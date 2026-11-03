<?php

declare(strict_types=1);

namespace App\Tests\Integration\Event;

use App\Event\Application\Command\CreateEventCommand;
use App\Event\Application\Command\InviteMunicipalityCommand;
use App\Event\Application\EventAccessPolicy;
use App\Event\Application\EventResourceResolver;
use App\Event\Application\Result\CreatedEvent;
use App\Event\Application\Service\EventCreationService;
use App\Event\Application\Service\EventParticipationService;
use App\Event\Application\Support\EventAuditRecorder;
use App\Event\Application\Support\EventLocker;
use App\Event\Application\Support\EventTransactions;
use App\Event\Domain\Event;
use App\Event\Domain\EventParticipation;
use App\Event\Domain\EventParticipationRepository;
use App\Event\Domain\EventZoneRepository;
use App\Event\Domain\ParticipationStatus;
use App\Identity\Domain\User;
use App\Municipality\Application\EventCreationPolicy;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRepository;
use App\Municipality\Domain\MunicipalityStatus;
use App\Shared\Application\AuditTrail;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\EventSchemaTestCase;
use App\Tests\Support\FailingAuditTrail;
use App\Tests\Support\ParallelPhp;
use App\Tests\Support\ServiceWorld;
use PHPUnit\Framework\Attributes\Group;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

#[Group('requires-event-schema')]
final class EventInvitationServiceTest extends EventSchemaTestCase
{
    private ServiceWorld $w;
    private CreatedEvent $created;

    protected function setUp(): void
    {
        parent::setUp();
        $this->w = ServiceWorld::persist($this->em, self::getContainer());
        $this->created = $this->service(EventCreationService::class)->create(
            $this->as($this->w->adminO),
            $this->w->org->id()->toRfc4122(),
            new CreateEventCommand('fire', 'Incendie du massif forestier', new \DateTimeImmutable('2026-12-01 08:00:00', new \DateTimeZone('UTC'))),
        );
    }

    private function as(User $user): User
    {
        return $this->em->find(User::class, $user->id()) ?? self::fail('Utilisateur absent.');
    }

    private function participation(): EventParticipationService
    {
        return $this->service(EventParticipationService::class);
    }

    private function invite(User $actor, Municipality $target, ?string $message = null): \App\Event\Application\Result\InvitationResult
    {
        return $this->participation()->invite($this->as($actor), new InviteMunicipalityCommand($this->created->eventId, $target->id()->toRfc4122(), $message));
    }

