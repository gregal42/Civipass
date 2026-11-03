<?php

declare(strict_types=1);

namespace App\Tests\Integration\Event;

use App\Event\Application\Command\CreateEventCommand;
use App\Event\Application\EventAccessPolicy;
use App\Event\Application\Service\EventCreationService;
use App\Event\Application\Support\EventAuditRecorder;
use App\Event\Application\Support\EventTransactions;
use App\Event\Domain\EventParticipationRepository;
use App\Event\Domain\EventRepository;
use App\Event\Domain\EventTypeRepository;
use App\Event\Domain\EventZoneRepository;
use App\Event\Domain\PublicReferenceGenerator;
use App\Event\Domain\Time\TerritoryTimezoneResolver;
use App\Identity\Domain\User;
use App\Municipality\Application\MunicipalityContext;
use App\Municipality\Domain\MunicipalityStatus;
use App\Shared\Application\AuditTrail;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\EventSchemaTestCase;
use App\Tests\Support\FailingAuditTrail;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\ParallelPhp;
use App\Tests\Support\ServiceWorld;
use App\Tests\Support\ThrowingAfterReferenceGenerator;
use App\Tests\Support\ThrowingZoneRepository;
use App\Tests\Support\UserBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Clock\ClockInterface;

#[Group('requires-event-schema')]
final class EventCreationServiceTest extends EventSchemaTestCase
{
    private ServiceWorld $w;

    protected function setUp(): void
    {
        parent::setUp();
        $this->w = ServiceWorld::persist($this->em, self::getContainer());
    }

    /** Utilisateur géré par l'EntityManager courant, comme l'acteur réel fourni par la couche de sécurité. */
    private function as(User $user): User
    {
        return $this->em->find(User::class, $user->id()) ?? self::fail('Utilisateur absent.');
    }

    private function creation(): EventCreationService
    {
        return $this->service(EventCreationService::class);
    }

    private static function command(?string $timezone = null, string $name = 'Incendie du massif forestier'): CreateEventCommand
    {
        return new CreateEventCommand('fire', $name, new \DateTimeImmutable('2026-12-01 08:00:00', new \DateTimeZone('UTC')), new \DateTimeImmutable('2026-12-10 18:00:00', new \DateTimeZone('UTC')), $timezone, 'Description libre', 'Consignes opérationnelles');
    }

