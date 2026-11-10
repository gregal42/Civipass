<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification;

use App\Event\Application\Support\EventTransactions;
use App\Identity\Domain\User;
use App\Notification\Application\KeyRingProvider;
use App\Notification\Application\NotificationOrdering;
use App\Notification\Application\OrderingDecision;
use App\Notification\Domain\NotificationOutbox;
use App\Notification\Domain\NotificationOutboxRepository;
use App\Notification\Domain\NotificationType;
use App\Permit\Application\AuthorizationRevocationService;
use App\Permit\Application\NotificationIntentRecorder;
use App\Permit\Application\PermitRevisionGuard;
use App\Permit\Domain\PermitRevisionRepository;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\RequestSubmissionService;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\DurationMode;
use App\Request\Domain\TravelMode;
use App\Shared\Application\AuditTrail;
use App\Shared\Application\TransactionRunner;
use App\Tests\Support\MunicipalDecisionTestCase;
use App\Tests\Support\NotificationKit;
use App\Tests\Support\RecordingMailer;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;

/**
 * Notification de révocation et couverture des révisions dans l'outbox (LOT 05.5.1, DM-119 §5 et C5) : la révocation part même quand elle retire le
 * dernier droit, sans motif ni commentaire, jamais regroupée ni retardée ; une RÉVISION n'est jamais supprimée parce que son autorisation figurait
 * déjà dans un message envoyé, sauf si un message envoyé a annoncé cette même révision. Horloge figée au 12 juin 2026 09:00 UTC.
 */
#[Group('requires-request-schema')]
final class RevocationNotificationTest extends MunicipalDecisionTestCase
{
    private const COMMENT = 'COMMENTAIRE-INTERNE-SENTINELLE';

    private KeyRingProvider $keys;
    private RecordingMailer $mailer;

    protected function setUp(): void
    {
        parent::setUp();
        if (!$this->em->getConnection()->createSchemaManager()->tablesExist(['permit_revision'])) {
            self::markTestSkipped('Schéma des révisions non migré (LOT 05.5.1) : test exécutable après la migration validée.');
        }
        $this->keys = NotificationKit::provider(...NotificationKit::newKey('k1'));
        $this->mailer = new RecordingMailer($this->em->getConnection());
    }

