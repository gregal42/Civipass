<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification;

use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Permit\Application\PermitBackfill;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\RequestSubmissionService;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\DurationMode;
use App\Request\Domain\TravelMode;
use App\Request\Infrastructure\NullApprovedPartListener;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Domain\AuditAction;
use App\Tests\Support\MunicipalDecisionTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Enregistrement atomique des intentions de notification (LOT 05.3.1, DT-049, DM-117) : une ligne par événement métier, dans la transaction
 * de l'approbation, sans doublon au rejeu, NO_RECIPIENT sans adresse, rien au rattrapage historique, aucune donnée personnelle.
 */
#[Group('requires-request-schema')]
final class NotificationOutboxRecordingTest extends MunicipalDecisionTestCase
{
    private function managed(User $user): User
    {
        return $this->em->find(User::class, $user->id()) ?? $user;
    }

    private function approve(User $agent, Municipality $commune, string $requestId, ?AuditTrail $audit = null, ?\App\Request\Application\ApprovedPartListener $listener = null): void
    {
        $this->instruction($audit, $listener)->approve($this->managed($agent), $commune->id()->toRfc4122(), $requestId, ApprovalDuration::fixed(DurationMode::H24));
    }

    private function submitFor(?string $email): string
    {
        return $this->service(RequestSubmissionService::class)->submitPublic(new SubmitRequestCommand(
            $this->event->id()->toRfc4122(),
            'Dupont',
            'Léa',
            '12 rue des Lilas 29200 Brest',
            $email,
            null === $email ? '+33612345678' : null,
            'private_person',
            'home',
            null,
            TravelMode::Vehicle,
            ['QW-123-ER'],
            [$this->organizerZone->id()->toRfc4122(), $this->partnerZone->id()->toRfc4122()],
            true,
        ), $this->key())->requestId;
    }

    /** @return list<array<string, mixed>> */
    private function outbox(): array
    {
        return $this->em->getConnection()->fetchAllAssociative('SELECT *, LOWER(HEX(permit_id)) AS pid, LOWER(HEX(authorization_id)) AS aid FROM notification_outbox ORDER BY type');
    }

    public function testTheFirstApprovalRecordsOnePendingPermitIssuedIntentWithoutDelay(): void
    {
        $id = $this->submitFor('lea@exemple.fr');

        $this->approve($this->instructor, $this->organizer, $id);

        $rows = $this->outbox();
        self::assertCount(1, $rows);
        $permitId = bin2hex((string) $this->em->getConnection()->fetchOne('SELECT id FROM permit_permit'));
        $authId = bin2hex((string) $this->em->getConnection()->fetchOne('SELECT id FROM permit_authorization'));
        self::assertSame('PERMIT_ISSUED', $rows[0]['type']);
        self::assertSame('PENDING', $rows[0]['status']);
        self::assertSame($permitId, $rows[0]['pid']);
        self::assertSame($authId, $rows[0]['aid']);
        self::assertSame('PERMIT_ISSUED:'.$this->uuid($permitId), $rows[0]['deduplication_key']);
        self::assertSame($rows[0]['occurred_at'], $rows[0]['available_at'], 'première approbation : aucun délai de regroupement');
        self::assertSame(['0', null, null], [(string) $rows[0]['attempts'], $rows[0]['leased_until'], $rows[0]['processed_at']]);
    }

    public function testASecondCommuneRecordsOneAddedIntentDelayedByTheSilence(): void
    {
        $id = $this->submitFor('lea@exemple.fr');
        $this->approve($this->instructor, $this->organizer, $id);
        $this->clock->sleep(120);

        $this->approve($this->partnerInstructor, $this->partner, $id);

        $rows = $this->outbox();
        self::assertSame(['AUTHORIZATION_ADDED', 'PERMIT_ISSUED'], array_column($rows, 'type'));
        $added = $rows[0];
        self::assertSame('PENDING', $added['status']);
        self::assertSame(600, strtotime($added['available_at'].' UTC') - strtotime($added['occurred_at'].' UTC'));
        self::assertSame($rows[0]['pid'], $rows[1]['pid'], 'un seul laissez-passer');
        self::assertNotSame($rows[0]['aid'], $rows[1]['aid']);
    }

