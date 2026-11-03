<?php

declare(strict_types=1);

namespace App\Tests\Integration\Event;

use App\Event\Application\Command\BulkZoneCommand;
use App\Event\Application\Command\CreateZoneCommand;
use App\Event\Application\Command\UpdateEventCommand;
use App\Event\Application\Command\UpdateZoneCommand;
use App\Event\Application\Result\BulkZoneOperation;
use App\Event\Application\Service\EventZoneBulkService;
use App\Identity\Domain\User;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\ZoneServiceTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Consolidation TECH-02.1 : parcours métier de bout en bout (A à E) à travers TOUS les services, sur MySQL de test,
 * et matrice des permissions par statut de commune.
 */
#[Group('requires-event-schema')]
final class EventModuleJourneysTest extends ZoneServiceTestCase
{
    private function admin(): User
    {
        return $this->as($this->w->adminO);
    }

    private function adminB(): User
    {
        return $this->as($this->w->adminB);
    }

    private function bulk(): EventZoneBulkService
    {
        return $this->service(EventZoneBulkService::class);
    }

    /**
     * @return list<string> actions d'audit dans l'ordre d'écriture (UUID v7 croissants)
     */
    private function trail(): array
    {
        return array_map('strval', $this->em->getConnection()->fetchFirstColumn('SELECT action FROM audit_log ORDER BY id'));
    }

    /**
     * @return list<mixed>
     */
    private function participationStatuses(): array
    {
        return $this->em->getConnection()->fetchFirstColumn('SELECT status FROM event_participation');
    }

    // ── Parcours A — événement simple ────────────────────────────────────────

    public function testJourneyASimpleEvent(): void
    {
        $zone = $this->created->proposedZoneId;
        self::assertSame('DRAFT', $this->eventStatus());

        $this->zoneService()->configureValidity($this->admin(), $zone, $this->periods($this->punctual('2026-12-02'), $this->punctual('2026-12-03')));
        $this->expectRefused(fn () => $this->lifecycle()->activate($this->admin(), $this->created->eventId), 'zone proposée non confirmée');
        $this->zoneService()->confirm($this->admin(), $zone);
        $this->lifecycle()->activate($this->admin(), $this->created->eventId);
        self::assertSame('ACTIVE', $this->eventStatus());
        $result = $this->lifecycle()->close($this->admin(), $this->created->eventId);

        self::assertSame('CLOSED', $this->eventStatus());
        self::assertCount(2, $result->closedParticipations, 'organisatrice et B (acceptée en préparation)');
        self::assertSame(['CLOSED'], array_values(array_unique($this->participationStatuses())));
        self::assertNull($this->zoneColumn($zone, 'deleted_at'), 'les zones sont conservées après la clôture');
        $trail = $this->trail();
        self::assertSame(['ZONE_VALIDITY_MODE_CHANGED', 'ZONE_VALIDITY_SLOT_CREATED', 'ZONE_VALIDITY_SLOT_CREATED', 'ZONE_CONFIRMED', 'EVENT_ACTIVATED'], \array_slice($trail, \count($trail) - 8, 5));
        self::assertSame('EVENT_CLOSED', $trail[\count($trail) - 3] ?? null, 'clôture : événement puis participations');
    }

    // ── Parcours B — événement intercommunal ─────────────────────────────────

    public function testJourneyBInterMunicipalEvent(): void
    {
        $zoneO = $this->created->proposedZoneId;
        $this->zoneService()->confirm($this->admin(), $zoneO);
        $this->zoneService()->configureValidity($this->admin(), $zoneO, $this->eventDuration());
        // B (sans abonnement) prépare ses zones avant l'activation.
        $zoneB = $this->zoneService()->create($this->adminB(), $this->participationB, new CreateZoneCommand('Quartier nord', 'DISTRICT'))->zoneId;
        $this->zoneService()->configureValidity($this->adminB(), $zoneB, $this->periods($this->punctual()));
        $this->zoneService()->confirm($this->adminB(), $this->proposedZoneB);
        $this->lifecycle()->activate($this->admin(), $this->created->eventId);

        // Chaque commune ne gère que les siennes.
        $this->expectRefused(fn () => $this->zoneService()->suspend($this->admin(), $zoneB), 'organisatrice sur la zone de B');
        $this->expectRefused(fn () => $this->zoneService()->suspend($this->adminB(), $zoneO), 'B sur la zone de l\'organisatrice');
        $this->zoneService()->suspend($this->adminB(), $zoneB);
        self::assertSame('ACTIVE', $this->zoneColumn($zoneO, 'status'));

        $closure = $this->lifecycle()->close($this->admin(), $this->created->eventId);

        self::assertCount(2, $closure->closedParticipations);
        self::assertSame(['CLOSED'], array_values(array_unique($this->participationStatuses())));
        // Après la clôture globale, plus aucune opération de zone, même pour B.
        $this->expectRefused(fn () => $this->zoneService()->reactivate($this->adminB(), $zoneB), 'zone de B après clôture');
        $this->expectRefused(fn () => $this->bulk()->apply($this->adminB(), $this->participationB, new BulkZoneCommand(BulkZoneOperation::Reactivate, [$zoneB])), 'lot de B après clôture');
        self::assertSame('SUSPENDED', $this->zoneColumn($zoneB, 'status'));
    }