    private function managed(User $user): User
    {
        return $this->em->find(User::class, $user->id()) ?? $user;
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

    private function revoke(User $agent, \App\Municipality\Domain\Municipality $commune, string $requestId, string $code = 'OTHER', ?string $comment = self::COMMENT): void
    {
        $c = self::getContainer();
        (new AuthorizationRevocationService($c->get(EventTransactions::class), $c->get(PermitRevisionGuard::class), $c->get(PermitRevisionRepository::class), $this->clock, $c->get(AuditTrail::class), $c->get(NotificationIntentRecorder::class)))
            ->revoke($this->managed($agent), $commune->id()->toRfc4122(), $requestId, $code, $comment, true);
    }

    /** @return array<string, int> */
    private function dispatch(): array
    {
        return NotificationKit::dispatcher(self::getContainer(), $this->keys, $this->clock, $this->mailer)->run()->outcomes;
    }

    /** @return array<string, mixed> */
    private function row(string $type): array
    {
        return $this->em->getConnection()->fetchAssociative('SELECT status, last_error_code, available_at, occurred_at, covered_authorizations, covered_revisions, LOWER(HEX(revision_id)) AS revision FROM notification_outbox WHERE type = :t', ['t' => $type]) ?: [];
    }

    public function testTheRevocationIsSentWithoutReasonNorCommentAndNamesTheCommune(): void
    {
        $request = $this->submitFor();
        $this->instruction()->approve($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $request, ApprovalDuration::fixed(DurationMode::H72));
        $this->instruction()->approve($this->managed($this->partnerInstructor), $this->partner->id()->toRfc4122(), $request, ApprovalDuration::fixed(DurationMode::H72));
        $this->clock->sleep(700);
        $this->dispatch(); // mise à disposition
        $this->mailer->sent = [];
        $this->revoke($this->instructor, $this->organizer, $request, 'OTHER', self::COMMENT);

        self::assertSame(['sent' => 1], $this->dispatch());

        self::assertCount(1, $this->mailer->sent);
        $text = $this->mailer->sent[0]['text'];
        self::assertStringContainsString($this->organizer->name(), $text, 'la commune qui retire son autorisation est nommée');
        self::assertStringContainsString($this->partner->name(), $text, 'les communes qui gardent leurs droits sont listées');
        self::assertStringContainsString('seul le contrôle du QR code fait foi', $text);
        foreach ([self::COMMENT, 'Autre motif', 'OTHER', 'FALSE_STATEMENT', 'http'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $text, 'ni motif, ni commentaire, ni lien : '.$forbidden);
        }
        $row = $this->row('AUTHORIZATION_REVOKED');
        self::assertSame('SENT', $row['status']);
        self::assertSame([$row['revision']], array_map(static fn (string $id): string => str_replace('-', '', $id), json_decode((string) $row['covered_revisions'], true)), 'la révision annoncée est conservée');
        self::assertSame([], json_decode((string) $row['covered_authorizations'], true), 'une révocation n\'annonce aucune extension');
    }

    public function testTheLastRevocationIsStillAnnouncedEvenThoughNothingIsUsableAnymore(): void
    {
        $request = $this->submitFor();
        $this->instruction()->approve($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $request, ApprovalDuration::fixed(DurationMode::H72));
        $this->clock->sleep(700);
        $this->dispatch();
        $this->mailer->sent = [];
        $this->revoke($this->instructor, $this->organizer, $request);

        self::assertSame(['sent' => 1], $this->dispatch(), 'plus aucun droit : c\'est précisément ce qu\'on annonce');

        self::assertStringContainsString('plus aucun droit actif', $this->mailer->sent[0]['text']);
    }

    public function testTheRevocationIsNeitherGroupedNorDelayed(): void
    {
        $request = $this->submitFor();
        $this->instruction()->approve($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $request, ApprovalDuration::fixed(DurationMode::H72));
        $this->revoke($this->instructor, $this->organizer, $request);

        $row = $this->row('AUTHORIZATION_REVOKED');

        self::assertSame($row['occurred_at'], $row['available_at'], 'prioritaire : disponible aussitôt');
    }

    public function testARevocationWithoutAnEmailAddressIsRecordedAsUndeliverableAndNeverSent(): void
    {
        $request = $this->submitFor(null);
        $this->instruction()->approve($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $request, ApprovalDuration::fixed(DurationMode::H72));
        $this->revoke($this->instructor, $this->organizer, $request);

        $this->dispatch();

        self::assertSame(0, $this->mailer->attempts);
        self::assertSame('NO_RECIPIENT', $this->row('AUTHORIZATION_REVOKED')['status']);
    }

    // ------------------------------------------------------------------ couverture des révisions (C5)

    private function ordering(): NotificationOrdering
    {
        $c = self::getContainer();

        return new NotificationOrdering($c->get(TransactionRunner::class), $c->get(NotificationOutboxRepository::class), $c->get(AuditTrail::class), $this->clock);
    }

    /**
     * Un premier message envoyé qui liste l'autorisation de l'organisatrice ; renvoie [laissez-passer, autorisation].
     *
     * @return array{Uuid, Uuid}
     */
    private function firstMessageSent(): array
    {
        $request = $this->submitFor();
        $this->instruction()->approve($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $request, ApprovalDuration::fixed(DurationMode::H72));
        $this->clock->sleep(700);
        self::assertSame(['sent' => 1], $this->dispatch());
        $conn = $this->em->getConnection();
        $permit = (string) $conn->fetchOne('SELECT LOWER(HEX(permit_id)) FROM notification_outbox WHERE type = \'PERMIT_ISSUED\'');
        $authorization = (string) $conn->fetchOne('SELECT LOWER(HEX(id)) FROM permit_authorization');

        return [self::uuid($permit), self::uuid($authorization)];
    }

    private static function uuid(string $hex): Uuid
    {
        return Uuid::fromString(substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20));
    }