    public function testReplayingThePortRecordsNothingMore(): void
    {
        $id = $this->submitFor('lea@exemple.fr');
        $this->approve($this->instructor, $this->organizer, $id);
        $decisionId = $this->em->getConnection()->fetchOne('SELECT id FROM request_permit_request_decision');

        $this->service(\App\Shared\Application\TransactionRunner::class)->run(function () use ($decisionId): void {
            $decision = $this->em->find(\App\Request\Domain\PermitRequestDecision::class, \Symfony\Component\Uid\Uuid::fromBinary((string) $decisionId)) ?? self::fail('Décision absente.');
            \App\Tests\Support\PermitIssuerFactory::create(self::getContainer())->partApproved($decision);
        });

        self::assertCount(1, $this->outbox());
    }

    public function testTheRecorderIsIdempotentByDeduplicationKeyEvenWithoutTheIssuerGuard(): void
    {
        $id = $this->submitFor('lea@exemple.fr');
        $this->approve($this->instructor, $this->organizer, $id);
        $row = $this->outbox()[0];
        $recorder = self::getContainer()->get(\App\Permit\Application\NotificationIntentRecorder::class);
        $intent = new \App\Permit\Domain\NotificationIntent(\App\Permit\Domain\NotificationKind::PermitIssued, \Symfony\Component\Uid\Uuid::fromString($this->uuid($row['pid'])), \Symfony\Component\Uid\Uuid::fromString($this->uuid($row['aid'])), $this->clock->now(), true);

        $this->service(\App\Shared\Application\TransactionRunner::class)->run(function () use ($recorder, $intent): void {
            $recorder->record($intent);
            $recorder->record($intent); // doublon dans la même unité de travail
        });

        self::assertCount(1, $this->outbox());
    }

    public function testWithoutEmailTheIntentIsRecordedAsNoRecipientAndNeverPending(): void
    {
        $id = $this->submitFor(null);

        $this->approve($this->instructor, $this->organizer, $id);

        $rows = $this->outbox();
        self::assertCount(1, $rows);
        self::assertSame('NO_RECIPIENT', $rows[0]['status']);
        self::assertNotNull($rows[0]['processed_at']);
        self::assertSame(0, $this->rows('notification_outbox WHERE status IN (\'PENDING\', \'RESERVED\')'));
    }

    public function testCorrectingTheEmailLaterDoesNotReviveANoRecipientIntent(): void
    {
        $id = $this->submitFor(null);
        $this->approve($this->instructor, $this->organizer, $id);
        $this->sql('UPDATE request_permit_request SET email = :e', ['e' => 'tardif@exemple.fr']);

        $this->approve($this->partnerInstructor, $this->partner, $id);

        $byType = array_column($this->outbox(), 'status', 'type');
        self::assertSame('NO_RECIPIENT', $byType['PERMIT_ISSUED'], 'aucun envoi automatique non sollicité');
        self::assertSame('PENDING', $byType['AUTHORIZATION_ADDED'], 'un nouvel événement, lui, voit l\'adresse corrigée');
    }

    public function testAFailureAfterTheIntentRollsBackTheOutboxWithTheApproval(): void
    {
        $id = $this->submitFor('lea@exemple.fr');
        $inner = self::getContainer()->get(AuditTrail::class);
        // L'intention est écrite APRÈS l'audit du laissez-passer : un échec d'un audit postérieur annule tout.
        $failing = new class ($inner) implements AuditTrail {
            public function __construct(private readonly AuditTrail $inner)
            {
            }

            public function record(AuditEvent $event): void
            {
                $this->inner->record($event);
                if (AuditAction::PermitIssued === $event->action) {
                    throw new \RuntimeException('audit indisponible');
                }
            }
        };

        try {
            $this->approve($this->instructor, $this->organizer, $id, $failing);
            self::fail('L\'approbation doit être annulée.');
        } catch (\RuntimeException $e) {
            self::assertSame('audit indisponible', $e->getMessage());
        }

        self::assertSame([0, 0], [$this->rows('notification_outbox'), $this->rows('permit_permit')]);
    }