    // ── Parcours C — événement suspendu ──────────────────────────────────────

    public function testJourneyCSuspendedEvent(): void
    {
        $this->activateEvent();
        $this->lifecycle()->suspend($this->admin(), $this->created->eventId, 'Alerte météo');
        self::assertSame('SUSPENDED', $this->eventStatus());
        // Les zones restent administrables pendant la suspension ; rien n'est modifié automatiquement.
        $this->zoneService()->update($this->admin(), $this->created->proposedZoneId, new UpdateZoneCommand('Commune — mise à jour'));
        self::assertSame('ACTIVE', $this->zoneColumn($this->created->proposedZoneId, 'status'));
        self::assertSame(['ACTIVE'], array_values(array_unique($this->participationStatuses())), 'participations non suspendues automatiquement');

        $this->lifecycle()->reactivate($this->admin(), $this->created->eventId);
        self::assertSame('ACTIVE', $this->eventStatus());
        self::assertSame('Alerte météo', $this->em->getConnection()->fetchOne('SELECT suspension_reason FROM event_event'), 'DM-081 : dernière suspension conservée');
        $this->lifecycle()->suspend($this->admin(), $this->created->eventId, 'Seconde alerte');
        $this->lifecycle()->close($this->admin(), $this->created->eventId);

        self::assertSame('CLOSED', $this->eventStatus());
        $this->expectRefused(fn () => $this->lifecycle()->reactivate($this->admin(), $this->created->eventId), 'réactivation après clôture');
    }

    // ── Parcours D — modification en cours d'événement ───────────────────────

    public function testJourneyDChangesDuringAnActiveEvent(): void
    {
        $this->activateEvent();
        $zone = $this->zoneService()->create($this->admin(), $this->created->participationId, new CreateZoneCommand('Route barrée', 'STREET'))->zoneId;

        $this->zoneService()->configureValidity($this->admin(), $zone, $this->periods($this->punctual('2026-12-02')));
        $this->zoneService()->configureValidity($this->admin(), $zone, $this->periods($this->punctual('2026-12-04', '08:00', '12:00')));
        $this->zoneService()->suspend($this->admin(), $zone);
        $this->zoneService()->reactivate($this->admin(), $zone);
        $this->lifecycle()->update($this->admin(), $this->created->eventId, new UpdateEventCommand(name: 'Incendie — mise à jour'));

        self::assertSame('ACTIVE', $this->eventStatus());
        self::assertSame('ACTIVE', $this->zoneColumn($zone, 'status'));
        self::assertSame(2, $this->rows('SELECT COUNT(*) FROM event_zone_validity'), 'historique des deux configurations');
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM event_zone_validity WHERE deleted_at IS NULL'));
        // L'événement reste activable-et-actif tant qu'une zone prête existe, même si l'on suspend l'autre zone.
        $this->zoneService()->suspend($this->admin(), $zone);
        self::assertSame('ACTIVE', $this->eventStatus(), 'la suspension d\'une zone ne modifie pas l\'événement');
    }

    // ── Parcours E — opérations groupées ─────────────────────────────────────