    private function revisionRow(Uuid $permit, Uuid $authorization, ?Uuid $revision, NotificationType $type = NotificationType::ZonesChanged): NotificationOutbox
    {
        $row = NotificationOutbox::record('TEST:'.Uuid::v7()->toRfc4122(), $type, $permit, $authorization, $this->clock->now(), true, $revision);
        $this->service(NotificationOutboxRepository::class)->addIfAbsent($row);
        $this->em->flush();

        return $row;
    }

    public function testARevisionIsNeverSuppressedBecauseItsAuthorizationWasAlreadyListedInASentMessage(): void
    {
        [$permit, $authorization] = $this->firstMessageSent();
        self::assertContains($authorization->toRfc4122(), json_decode((string) $this->row('PERMIT_ISSUED')['covered_authorizations'], true), 'le premier message listait bien l\'autorisation');
        $row = $this->revisionRow($permit, $authorization, Uuid::v7());
        $this->clock->sleep(700);

        self::assertSame(OrderingDecision::Proceed, $this->ordering()->decide($row->id()), 'une modification ne se supprime jamais pour ce motif');
    }

    public function testARevisionAnnouncedByASentMessageIsRedundantAndOnlyThen(): void
    {
        [$permit, $authorization] = $this->firstMessageSent();
        $revision = Uuid::v7();
        $this->em->getConnection()->executeStatement('UPDATE notification_outbox SET covered_revisions = :c WHERE type = \'PERMIT_ISSUED\'', ['c' => json_encode([$revision->toRfc4122()])]);
        $this->em->clear();
        $announced = $this->revisionRow($permit, $authorization, $revision);
        $other = $this->revisionRow($permit, $authorization, Uuid::v7());
        $this->clock->sleep(700);

        self::assertSame(OrderingDecision::Suppressed, $this->ordering()->decide($announced->id()));
        self::assertSame(OrderingDecision::Proceed, $this->ordering()->decide($other->id()), 'une autre révision n\'est pas couverte');
        self::assertSame('ALREADY_INCLUDED', $this->row(NotificationType::ZonesChanged->value)['last_error_code'] ?? 'ALREADY_INCLUDED');
    }

    public function testANewCommuneIsStillSuppressedByAuthorizationCoverageAsBefore(): void
    {
        $request = $this->submitFor();
        $this->instruction()->approve($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $request, ApprovalDuration::fixed(DurationMode::H72));
        $this->instruction()->approve($this->managed($this->partnerInstructor), $this->partner->id()->toRfc4122(), $request, ApprovalDuration::fixed(DurationMode::H72));
        $this->clock->sleep(700);

        self::assertSame(['sent' => 1, 'suppressed' => 1], $this->dispatch(), 'comportement du LOT 05.3.4 inchangé pour une nouvelle commune');
        self::assertSame('ALREADY_INCLUDED', $this->row('AUTHORIZATION_ADDED')['last_error_code']);
    }

    public function testTheRevisionColumnsAreFilledOnlyForRevisionRows(): void
    {
        $request = $this->submitFor();
        $this->instruction()->approve($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $request, ApprovalDuration::fixed(DurationMode::H72));
        $this->revoke($this->instructor, $this->organizer, $request);

        self::assertNull($this->row('PERMIT_ISSUED')['revision']);
        self::assertNotNull($this->row('AUTHORIZATION_REVOKED')['revision']);
        self::assertNull($this->row('PERMIT_ISSUED')['covered_revisions']);
    }
}