    private function rows(string $table): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
    }

    private function uuidHex(string $uuid): string
    {
        return str_replace('-', '', $uuid);
    }

    // ── Invitation nominale ──────────────────────────────────────────────────

    public function testNominalInvitationOfANonSubscribedMunicipalityWithoutAnyAdministrator(): void
    {
        $w = $this->w;
        $before = ['membership' => $this->rows('municipality_membership'), 'access' => $this->rows('municipality_commercial_access'), 'user' => $this->rows('identity_user'), 'municipality' => $this->rows('municipality')];

        $result = $this->invite($w->adminO, $w->guestA, 'Merci de vous joindre à nous.');

        self::assertSame(ParticipationStatus::Invited, $result->status);
        self::assertSame($w->guestA->id()->toRfc4122(), $result->municipalityId);
        self::assertNotNull($result->proposedZoneId);
        $row = $this->em->getConnection()->fetchAssociative('SELECT status, LOWER(HEX(invited_by)) invited_by, invitation_message, invited_at, last_invitation_sent_at, activated_at FROM event_participation WHERE id = ?', [Uuid::fromString($result->participationId)->toBinary()]);
        self::assertSame('INVITED', $row['status']);
        self::assertSame($this->uuidHex($w->adminO->id()->toRfc4122()), $row['invited_by']);
        self::assertSame('Merci de vous joindre à nous.', $row['invitation_message']);
        self::assertNotNull($row['invited_at']);
        self::assertNull($row['activated_at']);
        self::assertSame(2, $this->rows('event_participation'), 'organisatrice + invitée');

        $zone = $this->em->getConnection()->fetchAssociative('SELECT type, status, confirmed_at, validity_mode FROM event_zone WHERE participation_id = ?', [Uuid::fromString($result->participationId)->toBinary()]);
        self::assertSame('WHOLE_MUNICIPALITY', $zone['type']);
        self::assertNull($zone['confirmed_at'], 'proposée, non confirmée, aucun droit');
        self::assertSame('UNCONFIGURED', $zone['validity_mode']);

        // L'invitation ne crée NI utilisateur, NI adhésion, NI abonnement ou accès commercial, NI commune.
        self::assertSame($before, ['membership' => $this->rows('municipality_membership'), 'access' => $this->rows('municipality_commercial_access'), 'user' => $this->rows('identity_user'), 'municipality' => $this->rows('municipality')]);
    }

    public function testInvitationAuditIsStructuredAndFreeOfFreeText(): void
    {
        $w = $this->w;
        $result = $this->invite($w->managerO, $w->guestB, 'Message confidentiel de l\'organisatrice');

        $rows = $this->em->getConnection()->fetchAllAssociative("SELECT action, subject_type, LOWER(HEX(subject_id)) subject, LOWER(HEX(municipality_id)) municipality, LOWER(HEX(actor_id)) actor, LOWER(HEX(batch_id)) batch, new_value FROM audit_log WHERE action IN ('PARTICIPATION_INVITED') OR (action = 'ZONE_CREATED' AND municipality_id = ?) ORDER BY action", [$w->guestB->id()->toBinary()]);

        self::assertSame(['PARTICIPATION_INVITED', 'ZONE_CREATED'], array_column($rows, 'action'));
        self::assertCount(1, array_unique(array_column($rows, 'batch')));
        foreach ($rows as $row) {
            self::assertSame($this->uuidHex($w->guestB->id()->toRfc4122()), $row['municipality'], 'commune concernée : l\'invitée');
            self::assertSame($this->uuidHex($w->managerO->id()->toRfc4122()), $row['actor']);
        }
        self::assertSame($this->uuidHex($result->participationId), $rows[0]['subject']);
        self::assertStringContainsString('"status": "INVITED"', $rows[0]['new_value']);
        self::assertStringNotContainsString('Message confidentiel', implode('', array_column($rows, 'new_value')));
    }

    public function testManagerAndAdministratorMayInvite(): void
    {
        $this->invite($this->w->adminO, $this->w->guestA);
        $this->invite($this->w->managerO, $this->w->guestB);

        self::assertSame(3, $this->rows('event_participation'));
    }

    // ── Refus d'autorisation ─────────────────────────────────────────────────

    public function testOnlyTheOrganizerAdministratorsAndManagersMayInvite(): void
    {
        $w = $this->w;
        $this->invite($w->adminO, $w->guestB); // B devient participante invitée, puis acceptante
        $participation = $this->em->find(EventParticipation::class, Uuid::fromString($this->em->getConnection()->fetchOne("SELECT BIN_TO_UUID(id) FROM event_participation WHERE status = 'INVITED'")));
        $participation?->accept(new \DateTimeImmutable());
        $this->em->flush();
        $before = $this->rows('event_participation') + $this->rows('audit_log');

        $attempts = [
            'instructeur de l\'organisatrice' => [$w->instructorO, 'rôle'],
            'administrateur d\'une commune participante' => [$w->adminB, 'Ressource inaccessible.'],
            'administrateur d\'une commune étrangère' => [$w->adminX, 'Ressource inaccessible.'],
            'super-administrateur CWS' => [$w->superAdmin, 'Ressource inaccessible.'],
        ];
        foreach ($attempts as $label => [$user, $fragment]) {
            try {
                $this->invite($user, $w->other);
                self::fail($label.' ne peut pas inviter.');
            } catch (OperationNotAllowed $e) {
                self::assertStringContainsStringIgnoringCase($fragment, $e->getMessage(), $label);
            }
        }
        self::assertSame($before, $this->rows('event_participation') + $this->rows('audit_log'), 'aucune écriture, aucun audit');
    }

    public function testForgedEventIdentifiersAreRefusedUniformly(): void
    {
        $w = $this->w;
        foreach ([Uuid::v7()->toRfc4122(), 'pas-un-uuid', "1' OR 1=1 --"] as $eventId) {
            try {
                $this->participation()->invite($this->as($w->adminO), new InviteMunicipalityCommand($eventId, $w->guestA->id()->toRfc4122()));
                self::fail('Événement forgé accepté.');
            } catch (OperationNotAllowed $e) {
                self::assertSame('Ressource inaccessible.', $e->getMessage());
            }
        }
        // Un événement existant mais invisible de l'acteur donne le même refus.
        $this->expectException(OperationNotAllowed::class);
        $this->expectExceptionMessage('Ressource inaccessible.');
        $this->participation()->invite($this->as($w->adminX), new InviteMunicipalityCommand($this->created->eventId, $w->guestA->id()->toRfc4122()));
    }

    // ── Refus métier ─────────────────────────────────────────────────────────

    public function testUnknownInvalidOrUnavailableMunicipalitiesAreRefusedUniformly(): void
    {
        $w = $this->w;
        $inactive = $this->em->find(Municipality::class, $w->guestA->id()) ?? self::fail('Commune absente.');
        $inactive->changeStatus(MunicipalityStatus::Inactive, new \DateTimeImmutable());
        $suspended = $this->em->find(Municipality::class, $w->guestB->id()) ?? self::fail('Commune absente.');
        $suspended->changeStatus(MunicipalityStatus::Suspended, new \DateTimeImmutable());
        $this->em->flush();

        foreach ([Uuid::v7()->toRfc4122(), 'pas-un-uuid', $w->guestA->id()->toRfc4122(), $w->guestB->id()->toRfc4122()] as $target) {
            try {
                $this->participation()->invite($this->as($w->adminO), new InviteMunicipalityCommand($this->created->eventId, $target));
                self::fail('Commune indisponible acceptée.');
            } catch (OperationNotAllowed $e) {
                self::assertSame('Commune introuvable ou indisponible.', $e->getMessage(), $target);
            }
        }
        self::assertSame(1, $this->rows('event_participation'));
    }

    public function testDuplicateInvitationsAndTheOrganizerItselfAreRefused(): void
    {
        $w = $this->w;
        $this->invite($w->adminO, $w->guestA);

        foreach ([$w->guestA, $w->org] as $target) {
            try {
                $this->invite($w->adminO, $target);
                self::fail('Doublon accepté.');
            } catch (OperationNotAllowed $e) {
                self::assertStringContainsString('déjà invitée', $e->getMessage());
            }
        }
        self::assertSame(2, $this->rows('event_participation'), 'jamais deux participations pour le même couple');
    }

    public function testAMunicipalityWhoseParticipationIsClosedCannotBeReinvited(): void
    {
        $w = $this->w;
        $result = $this->invite($w->adminO, $w->guestB);
        $participation = $this->em->find(EventParticipation::class, Uuid::fromString($result->participationId)) ?? self::fail('Participation absente.');
        $participation->accept(new \DateTimeImmutable());
        $participation->close(new \DateTimeImmutable());
        $this->em->flush();

        try {
            $this->invite($w->adminO, $w->guestB);
            self::fail('Réinvitation après clôture interdite (D8).');
        } catch (OperationNotAllowed $e) {
            self::assertStringContainsString('clôturée', $e->getMessage());
        }
        self::assertSame(ParticipationStatus::Closed->value, $this->em->getConnection()->fetchOne('SELECT status FROM event_participation WHERE id = ?', [Uuid::fromString($result->participationId)->toBinary()]));
        self::assertSame(2, $this->rows('event_participation'));
    }

    public function testAClosedEventAcceptsNoInvitation(): void
    {
        $event = $this->em->find(Event::class, Uuid::fromString($this->created->eventId)) ?? self::fail('Événement absent.');
        $event->close(new \DateTimeImmutable());
        $this->em->flush();

        $this->expectException(OperationNotAllowed::class);
        $this->expectExceptionMessage('clôturé');
        $this->invite($this->w->adminO, $this->w->guestA);
    }

    // ── Rollback ─────────────────────────────────────────────────────────────

    public function testAFailureRollsBackTheParticipationTheZoneAndTheAudit(): void
    {
        $c = self::getContainer();
        $service = new EventParticipationService(
            $c->get(EventTransactions::class),
            $c->get(ClockInterface::class),
            $c->get(EventResourceResolver::class),
            $c->get(EventAccessPolicy::class),
            $c->get(EventCreationPolicy::class),
            $c->get(EventLocker::class),
            $c->get(MunicipalityRepository::class),
            $c->get(EventParticipationRepository::class),
            $c->get(EventZoneRepository::class),
            new EventAuditRecorder(new FailingAuditTrail($c->get(AuditTrail::class), 2)),
        );
        $before = ['p' => $this->rows('event_participation'), 'z' => $this->rows('event_zone'), 'a' => $this->rows('audit_log')];

        try {
            $service->invite($this->as($this->w->adminO), new InviteMunicipalityCommand($this->created->eventId, $this->w->guestA->id()->toRfc4122()));
            self::fail('Échec injecté attendu.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Échec injecté', $e->getMessage());
        }

        self::assertSame($before, ['p' => $this->rows('event_participation'), 'z' => $this->rows('event_zone'), 'a' => $this->rows('audit_log')]);
    }

    // ── C2 : concurrence ─────────────────────────────────────────────────────

    public function testC2TheSameInvitationSentTwiceSimultaneouslyCreatesASingleParticipation(): void
    {
        $w = $this->w;
        $arguments = [$w->adminO->id()->toRfc4122(), $this->created->eventId, $w->guestA->id()->toRfc4122()];

        $results = ParallelPhp::run('invite-municipality.php', [$arguments, $arguments, $arguments]);

        $decoded = array_map(static fn (array $r): array => json_decode($r['stdout'], true, 512, \JSON_THROW_ON_ERROR), $results);
        $accepted = array_values(array_filter($decoded, static fn (array $d): bool => true === $d['ok']));
        $refused = array_values(array_filter($decoded, static fn (array $d): bool => false === $d['ok']));
        self::assertCount(1, $accepted, 'une seule invitation aboutit : '.json_encode($decoded));
        self::assertCount(2, $refused);
        foreach ($refused as $refusal) {
            self::assertStringContainsString('déjà invitée', $refusal['message'], 'refus contrôlé, pas une erreur technique : '.$refusal['error']);
        }
        self::assertSame(2, $this->rows('event_participation'), 'organisatrice + une invitée');
        self::assertSame(2, $this->rows('event_zone'));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM event_participation WHERE municipality_id = ?', [$w->guestA->id()->toBinary()]));
    }

    public function testConcurrentInvitationsOfDifferentMunicipalitiesAllSucceed(): void
    {
        $w = $this->w;
        $lists = array_map(fn (Municipality $m): array => [$w->adminO->id()->toRfc4122(), $this->created->eventId, $m->id()->toRfc4122()], [$w->guestA, $w->guestB, $w->other]);

        $results = ParallelPhp::run('invite-municipality.php', $lists);

        foreach ($results as $result) {
            $decoded = json_decode($result['stdout'], true, 512, \JSON_THROW_ON_ERROR);
            self::assertTrue($decoded['ok'], $result['stdout'].$result['stderr']);
        }
        self::assertSame(4, $this->rows('event_participation'));
    }

    // ── Réexpédition ─────────────────────────────────────────────────────────

    private function backdateInvitation(string $participationId): void
    {
        $this->em->getConnection()->executeStatement("UPDATE event_participation SET last_invitation_sent_at = '2026-01-01 00:00:00' WHERE id = ?", [Uuid::fromString($participationId)->toBinary()]);
        $this->em->clear();
    }

    public function testNominalResendKeepsTheSameParticipationAndUpdatesTheSendDate(): void
    {
        $w = $this->w;
        $invitation = $this->invite($w->adminO, $w->guestA);
        $this->backdateInvitation($invitation->participationId);

        $result = $this->participation()->resendInvitation($this->as($w->managerO), $invitation->participationId);

        self::assertSame($invitation->participationId, $result->participationId, 'même participation');
        self::assertSame(ParticipationStatus::Invited, $result->status);
        self::assertNull($result->proposedZoneId);
        self::assertSame(2, $this->rows('event_participation'), 'aucune seconde participation');
        self::assertSame(2, $this->rows('event_zone'), 'aucune seconde zone');
        $sentAt = $this->em->getConnection()->fetchOne('SELECT last_invitation_sent_at FROM event_participation WHERE id = ?', [Uuid::fromString($invitation->participationId)->toBinary()]);
        self::assertGreaterThan('2026-01-02', $sentAt);
        $audit = $this->em->getConnection()->fetchAssociative("SELECT action, LOWER(HEX(municipality_id)) municipality, LOWER(HEX(actor_id)) actor, previous_value, new_value FROM audit_log WHERE action = 'PARTICIPATION_INVITATION_RESENT'");
        self::assertSame($this->uuidHex($w->guestA->id()->toRfc4122()), $audit['municipality']);
        self::assertSame($this->uuidHex($w->managerO->id()->toRfc4122()), $audit['actor']);
        self::assertStringContainsString('2026-01-01T00:00:00Z', $audit['previous_value']);
        self::assertStringContainsString('lastInvitationSentAt', $audit['new_value']);
    }

    public function testResendRefusalsWriteNothing(): void
    {
        $w = $this->w;
        $invitation = $this->invite($w->adminO, $w->guestB);
        $before = $this->rows('audit_log');
        $id = $invitation->participationId;

        $attempts = [
            'instructeur' => [fn () => $this->participation()->resendInvitation($this->as($w->instructorO), $id), OperationNotAllowed::class, 'rôle'],
            'l\'invitée elle-même' => [fn () => $this->participation()->resendInvitation($this->as($w->adminB), $id), OperationNotAllowed::class, 'Ressource inaccessible.'],
            'commune étrangère' => [fn () => $this->participation()->resendInvitation($this->as($w->adminX), $id), OperationNotAllowed::class, 'Ressource inaccessible.'],
            'identifiant inexistant' => [fn () => $this->participation()->resendInvitation($this->as($w->adminO), Uuid::v7()->toRfc4122()), OperationNotAllowed::class, 'Ressource inaccessible.'],
            'identifiant invalide' => [fn () => $this->participation()->resendInvitation($this->as($w->adminO), 'pas-un-uuid'), OperationNotAllowed::class, 'Ressource inaccessible.'],
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
        self::assertSame($before, $this->rows('audit_log'));
    }

    public function testOnlyAPendingInvitationCanBeResentAndNeverOnAClosedEvent(): void
    {
        $w = $this->w;
        $accepted = $this->invite($w->adminO, $w->guestB);
        $pending = $this->invite($w->adminO, $w->guestA);
        $this->em->find(EventParticipation::class, Uuid::fromString($accepted->participationId))?->accept(new \DateTimeImmutable());
        $this->em->flush();
        $before = $this->rows('audit_log');

        try {
            $this->participation()->resendInvitation($this->as($w->adminO), $accepted->participationId);
            self::fail('Une participation acceptée ne se réexpédie pas.');
        } catch (DomainRuleViolation $e) {
            self::assertStringContainsString('en attente', $e->getMessage());
        }

        $this->em->find(Event::class, Uuid::fromString($this->created->eventId))?->close(new \DateTimeImmutable());
        $this->em->flush();
        try {
            $this->participation()->resendInvitation($this->as($w->adminO), $pending->participationId);
            self::fail('Un événement clôturé ne réexpédie rien.');
        } catch (OperationNotAllowed $e) {
            self::assertStringContainsString('clôturé', $e->getMessage());
        }
        self::assertSame($before, $this->rows('audit_log'));
    }
}