    public function testJourneyEBulkOperationsKeepTheAuditConsistent(): void
    {
        $this->activateEvent();
        $zones = [];
        foreach (['Route A', 'Route B', 'Route C', 'Route D'] as $label) {
            $zones[] = $this->zoneService()->create($this->admin(), $this->created->participationId, new CreateZoneCommand($label, 'STREET'))->zoneId;
        }
        $before = $this->rows('SELECT COUNT(*) FROM audit_log');

        $configure = $this->bulk()->apply($this->admin(), $this->created->participationId, new BulkZoneCommand(BulkZoneOperation::ApplyValidity, $zones, $this->periods($this->punctual('2026-12-02'))));
        $suspend = $this->bulk()->apply($this->admin(), $this->created->participationId, new BulkZoneCommand(BulkZoneOperation::Suspend, \array_slice($zones, 0, 3)));
        $reactivate = $this->bulk()->apply($this->admin(), $this->created->participationId, new BulkZoneCommand(BulkZoneOperation::Reactivate, \array_slice($zones, 0, 2)));

        self::assertCount(4, $configure->modifiedZoneIds);
        self::assertCount(3, $suspend->modifiedZoneIds);
        self::assertCount(2, $reactivate->modifiedZoneIds);
        // Cohérence : (4 modes + 4 créneaux + 1 lot) + (3 + 1) + (2 + 1) lignes, regroupées par trois batch_id distincts.
        self::assertSame($before + 9 + 4 + 3, $this->rows('SELECT COUNT(*) FROM audit_log'));
        self::assertSame(3, $this->rows('SELECT COUNT(DISTINCT batch_id) FROM audit_log WHERE batch_id IS NOT NULL AND id > (SELECT id FROM audit_log WHERE action = \'EVENT_ACTIVATED\')'));
        self::assertSame(['ACTIVE', 'ACTIVE', 'SUSPENDED', 'ACTIVE'], array_map(fn (string $z): string => (string) $this->zoneColumn($z, 'status'), $zones));
        self::assertSame(4, $this->rows('SELECT COUNT(*) FROM event_zone_validity WHERE deleted_at IS NULL'));
        self::assertSame('ACTIVE', $this->eventStatus());
    }

    // ── Permissions par statut de commune ────────────────────────────────────

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonWritableMunicipalityStatuses(): iterable
    {
        yield 'commune suspendue' => ['SUSPENDED'];
        yield 'commune inactive' => ['INACTIVE'];
    }

    #[DataProvider('nonWritableMunicipalityStatuses')]
    public function testASuspendedOrInactiveMunicipalityCannotWriteAnythingNorUseTheBulkService(string $status): void
    {
        $zoneB = $this->zoneService()->create($this->adminB(), $this->participationB, new CreateZoneCommand('Quartier nord', 'DISTRICT'))->zoneId;
        $this->em->getConnection()->executeStatement('UPDATE municipality SET status = ? WHERE id = ?', [$status, $this->w->guestB->id()->toBinary()]);
        $this->em->clear();
        $audits = $this->rows('SELECT COUNT(*) FROM audit_log');

        $attempts = [
            'create' => fn () => $this->zoneService()->create($this->adminB(), $this->participationB, new CreateZoneCommand('X', 'STREET')),
            'update' => fn () => $this->zoneService()->update($this->adminB(), $zoneB, new UpdateZoneCommand('X')),
            'confirm' => fn () => $this->zoneService()->confirm($this->adminB(), $this->proposedZoneB),
            'suspend' => fn () => $this->zoneService()->suspend($this->adminB(), $zoneB),
            'delete' => fn () => $this->zoneService()->delete($this->adminB(), $zoneB),
            'validity' => fn () => $this->zoneService()->configureValidity($this->adminB(), $zoneB, $this->eventDuration()),
            'bulk' => fn () => $this->bulk()->apply($this->adminB(), $this->participationB, new BulkZoneCommand(BulkZoneOperation::Suspend, [$zoneB])),
            'participation.close' => fn () => $this->participations()->close($this->adminB(), $this->participationB),
        ];
        foreach ($attempts as $name => $attempt) {
            $this->expectRefused($attempt, $name.' ('.$status.')');
        }

        self::assertSame($audits, $this->rows('SELECT COUNT(*) FROM audit_log'));
        self::assertSame('ACTIVE', $this->zoneColumn($zoneB, 'status'));
    }

    public function testADeactivatedAdministratorAccountCannotWrite(): void
    {
        $this->em->getConnection()->executeStatement('UPDATE identity_user SET active = 0 WHERE id = ?', [$this->w->adminO->id()->toBinary()]);
        $this->em->clear();

        $this->expectRefused(fn () => $this->zoneService()->confirm($this->admin(), $this->created->proposedZoneId), 'compte désactivé');
        $this->expectRefused(fn () => $this->lifecycle()->close($this->admin(), $this->created->eventId), 'compte désactivé');
        self::assertNull($this->zoneColumn($this->created->proposedZoneId, 'confirmed_at'));
    }

    public function testInvalidEventUpdateDoesNotLeakThroughZones(): void
    {
        $this->expectRefused(fn () => $this->lifecycle()->update($this->admin(), $this->created->eventId, new UpdateEventCommand(name: '')), 'nom vide');
        self::assertSame('Incendie du massif forestier', $this->em->getConnection()->fetchOne('SELECT name FROM event_event'));
    }

    private function expectRefused(\Closure $attempt, string $label): void
    {
        try {
            $attempt();
            self::fail('Refus attendu : '.$label);
        } catch (DomainRuleViolation|OperationNotAllowed) {
            self::addToAssertionCount(1);
        }
    }
}