    private function rows(string $table): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
    }

    private function counter(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT last_number FROM event_reference_counter WHERE id = 1');
    }

    private function assertNothingCreated(): void
    {
        self::assertSame(0, $this->rows('event_event'), 'événement');
        self::assertSame(0, $this->rows('event_participation'), 'participation');
        self::assertSame(0, $this->rows('event_zone'), 'zone');
        self::assertSame(0, $this->rows('audit_log'), 'audit');
        self::assertSame(0, $this->counter(), 'compteur de références');
    }

    // ── Nominal ──────────────────────────────────────────────────────────────

    public function testNominalCreation(): void
    {
        $w = $this->w;
        $result = $this->creation()->create($this->as($w->adminO), $w->org->id()->toRfc4122(), self::command());

        $year = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y');
        self::assertSame('EVT-'.$year.'-000001', $result->publicReference);
        self::assertSame('Europe/Paris', $result->timezone, 'fuseau proposé d\'après la commune organisatrice (Paris)');
        self::assertSame(1, $this->rows('event_event'));

        $event = $this->em->getConnection()->fetchAssociative('SELECT LOWER(HEX(id)) id, status, timezone, public_reference, LOWER(HEX(organizer_municipality_id)) org, LOWER(HEX(created_by)) created_by FROM event_event');
        self::assertSame('DRAFT', $event['status']);
        self::assertSame(str_replace('-', '', $result->eventId), $event['id']);
        self::assertSame(str_replace('-', '', $w->org->id()->toRfc4122()), $event['org']);
        self::assertSame(str_replace('-', '', $w->adminO->id()->toRfc4122()), $event['created_by']);

        $participation = $this->em->getConnection()->fetchAssociative('SELECT status, LOWER(HEX(id)) id, invited_by, activated_at FROM event_participation');
        self::assertSame('ACTIVE', $participation['status'], 'participation organisatrice ACTIVE');
        self::assertSame(str_replace('-', '', $result->participationId), $participation['id']);
        self::assertNull($participation['invited_by']);
        self::assertNotNull($participation['activated_at']);

        $zone = $this->em->getConnection()->fetchAssociative('SELECT type, status, confirmed_at, validity_mode, label, LOWER(HEX(id)) id FROM event_zone');
        self::assertSame('WHOLE_MUNICIPALITY', $zone['type']);
        self::assertNull($zone['confirmed_at'], 'proposée, jamais confirmée automatiquement (DM-058)');
        self::assertSame('UNCONFIGURED', $zone['validity_mode'], 'aucun créneau ni mode choisi automatiquement');
        self::assertSame('Commune entière — '.$w->org->name(), $zone['label']);
        self::assertSame(str_replace('-', '', $result->proposedZoneId), $zone['id']);
        self::assertSame(0, $this->rows('event_zone_validity'));
        self::assertSame(1, $this->counter());
    }

    public function testCreationWritesThreeAuditLinesInTheSameBatch(): void
    {
        $w = $this->w;
        $result = $this->creation()->create($this->as($w->managerO), $w->org->id()->toRfc4122(), self::command(name: 'Nom secret de l\'événement'));

        $rows = $this->em->getConnection()->fetchAllAssociative('SELECT action, subject_type, LOWER(HEX(subject_id)) subject_id, LOWER(HEX(municipality_id)) municipality, LOWER(HEX(actor_id)) actor, operator_id, LOWER(HEX(batch_id)) batch, origin, previous_value, new_value FROM audit_log ORDER BY action');

        self::assertSame(['EVENT_CREATED', 'PARTICIPATION_CREATED', 'ZONE_CREATED'], array_column($rows, 'action'));
        self::assertSame(['EVENT', 'EVENT_PARTICIPATION', 'EVENT_ZONE'], array_column($rows, 'subject_type'));
        self::assertCount(1, array_unique(array_column($rows, 'batch')), 'un seul lot');
        self::assertNotNull($rows[0]['batch']);
        foreach ($rows as $row) {
            self::assertSame(str_replace('-', '', $w->org->id()->toRfc4122()), $row['municipality']);
            self::assertSame(str_replace('-', '', $w->managerO->id()->toRfc4122()), $row['actor'], 'acteur métier');
            self::assertNull($row['operator_id']);
            self::assertSame('USER', $row['origin']);
            self::assertNull($row['previous_value']);
        }
        self::assertSame([str_replace('-', '', $result->eventId), str_replace('-', '', $result->participationId), str_replace('-', '', $result->proposedZoneId)], array_column($rows, 'subject_id'));
        $payload = implode('', array_column($rows, 'new_value'));
        self::assertStringContainsString($result->publicReference, $payload);
        self::assertStringContainsString('AUTO_PROPOSAL', $payload);
        foreach (['Nom secret', 'Description libre', 'Consignes opérationnelles', 'Commune entière'] as $freeText) {
            self::assertStringNotContainsString($freeText, $payload, 'aucun texte libre dans l\'audit');
        }
    }

    public function testManagerAndAdministratorMayBothCreate(): void
    {
        $w = $this->w;
        $service = $this->creation();

        $service->create($this->as($w->adminO), $w->org->id()->toRfc4122(), self::command());
        $service->create($this->as($w->managerO), $w->org->id()->toRfc4122(), self::command());

        self::assertSame(2, $this->rows('event_event'));
        self::assertSame(2, $this->counter());
    }

    // ── Fuseau horaire ───────────────────────────────────────────────────────

    public function testTheTimezoneIsResolvedFromTheOrganizerOrExplicitlyProvided(): void
    {
        $w = $this->w;

        self::assertSame('Europe/Paris', $this->creation()->create($this->as($w->adminO), $w->org->id()->toRfc4122(), self::command())->timezone);
        self::assertSame('Pacific/Tahiti', $this->creation()->create($this->as($w->adminO), $w->org->id()->toRfc4122(), self::command('Pacific/Tahiti'))->timezone, 'fuseau explicite : prioritaire');
    }

    public function testAnOverseasOrganizerGetsItsOwnTimezone(): void
    {
        $this->em->clear();
        $this->w = ServiceWorld::persist($this->em, self::getContainer(), '97411');
        $result = $this->creation()->create($this->as($this->w->adminO), $this->w->org->id()->toRfc4122(), self::command());

        self::assertSame('Indian/Reunion', $result->timezone);
        self::assertSame('Indian/Reunion', $this->em->getConnection()->fetchOne('SELECT timezone FROM event_event'));
    }

    public function testAnUnresolvableTimezoneMustBeProvidedAndIsNeverGuessed(): void
    {
        $this->em->clear();
        $this->w = ServiceWorld::persist($this->em, self::getContainer(), '98714'); // Polynésie française : trois fuseaux
        $w = $this->w;

        try {
            $this->creation()->create($this->as($w->adminO), $w->org->id()->toRfc4122(), self::command());
            self::fail('Le fuseau non déterminable doit être fourni.');
        } catch (DomainRuleViolation $e) {
            self::assertStringContainsString('indiquez-le explicitement', $e->getMessage());
        }
        self::assertSame(0, $this->rows('event_event'));
        self::assertSame(0, $this->counter(), 'aucune référence consommée');

        self::assertSame('Pacific/Marquesas', $this->creation()->create($this->as($w->adminO), $w->org->id()->toRfc4122(), self::command('Pacific/Marquesas'))->timezone);
    }

    public function testAnUnsupportedTimezoneIsRefused(): void
    {
        $w = $this->w;

        $this->expectException(DomainRuleViolation::class);
        $this->creation()->create($this->as($w->adminO), $w->org->id()->toRfc4122(), self::command('Europe/London'));
    }

    // ── Refus (rien n'est écrit) ─────────────────────────────────────────────

    public function testRefusalsWriteNothing(): void
    {
        $w = $this->w;
        $org = $w->org->id()->toRfc4122();
        $attempts = [
            'instructeur' => [fn () => $this->creation()->create($this->as($w->instructorO), $org, self::command()), OperationNotAllowed::class, 'rôle'],
            'commune étrangère sans droit' => [fn () => $this->creation()->create($this->as($w->adminX), $w->other->id()->toRfc4122(), self::command()), OperationNotAllowed::class, 'droit d\'utilisation'],
            'utilisateur d\'une autre commune, contexte forgé' => [fn () => $this->creation()->create($this->as($w->adminX), $org, self::command()), OperationNotAllowed::class, 'Commune inaccessible.'],
            'super-administrateur CWS' => [fn () => $this->creation()->create($this->as($w->superAdmin), $org, self::command()), OperationNotAllowed::class, 'Commune inaccessible.'],
            'identifiant invalide' => [fn () => $this->creation()->create($this->as($w->adminO), "1' OR 1=1 --", self::command()), OperationNotAllowed::class, 'Commune inaccessible.'],
            'type inconnu' => [fn () => $this->creation()->create($this->as($w->adminO), $org, new CreateEventCommand('inconnu', 'Nom', new \DateTimeImmutable('2026-12-01'))), DomainRuleViolation::class, 'Type'],
            'nom vide' => [fn () => $this->creation()->create($this->as($w->adminO), $org, self::command(name: '   ')), DomainRuleViolation::class, 'nom'],
            'fin avant début' => [fn () => $this->creation()->create($this->as($w->adminO), $org, new CreateEventCommand('fire', 'Nom', new \DateTimeImmutable('2026-12-10'), new \DateTimeImmutable('2026-12-01'), 'Europe/Paris')), DomainRuleViolation::class, 'fin'],
        ];

        foreach ($attempts as $label => [$attempt, $class, $fragment]) {
            try {
                $attempt();
                self::fail($label.' devait être refusé.');
            } catch (\Throwable $e) {
                self::assertInstanceOf($class, $e, $label);
                self::assertStringContainsStringIgnoringCase($fragment, $e->getMessage(), $label);
            }
        }
        $this->assertNothingCreated();
    }

    public function testAMunicipalityThatOnlyHasFreeAccessAsAnInvitedGuestCannotCreate(): void
    {
        // guestB a un administrateur, mais ni abonnement, ni TRIAL, ni accès commercial : participer gratuitement ne suffit pas.
        $w = $this->w;

        try {
            $this->creation()->create($this->as($w->adminB), $w->guestB->id()->toRfc4122(), self::command('Europe/Paris'));
            self::fail('Création refusée sans droit d\'utilisation.');
        } catch (OperationNotAllowed $e) {
            self::assertStringContainsString('droit d\'utilisation', $e->getMessage());
        }
        $this->assertNothingCreated();
    }

    public function testASuspendedOrInactiveMunicipalityCannotCreateDespiteItsCommercialAccess(): void
    {
        $w = $this->w;
        $org = $this->em->find($w->org::class, $w->org->id()) ?? self::fail('Commune absente.');
        $org->changeStatus(MunicipalityStatus::Suspended, new \DateTimeImmutable());
        $this->em->flush();

        try {
            $this->creation()->create($this->as($w->adminO), $w->org->id()->toRfc4122(), self::command());
            self::fail('Commune suspendue : refus attendu.');
        } catch (OperationNotAllowed $e) {
            self::assertStringContainsString('statut administratif', $e->getMessage());
        }

        $org->changeStatus(MunicipalityStatus::Inactive, new \DateTimeImmutable());
        $this->em->flush();
        try {
            $this->creation()->create($this->as($w->adminO), $w->org->id()->toRfc4122(), self::command());
            self::fail('Commune inactive : refus attendu.');
        } catch (OperationNotAllowed $e) {
            self::assertSame('Commune inaccessible.', $e->getMessage());
        }
        $this->assertNothingCreated();
    }

    public function testAnInactiveUserOrMembershipCannotCreate(): void
    {
        $w = $this->w;
        $ghost = UserBuilder::aUser()->build();
        $inactiveUser = UserBuilder::aUser()->inactive()->build();
        $this->save(
            $ghost,
            $inactiveUser,
            MembershipBuilder::aMembership($ghost, $this->em->getReference($w->org::class, $w->org->id()))->withRoles(\App\Municipality\Domain\MunicipalityRole::MunicipalityAdmin)->inactive()->build(),
            MembershipBuilder::aMembership($inactiveUser, $this->em->getReference($w->org::class, $w->org->id()))->withRoles(\App\Municipality\Domain\MunicipalityRole::MunicipalityAdmin)->build(),
        );

        foreach ([$ghost, $inactiveUser] as $user) {
            try {
                $this->creation()->create($user, $w->org->id()->toRfc4122(), self::command());
                self::fail('Refus attendu.');
            } catch (OperationNotAllowed $e) {
                self::assertSame('Commune inaccessible.', $e->getMessage());
            }
        }
        $this->assertNothingCreated();
    }

    // ── Rollback complet ─────────────────────────────────────────────────────

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function failures(): iterable
    {
        yield 'échec de l\'audit de l\'événement' => ['audit', 1];
        yield 'échec de l\'audit de la participation' => ['audit', 2];
        yield 'échec de l\'audit de la zone' => ['audit', 3];
        yield 'échec après réservation de la référence' => ['reference', 0];
        yield 'échec à l\'ajout de la zone' => ['zone', 0];
    }

    #[DataProvider('failures')]
    public function testAnyFailureRollsBackEverythingTogether(string $kind, int $call): void
    {
        $container = self::getContainer();
        $audit = $container->get(AuditTrail::class);
        $references = $container->get(PublicReferenceGenerator::class);
        $zones = $container->get(EventZoneRepository::class);
        $service = new EventCreationService(
            $container->get(EventTransactions::class),
            $container->get(ClockInterface::class),
            $container->get(MunicipalityContext::class),
            $container->get(EventAccessPolicy::class),
            $container->get(EventTypeRepository::class),
            $container->get(EventRepository::class),
            $container->get(EventParticipationRepository::class),
            'zone' === $kind ? new ThrowingZoneRepository($zones) : $zones,
            'reference' === $kind ? new ThrowingAfterReferenceGenerator($references) : $references,
            new TerritoryTimezoneResolver(),
            new EventAuditRecorder('audit' === $kind ? new FailingAuditTrail($audit, $call) : $audit),
        );

        try {
            $service->create($this->as($this->w->adminO), $this->w->org->id()->toRfc4122(), self::command());
            self::fail('Échec injecté attendu.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Échec injecté', $e->getMessage());
        }

        $this->assertNothingCreated();
    }

    // ── C1 : créations simultanées ───────────────────────────────────────────

    public function testC1ConcurrentCreationsGetDistinctContinuousReferences(): void
    {
        $w = $this->w;
        $arguments = [$w->adminO->id()->toRfc4122(), $w->org->id()->toRfc4122(), '5'];

        $results = ParallelPhp::run('create-events.php', [$arguments, $arguments, $arguments, $arguments]);

        $all = [];
        foreach ($results as $result) {
            self::assertSame(0, $result['status'], 'processus en échec : '.$result['stderr']);
            $all = [...$all, ...json_decode($result['stdout'], true, 512, \JSON_THROW_ON_ERROR)];
        }
        $year = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y');
        $expected = array_map(static fn (int $n): string => sprintf('EVT-%s-%06d', $year, $n), range(1, 20));
        sort($all);
        self::assertSame($expected, $all, 'références distinctes et continues');
        self::assertSame(20, $this->rows('event_event'));
        self::assertSame(20, $this->rows('event_participation'));
        self::assertSame(20, $this->rows('event_zone'));
        self::assertSame(60, $this->rows('audit_log'), 'trois lignes d\'audit par création');
        self::assertSame(20, $this->counter());
    }
}
