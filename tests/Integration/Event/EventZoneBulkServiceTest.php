<?php

declare(strict_types=1);

namespace App\Tests\Integration\Event;

use App\Event\Application\Command\BulkZoneCommand;
use App\Event\Application\Command\CreateZoneCommand;
use App\Event\Application\EventAccessPolicy;
use App\Event\Application\EventResourceResolver;
use App\Event\Application\Result\BulkZoneOperation;
use App\Event\Application\Service\EventZoneBulkService;
use App\Event\Application\Support\EventAuditRecorder;
use App\Event\Application\Support\EventLocker;
use App\Event\Application\Support\EventTransactions;
use App\Event\Application\Support\ZoneValidityConfigurator;
use App\Event\Domain\EventZoneValidityRepository;
use App\Identity\Domain\User;
use App\Shared\Application\AuditTrail;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\FailingAuditTrail;
use App\Tests\Support\ZoneServiceTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

#[Group('requires-event-schema')]
final class EventZoneBulkServiceTest extends ZoneServiceTestCase
{
    /** @var list<string> */
    private array $zones = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Trois zones confirmées de la commune organisatrice + la « Commune entière » proposée (4 zones au total).
        foreach (['Route A', 'Route B', 'Route C'] as $label) {
            $this->zones[] = $this->zoneService()->create($this->admin(), $this->created->participationId, new CreateZoneCommand($label, 'STREET'))->zoneId;
        }
    }

    private function admin(): User
    {
        return $this->as($this->w->adminO);
    }

    private function bulk(): EventZoneBulkService
    {
        return $this->service(EventZoneBulkService::class);
    }

    /**
     * @param list<string>|null $zones
     */
    private function suspendAll(?array $zones = null): BulkZoneCommand
    {
        return new BulkZoneCommand(BulkZoneOperation::Suspend, $zones ?? $this->zones);
    }

    /**
     * @param list<string>|null $zones
     */
    private function applyDuration(?array $zones = null, bool $confirm = false): BulkZoneCommand
    {
        return new BulkZoneCommand(BulkZoneOperation::ApplyValidity, $zones ?? $this->zones, $this->eventDuration(), $confirm);
    }

    /**
     * @return array<string, string>
     */
    private function statuses(): array
    {
        return $this->em->getConnection()->fetchAllKeyValue('SELECT label, status FROM event_zone ORDER BY label');
    }

    // ── Nominal ──────────────────────────────────────────────────────────────

    public function testSuspendThenReactivateSeveralZonesInOneBatch(): void
    {
        $result = $this->bulk()->apply($this->admin(), $this->created->participationId, $this->suspendAll());

        self::assertTrue($result->changed);
        self::assertSame(BulkZoneOperation::Suspend, $result->operation);
        self::assertCount(3, $result->modifiedZoneIds);
        self::assertNotNull($result->batchId);
        self::assertSame('SUSPENDED', $this->statuses()['Route A']);
        self::assertStringStartsWith('Commune entière', (string) array_key_first(array_filter($this->statuses(), static fn (string $s): bool => 'ACTIVE' === $s)), 'les zones hors sélection ne bougent pas');
        $batch = $this->hex((string) $result->batchId);
        self::assertSame(3, $this->rows("SELECT COUNT(*) FROM audit_log WHERE action = 'ZONE_SUSPENDED' AND batch_id = UNHEX('".$batch."')"));
        self::assertSame(1, $this->rows("SELECT COUNT(*) FROM audit_log WHERE action = 'ZONE_BULK_OPERATION' AND batch_id = UNHEX('".$batch."')"));
        self::assertSame(4, $this->rows("SELECT COUNT(*) FROM audit_log WHERE batch_id = UNHEX('".$batch."')"));

        $back = $this->bulk()->apply($this->admin(), $this->created->participationId, new BulkZoneCommand(BulkZoneOperation::Reactivate, $this->zones));
        self::assertCount(3, $back->modifiedZoneIds);
        self::assertNotSame($result->batchId, $back->batchId, 'un batch par opération');
        self::assertSame(['ACTIVE'], array_values(array_unique($this->statuses())));
    }

    public function testApplyingAValidityGivesEachZoneItsOwnIndependentSlots(): void
    {
        $slots = new \App\Event\Application\Command\ZoneValidityCommand('SPECIFIC_PERIODS', [$this->punctual('2026-12-02')]);

        $result = $this->bulk()->apply($this->admin(), $this->created->participationId, new BulkZoneCommand(BulkZoneOperation::ApplyValidity, $this->zones, $slots));

        self::assertCount(3, $result->modifiedZoneIds);
        self::assertSame(3, $this->rows('SELECT COUNT(*) FROM event_zone_validity WHERE deleted_at IS NULL'), 'une copie par zone (DM-026)');
        self::assertSame(3, $this->rows('SELECT COUNT(DISTINCT zone_id) FROM event_zone_validity'));
        $batch = $this->hex((string) $result->batchId);
        self::assertSame(3, $this->rows("SELECT COUNT(*) FROM audit_log WHERE action = 'ZONE_VALIDITY_SLOT_CREATED' AND batch_id = UNHEX('".$batch."')"));
        self::assertSame(3, $this->rows("SELECT COUNT(*) FROM audit_log WHERE action = 'ZONE_VALIDITY_MODE_CHANGED' AND batch_id = UNHEX('".$batch."')"));

        // Aucun héritage permanent (DM-027) : modifier une zone ne touche pas les autres.
        $this->zoneService()->configureValidity($this->admin(), $this->zones[0], $this->eventDuration());
        self::assertSame(2, $this->rows('SELECT COUNT(*) FROM event_zone_validity WHERE deleted_at IS NULL'));
    }

    // ── Écrasement (DM-025) ──────────────────────────────────────────────────

    public function testOverwritingAnExistingDifferentConfigurationRequiresConfirmation(): void
    {
        $this->zoneService()->configureValidity($this->admin(), $this->zones[1], $this->periods($this->punctual('2026-12-09')));
        $audits = $this->rows('SELECT COUNT(*) FROM audit_log');
        $command = new BulkZoneCommand(BulkZoneOperation::ApplyValidity, $this->zones, $this->eventDuration());

        $this->expectViolation(fn () => $this->bulk()->apply($this->admin(), $this->created->participationId, $command), 'écrasement sans confirmation');
        self::assertSame($audits, $this->rows('SELECT COUNT(*) FROM audit_log'));
        self::assertSame('UNCONFIGURED', $this->zoneColumn($this->zones[0], 'validity_mode'), 'aucune zone n\'est modifiée, même celles sans configuration');

        $confirmed = $this->bulk()->apply($this->admin(), $this->created->participationId, new BulkZoneCommand(BulkZoneOperation::ApplyValidity, $this->zones, $this->eventDuration(), true));
        self::assertCount(3, $confirmed->modifiedZoneIds);
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM event_zone_validity WHERE deleted_at IS NULL'));
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM event_zone_validity'), 'l\'ancien créneau est conservé (supprimé logiquement)');
        self::assertSame(1, $this->auditCount('ZONE_VALIDITY_SLOT_DELETED'), 'l\'état précédent est conservé dans l\'audit');
    }

    public function testIdenticalConfigurationIsNeitherAnOverwriteNorAModification(): void
    {
        $this->bulk()->apply($this->admin(), $this->created->participationId, $this->applyDuration());
        $audits = $this->rows('SELECT COUNT(*) FROM audit_log');
        $updated = $this->em->getConnection()->fetchAllKeyValue('SELECT label, updated_at FROM event_zone ORDER BY label');

        $result = $this->bulk()->apply($this->admin(), $this->created->participationId, $this->applyDuration());

        self::assertFalse($result->changed);
        self::assertSame([], $result->modifiedZoneIds);
        self::assertNull($result->batchId);
        self::assertSame($audits, $this->rows('SELECT COUNT(*) FROM audit_log'), 'aucun audit artificiel, pas même ZONE_BULK_OPERATION');
        self::assertSame($updated, $this->em->getConnection()->fetchAllKeyValue('SELECT label, updated_at FROM event_zone ORDER BY label'));
    }

    public function testOnlyTheZonesThatActuallyChangeAreModifiedAndAudited(): void
    {
        $this->zoneService()->configureValidity($this->admin(), $this->zones[0], $this->eventDuration());
        $audits = $this->rows('SELECT COUNT(*) FROM audit_log');

        $result = $this->bulk()->apply($this->admin(), $this->created->participationId, $this->applyDuration());

        self::assertCount(2, $result->modifiedZoneIds, 'la zone déjà identique n\'est pas touchée');
        self::assertNotContains($this->zones[0], $result->modifiedZoneIds);
        self::assertSame($audits + 2 + 1, $this->rows('SELECT COUNT(*) FROM audit_log'), 'un changement de mode par zone modifiée + la ligne de lot');
    }

    // ── Sélection invalide : refus en bloc ───────────────────────────────────

    public function testAForeignZoneInTheSelectionRefusesTheWholeOperationUniformly(): void
    {
        $audits = $this->rows('SELECT COUNT(*) FROM audit_log');
        foreach ([$this->proposedZoneB, Uuid::v7()->toRfc4122(), $this->created->eventId, $this->created->participationId] as $foreign) {
            try {
                $this->bulk()->apply($this->admin(), $this->created->participationId, $this->suspendAll([...$this->zones, $foreign]));
                self::fail('Zone étrangère acceptée.');
            } catch (OperationNotAllowed $e) {
                self::assertSame('Ressource inaccessible.', $e->getMessage());
            }
        }

        self::assertSame(['ACTIVE'], array_values(array_unique($this->statuses())));
        self::assertSame($audits, $this->rows('SELECT COUNT(*) FROM audit_log'));
    }

    public function testInvalidSelections(): void
    {
        $this->zoneService()->delete($this->admin(), $this->zones[2]);
        $audits = $this->rows('SELECT COUNT(*) FROM audit_log');

        $this->expectRefused(fn () => $this->bulk()->apply($this->admin(), $this->created->participationId, $this->suspendAll()), 'zone supprimée dans la sélection');
        $this->expectRefused(fn () => $this->bulk()->apply($this->admin(), $this->created->participationId, $this->suspendAll([$this->zones[0], $this->zones[0]])), 'doublon');
        $this->expectRefused(fn () => $this->bulk()->apply($this->admin(), $this->created->participationId, $this->suspendAll([])), 'sélection vide');
        $this->expectRefused(fn () => $this->bulk()->apply($this->admin(), $this->created->participationId, $this->suspendAll(['not-a-uuid'])), 'identifiant invalide');
        $this->expectRefused(fn () => $this->bulk()->apply($this->admin(), $this->created->participationId, new BulkZoneCommand(BulkZoneOperation::ApplyValidity, [$this->zones[0]])), 'configuration absente');
        $this->expectRefused(fn () => $this->bulk()->apply($this->admin(), $this->created->participationId, new BulkZoneCommand(BulkZoneOperation::Suspend, [$this->zones[0]], $this->eventDuration())), 'configuration inattendue');

        self::assertSame($audits, $this->rows('SELECT COUNT(*) FROM audit_log'));
        self::assertSame('ACTIVE', $this->zoneColumn($this->zones[0], 'status'));
    }

    public function testAForbiddenTransitionOnOneZoneRefusesTheWholeOperation(): void
    {
        $this->zoneService()->suspend($this->admin(), $this->zones[1]);
        $audits = $this->rows('SELECT COUNT(*) FROM audit_log');

        $this->expectRefused(fn () => $this->bulk()->apply($this->admin(), $this->created->participationId, $this->suspendAll()), 'une zone déjà suspendue');
        $this->expectRefused(fn () => $this->bulk()->apply($this->admin(), $this->created->participationId, new BulkZoneCommand(BulkZoneOperation::Reactivate, $this->zones)), 'zones actives à réactiver');

        self::assertSame('ACTIVE', $this->zoneColumn($this->zones[0], 'status'), 'aucune suspension partielle');
        self::assertSame($audits, $this->rows('SELECT COUNT(*) FROM audit_log'));
    }

    public function testAnInvalidSlotRefusesTheWholeOperationAndWritesNothing(): void
    {
        $audits = $this->rows('SELECT COUNT(*) FROM audit_log');
        $command = new BulkZoneCommand(BulkZoneOperation::ApplyValidity, $this->zones, $this->periods($this->punctual('2027-03-28', '02:30', '04:00')));

        $this->expectRefused(fn () => $this->bulk()->apply($this->admin(), $this->created->participationId, $command), 'heure inexistante');

        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM event_zone_validity'));
        self::assertSame($audits, $this->rows('SELECT COUNT(*) FROM audit_log'));
    }

    // ── Aucun contournement des politiques d'accès ───────────────────────────

    public function testNoRoleGainsRightsThroughTheBulkService(): void
    {
        $w = $this->w;
        $actors = ['gestionnaire' => $w->managerO, 'instructeur' => $w->instructorO, 'commune participante B' => $w->adminB, 'commune étrangère' => $w->adminX, 'super-admin CWS' => $w->superAdmin];
        $audits = $this->rows('SELECT COUNT(*) FROM audit_log');

        foreach ($actors as $name => $user) {
            foreach ([$this->suspendAll(), $this->applyDuration(null, true), new BulkZoneCommand(BulkZoneOperation::Reactivate, $this->zones)] as $command) {
                try {
                    $this->bulk()->apply($this->as($user), $this->created->participationId, $command);
                    self::fail($name.' a pu agir en groupé.');
                } catch (OperationNotAllowed) {
                    self::addToAssertionCount(1);
                }
            }
        }
        // L'organisatrice ne peut pas non plus viser la participation de B.
        $this->expectRefused(fn () => $this->bulk()->apply($this->admin(), $this->participationB, new BulkZoneCommand(BulkZoneOperation::Suspend, [$this->proposedZoneB])), 'organisatrice sur B');

        self::assertSame($audits, $this->rows('SELECT COUNT(*) FROM audit_log'));
        self::assertSame(['ACTIVE'], array_values(array_unique($this->statuses())));
    }

    public function testAZoneOfAnotherParticipationOfTheSameEventCannotBeSmuggledIn(): void
    {
        // adminB agit sur SA participation avec une zone de l'organisatrice : refus en bloc.
        $own = $this->zoneService()->create($this->as($this->w->adminB), $this->participationB, new CreateZoneCommand('Zone B', 'DISTRICT'))->zoneId;

        $this->expectRefused(fn () => $this->bulk()->apply($this->as($this->w->adminB), $this->participationB, new BulkZoneCommand(BulkZoneOperation::Suspend, [$own, $this->zones[0]])), 'zone de l\'organisatrice');

        self::assertSame('ACTIVE', $this->zoneColumn($own, 'status'));
        self::assertSame('ACTIVE', $this->zoneColumn($this->zones[0], 'status'));
    }

    public function testANonSubscribedParticipatingMunicipalityCanOperateInBulkOnItsOwnZones(): void
    {
        $own = $this->zoneService()->create($this->as($this->w->adminB), $this->participationB, new CreateZoneCommand('Zone B', 'DISTRICT'))->zoneId;

        $result = $this->bulk()->apply($this->as($this->w->adminB), $this->participationB, new BulkZoneCommand(BulkZoneOperation::Suspend, [$own, $this->proposedZoneB]));

        self::assertCount(2, $result->modifiedZoneIds);
        self::assertSame($this->w->guestB->id()->toBinary(), $this->em->getConnection()->fetchOne("SELECT municipality_id FROM audit_log WHERE action = 'ZONE_BULK_OPERATION'"));
    }

    public function testBulkOperationsAreRefusedOnAClosedEvent(): void
    {
        $this->lifecycle()->close($this->admin(), $this->created->eventId);

        $this->expectRefused(fn () => $this->bulk()->apply($this->admin(), $this->created->participationId, $this->suspendAll()), 'événement clôturé');
        self::assertSame(0, $this->auditCount('ZONE_BULK_OPERATION'));
    }

    // ── Plafond de zones (DM-096) ────────────────────────────────────────────

    public function testExactlyTheZoneLimitIsAccepted(): void
    {
        $zones = $this->zones;
        for ($i = count($zones); $i < 100; ++$i) {
            $zones[] = $this->zoneService()->create($this->admin(), $this->created->participationId, new CreateZoneCommand('Zone '.$i, 'STREET'))->zoneId;
        }

        $result = $this->bulk()->apply($this->admin(), $this->created->participationId, $this->suspendAll($zones));

        self::assertCount(100, $result->modifiedZoneIds);
        self::assertSame(100, $this->auditCount('ZONE_SUSPENDED'));
    }

    public function testBeyondTheZoneLimitIsRefusedBeforeAnyLookupOrWrite(): void
    {
        $audits = $this->rows('SELECT COUNT(*) FROM audit_log');
        $tooMany = array_map(static fn (): string => Uuid::v7()->toRfc4122(), range(1, 101));

        try {
            $this->bulk()->apply($this->admin(), $this->created->participationId, $this->suspendAll($tooMany));
            self::fail('Dépassement accepté.');
        } catch (DomainRuleViolation $e) {
            self::assertStringContainsString('100', $e->getMessage());
        }
        // À la limite (100 identifiants inconnus) : le plafond ne s'applique pas, c'est le refus uniforme qui joue.
        $this->expectRefused(fn () => $this->bulk()->apply($this->admin(), $this->created->participationId, $this->suspendAll(array_slice($tooMany, 0, 100))), '100 identifiants inconnus');

        self::assertSame($audits, $this->rows('SELECT COUNT(*) FROM audit_log'));
        self::assertSame(['ACTIVE'], array_values(array_unique($this->statuses())));
    }

    public function testTheZoneLimitIsConfigurableAndWiredFromTheContainer(): void
    {
        self::assertSame(100, (new \ReflectionProperty(EventZoneBulkService::class, 'maxZones'))->getValue($this->bulk()));

        $c = self::getContainer();
        $small = new EventZoneBulkService(
            $c->get(EventTransactions::class),
            $c->get(ClockInterface::class),
            $c->get(EventResourceResolver::class),
            $c->get(EventAccessPolicy::class),
            $c->get(EventLocker::class),
            $c->get(ZoneValidityConfigurator::class),
            $c->get(EventAuditRecorder::class),
            maxZones: 2,
        );
        $this->expectViolation(fn () => $small->apply($this->admin(), $this->created->participationId, $this->suspendAll()), 'plafond abaissé à 2 (3 zones)');
        self::assertCount(2, $small->apply($this->admin(), $this->created->participationId, $this->suspendAll(array_slice($this->zones, 0, 2)))->modifiedZoneIds);
    }

    // ── Rollback ─────────────────────────────────────────────────────────────

    private function failingBulk(int $failOnCall): EventZoneBulkService
    {
        $c = self::getContainer();
        $audit = new EventAuditRecorder(new FailingAuditTrail($c->get(AuditTrail::class), $failOnCall));

        return new EventZoneBulkService(
            $c->get(EventTransactions::class),
            $c->get(ClockInterface::class),
            $c->get(EventResourceResolver::class),
            $c->get(EventAccessPolicy::class),
            $c->get(EventLocker::class),
            new ZoneValidityConfigurator($c->get(EventZoneValidityRepository::class), $audit),
            $audit,
        );
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function failures(): iterable
    {
        yield 'suspension : échec au 1er audit' => ['suspend', 1];
        yield 'suspension : échec au 3e audit (modifications déjà faites)' => ['suspend', 3];
        yield 'suspension : échec sur la ligne de lot' => ['suspend', 4];
        yield 'validité : échec au 2e audit' => ['validity', 2];
        yield 'validité : échec au 5e audit' => ['validity', 5];
        yield 'validité : échec sur la ligne de lot' => ['validity', 7];
    }

    #[DataProvider('failures')]
    public function testAnAuditFailureRollsBackEveryZone(string $operation, int $failOnCall): void
    {
        $snapshot = fn (): array => [
            $this->rows('SELECT COUNT(*) FROM audit_log'),
            $this->rows('SELECT COUNT(*) FROM event_zone_validity'),
            $this->em->getConnection()->fetchAllAssociative('SELECT LOWER(HEX(id)) id, status, validity_mode, updated_at FROM event_zone ORDER BY id'),
        ];
        $before = $snapshot();
        $command = 'suspend' === $operation ? $this->suspendAll() : new BulkZoneCommand(BulkZoneOperation::ApplyValidity, $this->zones, $this->periods($this->punctual()));

        try {
            $this->failingBulk($failOnCall)->apply($this->admin(), $this->created->participationId, $command);
            self::fail('Échec injecté attendu.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Échec injecté', $e->getMessage());
        }

        self::assertSame($before, $snapshot(), 'aucune zone ni aucun audit ne subsiste');
    }

    private function expectViolation(\Closure $attempt, string $label): void
    {
        try {
            $attempt();
            self::fail('Refus métier attendu : '.$label);
        } catch (DomainRuleViolation|OperationNotAllowed) {
            self::addToAssertionCount(1);
        }
    }

    private function expectRefused(\Closure $attempt, string $label): void
    {
        $this->expectViolation($attempt, $label);
    }
}