    public function testAnApprovalFailingAfterTheIntentWasWrittenLeavesNoOutboxRow(): void
    {
        $id = $this->submitFor('lea@exemple.fr');
        $failingAfter = new class (\App\Tests\Support\PermitIssuerFactory::create(self::getContainer())) implements \App\Request\Application\ApprovedPartListener {
            public function __construct(private readonly \App\Permit\Application\PermitIssuer $inner)
            {
            }

            public function partApproved(\App\Request\Domain\PermitRequestDecision $decision): void
            {
                $this->inner->partApproved($decision);
                throw new \RuntimeException('échec après l\'intention');
            }
        };

        try {
            $this->approve($this->instructor, $this->organizer, $id, null, $failingAfter);
            self::fail('L\'approbation doit être annulée.');
        } catch (\RuntimeException $e) {
            self::assertSame('échec après l\'intention', $e->getMessage());
        }

        self::assertSame([0, 0, 0], [$this->rows('notification_outbox'), $this->rows('permit_permit'), $this->rows('request_permit_request_decision')]);
    }

    public function testTheHistoricalBackfillRecordsNoNotification(): void
    {
        $id = $this->submitFor('lea@exemple.fr');
        $silent = new NullApprovedPartListener();
        $this->approve($this->instructor, $this->organizer, $id, null, $silent);
        $this->approve($this->partnerInstructor, $this->partner, $id, null, $silent);

        $report = self::getContainer()->get(PermitBackfill::class)->run(false);

        self::assertSame([1, 1], [$report->permitsCreated, $report->authorizationsAdded]);
        self::assertSame([1, 2], [$this->rows('permit_permit'), $this->rows('permit_authorization')]);
        self::assertSame(0, $this->rows('notification_outbox'), 'aucune notification au rattrapage');
    }

    public function testTheOutboxHoldsNoPersonalDataNorSecret(): void
    {
        $id = $this->submitFor('lea@exemple.fr');
        $this->approve($this->instructor, $this->organizer, $id);
        $this->approve($this->partnerInstructor, $this->partner, $id);

        $dump = json_encode($this->outbox(), \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE);
        foreach (['lea@exemple.fr', 'Dupont', 'Léa', 'Lilas', 'QW-123-ER', 'QW123ER', '+33612345678'] as $personal) {
            self::assertStringNotContainsString($personal, $dump);
        }
        $token = (string) $this->em->getConnection()->fetchOne('SELECT token FROM permit_verification_token');
        self::assertStringNotContainsString($token, $dump);
        $columns = array_column($this->em->getConnection()->fetchAllAssociative('SHOW COLUMNS FROM notification_outbox'), 'Field');
        self::assertEqualsCanonicalizing(['id', 'status', 'attempts', 'leased_until', 'processed_at', 'last_error_code', 'deduplication_key', 'type', 'permit_id', 'authorization_id', 'occurred_at', 'available_at', 'first_attempt_at', 'covered_authorizations'], $columns);
    }

    public function testTheUniqueKeyIsEnforcedBySql(): void
    {
        $id = $this->submitFor('lea@exemple.fr');
        $this->approve($this->instructor, $this->organizer, $id);

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $this->em->getConnection()->executeStatement('INSERT INTO notification_outbox (id, status, attempts, deduplication_key, type, permit_id, occurred_at, available_at) SELECT UNHEX(REPLACE(UUID(), \'-\', \'\')), status, 0, deduplication_key, type, permit_id, occurred_at, available_at FROM notification_outbox');
    }

    private function uuid(string $hex): string
    {
        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
    }
}
