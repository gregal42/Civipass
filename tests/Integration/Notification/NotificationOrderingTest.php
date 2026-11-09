<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification;

use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Notification\Application\KeyRingProvider;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\RequestSubmissionService;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\DurationMode;
use App\Request\Domain\TravelMode;
use App\Tests\Support\MunicipalDecisionTestCase;
use App\Tests\Support\NotificationKit;
use App\Tests\Support\RecordingMailer;
use PHPUnit\Framework\Attributes\Group;

/**
 * Ordre et suppression des notifications d'un même laissez-passer (LOT 05.3.4) : la mise à disposition passe toujours en premier ; une extension
 * attend, est supprimée avec un motif si elle est redondante, inexacte ou sans premier message remis, et reste éligible si elle est postérieure
 * à la composition du premier message. Horloge figée au 12 juin 2026 09:00 UTC.
 */
#[Group('requires-request-schema')]
final class NotificationOrderingTest extends MunicipalDecisionTestCase
{
    private KeyRingProvider $keys;
    private RecordingMailer $mailer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->keys = NotificationKit::provider(...NotificationKit::newKey('k1'));
        $this->mailer = new RecordingMailer($this->em->getConnection());
    }

    private function approve(User $agent, Municipality $commune, string $requestId): void
    {
        $user = $this->em->find(User::class, $agent->id()) ?? $agent;
        $this->instruction()->approve($user, $commune->id()->toRfc4122(), $requestId, ApprovalDuration::fixed(DurationMode::H72));
    }

    private function submitFor(?string $email = 'lea@exemple.fr'): string
    {
        return $this->service(RequestSubmissionService::class)->submitPublic(new SubmitRequestCommand(
            $this->event->id()->toRfc4122(),
            'Dupont',
            'Léa',
            null,
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

    /** @return array<string, int> */
    private function dispatch(?KeyRingProvider $keys = null): array
    {
        return NotificationKit::dispatcher(self::getContainer(), $keys ?? $this->keys, $this->clock, $this->mailer)->run()->outcomes;
    }

    /** @return array<string, mixed> */
    private function row(string $type): array
    {
        return $this->em->getConnection()->fetchAssociative('SELECT status, last_error_code, available_at, covered_authorizations FROM notification_outbox WHERE type = :t', ['t' => $type]) ?: [];
    }

    public function testAnExtensionApprovedBeforeTheFirstMessageIsComposedIsSuppressedAsRedundant(): void
    {
        $request = $this->submitFor();
        $this->approve($this->instructor, $this->organizer, $request);
        $this->approve($this->partnerInstructor, $this->partner, $request);
        $this->clock->sleep(700); // les deux messages sont échus

        self::assertSame(['sent' => 1, 'suppressed' => 1], $this->dispatch());

        self::assertCount(1, $this->mailer->sent, 'un seul message : le premier annonce déjà les deux communes');
        self::assertStringContainsString($this->partner->name(), $this->mailer->sent[0]['text']);
        self::assertSame(['SUPPRESSED', 'ALREADY_INCLUDED'], [$this->row('AUTHORIZATION_ADDED')['status'], $this->row('AUTHORIZATION_ADDED')['last_error_code']]);
        self::assertCount(2, json_decode((string) $this->row('PERMIT_ISSUED')['covered_authorizations'], true), 'identifiants conservés, jamais de donnée personnelle');
        $audit = $this->em->getConnection()->fetchAssociative('SELECT new_value, municipality_id FROM audit_log WHERE action = \'NOTIFICATION_SUPPRESSED\'');
        self::assertIsArray($audit);
        self::assertSame('ALREADY_INCLUDED', json_decode((string) $audit['new_value'], true)['reason']);
        self::assertStringNotContainsString('lea@exemple.fr', (string) $audit['new_value']);
    }

    public function testAnExtensionWaitsWhileTheFirstMessageWaitsForAKeyAndIsThenSuppressedNotLost(): void
    {
        $request = $this->submitFor();
        $this->approve($this->instructor, $this->organizer, $request);
        $this->approve($this->partnerInstructor, $this->partner, $request);
        $this->clock->sleep(700);
        $none = NotificationKit::provider(null, null);

        self::assertSame(['waiting_crypto' => 1, 'held' => 1], $this->dispatch($none));

        self::assertSame(0, $this->mailer->attempts, 'rien n\'est envoyé en panne de clé');
        self::assertSame(['PENDING', null], [$this->row('AUTHORIZATION_ADDED')['status'], $this->row('AUTHORIZATION_ADDED')['last_error_code']], 'l\'extension attend : elle n\'est PAS supprimée parce que le premier message est en attente');

        // La clé revient : le premier message part (il liste les deux communes), l'extension devient redondante.
        self::assertSame(['sent' => 1, 'suppressed' => 1], $this->dispatch());
        self::assertSame(1, $this->mailer->attempts);
        self::assertSame('ALREADY_INCLUDED', $this->row('AUTHORIZATION_ADDED')['last_error_code']);
    }

    public function testAChangeAfterTheCompositionOfTheFirstMessageStaysEligible(): void
    {
        $request = $this->submitFor();
        $this->approve($this->instructor, $this->organizer, $request);
        self::assertSame(['sent' => 1], $this->dispatch());
        $this->clock->sleep(30);
        $this->approve($this->partnerInstructor, $this->partner, $request);
        $this->clock->sleep(600);

        self::assertSame(['sent' => 1], $this->dispatch());

        self::assertCount(2, $this->mailer->sent);
        self::assertSame('SENT', $this->row('AUTHORIZATION_ADDED')['status']);
    }

    public function testAnExtensionIsNeverSentWhenTheFirstMessageWasAbandoned(): void
    {
        $request = $this->submitFor();
        $this->approve($this->instructor, $this->organizer, $request);
        $this->mailer->script(RecordingMailer::smtpFailure(550));
        $this->dispatch(); // premier message : refus définitif
        $this->clock->sleep(30);
        $this->approve($this->partnerInstructor, $this->partner, $request);
        $this->clock->sleep(600);

        self::assertSame(['suppressed' => 1], $this->dispatch());

        self::assertSame(1, $this->mailer->attempts, 'aucune extension annoncée « en plus du lien reçu »');
        self::assertSame(['SUPPRESSED', 'FIRST_NOT_DELIVERED'], [$this->row('AUTHORIZATION_ADDED')['status'], $this->row('AUTHORIZATION_ADDED')['last_error_code']]);
        self::assertSame(1, $this->rows('audit_log WHERE action = \'NOTIFICATION_SUPPRESSED\''), 'motif auditable');
    }

    public function testAnExtensionIsNeverSentWhenThereIsNoRecipientForTheFirstMessage(): void
    {
        $request = $this->submitFor(null);
        $this->approve($this->instructor, $this->organizer, $request);
        $this->approve($this->partnerInstructor, $this->partner, $request);
        $this->clock->sleep(700);

        $this->dispatch();

        self::assertSame(0, $this->mailer->attempts);
        self::assertSame(['NO_RECIPIENT'], $this->em->getConnection()->fetchFirstColumn('SELECT status FROM notification_outbox WHERE type = \'PERMIT_ISSUED\''));
    }

    public function testAnExtensionWhoseRightsWereRevokedBeforeSendingIsInaccurateAndNeverSent(): void
    {
        $request = $this->submitFor();
        $this->approve($this->instructor, $this->organizer, $request);
        $this->dispatch();
        $this->clock->sleep(30);
        $this->approve($this->partnerInstructor, $this->partner, $request);
        $this->sql('UPDATE permit_authorization SET revoked_at = :now WHERE municipality_id = :m', ['now' => $this->clock->now()->format('Y-m-d H:i:s'), 'm' => $this->partner->id()->toBinary()]);
        $this->clock->sleep(600);

        self::assertSame(['suppressed' => 1], $this->dispatch());

        self::assertSame(1, $this->mailer->attempts, 'jamais un message qui annonce des droits révoqués');
        self::assertSame(['SUPPRESSED', 'INACCURATE'], [$this->row('AUTHORIZATION_ADDED')['status'], $this->row('AUTHORIZATION_ADDED')['last_error_code']]);
    }

    public function testRedundantNeededAndInaccurateAreThreeDistinctOutcomes(): void
    {
        $codes = [\App\Notification\Domain\OutboxErrorCode::AlreadyIncluded->value, \App\Notification\Domain\OutboxErrorCode::Inaccurate->value, \App\Notification\Domain\OutboxErrorCode::FirstNotDelivered->value];

        self::assertCount(3, array_unique($codes));
        self::assertNotContains(\App\Notification\Domain\OutboxErrorCode::SmtpPermanent->value, $codes, 'une suppression n\'est jamais un échec d\'envoi');
        self::assertTrue(\App\Notification\Domain\OutboxStatus::Suppressed->isFinal());
        self::assertNotSame(\App\Notification\Domain\OutboxStatus::Abandoned, \App\Notification\Domain\OutboxStatus::Suppressed);
    }

    public function testASuppressedExtensionIsNeverSentLaterEvenWhenTheFirstMessageIsResent(): void
    {
        $request = $this->submitFor();
        $this->approve($this->instructor, $this->organizer, $request);
        $this->mailer->script(RecordingMailer::smtpFailure(550));
        $this->dispatch();
        $this->approve($this->partnerInstructor, $this->partner, $request);
        $this->clock->sleep(700);
        $this->dispatch();
        self::assertSame('SUPPRESSED', $this->row('AUTHORIZATION_ADDED')['status']);

        $before = $this->mailer->attempts;
        $this->clock->sleep(3600);
        self::assertSame([], $this->dispatch());

        self::assertSame($before, $this->mailer->attempts);
    }

    public function testASecondExtensionCoveredByAnAlreadySentExtensionIsRedundantSoAGroupProducesOneMessage(): void
    {
        $permit = \Symfony\Component\Uid\Uuid::v7();
        [$a, $b, $c] = [\Symfony\Component\Uid\Uuid::v7(), \Symfony\Component\Uid\Uuid::v7(), \Symfony\Component\Uid\Uuid::v7()];
        $insert = 'INSERT INTO notification_outbox (id, status, attempts, deduplication_key, type, permit_id, authorization_id, occurred_at, available_at, processed_at, covered_authorizations) VALUES (:id, :status, 1, :key, :type, :permit, :auth, :t, :t, :done, :covered)';
        $row = fn (string $status, string $key, string $type, ?\Symfony\Component\Uid\Uuid $auth, ?string $covered) => $this->sql($insert, ['id' => \Symfony\Component\Uid\Uuid::v7()->toBinary(), 'status' => $status, 'key' => $key, 'type' => $type, 'permit' => $permit->toBinary(), 'auth' => $auth?->toBinary(), 't' => '2026-06-12 08:00:00', 'done' => 'SENT' === $status ? '2026-06-12 08:30:00' : null, 'covered' => $covered]);
        $row('SENT', 'PERMIT_ISSUED:'.$permit->toRfc4122(), 'PERMIT_ISSUED', $a, json_encode([$a->toRfc4122()]));
        $row('SENT', 'AUTHORIZATION_ADDED:'.$b->toRfc4122(), 'AUTHORIZATION_ADDED', $b, json_encode([$a->toRfc4122(), $b->toRfc4122(), $c->toRfc4122()]));
        $row('PENDING', 'AUTHORIZATION_ADDED:'.$c->toRfc4122(), 'AUTHORIZATION_ADDED', $c, null);
        $pending = \Symfony\Component\Uid\Uuid::fromBinary((string) $this->em->getConnection()->fetchOne('SELECT id FROM notification_outbox WHERE status = \'PENDING\''));
        $ordering = new \App\Notification\Application\NotificationOrdering(self::getContainer()->get(\App\Shared\Application\TransactionRunner::class), self::getContainer()->get(\App\Notification\Domain\NotificationOutboxRepository::class), self::getContainer()->get(\App\Shared\Application\AuditTrail::class), $this->clock);

        self::assertSame(\App\Notification\Application\OrderingDecision::Suppressed, $ordering->decide($pending));

        self::assertSame(['SUPPRESSED', 'ALREADY_INCLUDED'], $this->em->getConnection()->fetchNumeric('SELECT status, last_error_code FROM notification_outbox WHERE status = \'SUPPRESSED\''));
    }
}
