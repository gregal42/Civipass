<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification;

use App\Event\Application\Support\EventLocker;
use App\Event\Application\Support\EventTransactions;
use App\Identity\Domain\User;
use App\Notification\Application\KeyRingProvider;
use App\Permit\Application\NotificationIntentRecorder;
use App\Permit\Application\PermitRevisionGuard;
use App\Permit\Application\PermitTokenReplacementService;
use App\Permit\Application\Result\TokenReplacementRequest;
use App\Permit\Domain\PermitRepository;
use App\Permit\Domain\PermitRevisionRepository;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\RequestSubmissionService;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\DurationMode;
use App\Request\Domain\TravelMode;
use App\Shared\Application\AuditTrail;
use App\Tests\Support\MunicipalDecisionTestCase;
use App\Tests\Support\NotificationKit;
use App\Tests\Support\RecordingMailer;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;

/**
 * Notifications de remplacement du QR (LOT 05.5.5, DM-119 §5) : PRIORITAIRE, jamais regroupée ni retardée ; JAMAIS un jeton, un lien de QR, un motif, un commentaire ni une
 * plaque ; fusion des seules notifications EN ATTENTE (un message déjà envoyé ne couvre jamais un nouveau remplacement) ; pannes SMTP temporaires et définitives ; aucune adresse
 * e-mail ; message exact tant qu'une autorisation non révoquée existe. Horloge figée au 12 juin 2026 09:00 UTC.
 */
#[Group('requires-request-schema')]
final class TokenReplacementNotificationTest extends MunicipalDecisionTestCase
{
    private const COMMENT = 'COMMENTAIRE-INTERNE-SENTINELLE';

    private KeyRingProvider $keys;
    private RecordingMailer $mailer;
    private User $organizerAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->keys = NotificationKit::provider(...NotificationKit::newKey('k1'));
        $this->mailer = new RecordingMailer($this->em->getConnection());
        $this->organizerAdmin = $this->adminOnly;
    }

    private function managed(User $user): User
    {
        return $this->em->find(User::class, $user->id()) ?? $user;
    }

    private function approved(?string $email = 'lea@exemple.fr'): string
    {
        $id = $this->service(RequestSubmissionService::class)->submitPublic(new SubmitRequestCommand(
            $this->event->id()->toRfc4122(),
            'Dupont',
            'Léa',
            null,
            $email,
            null === $email ? '0611223344' : null,
            'private_person',
            'home',
            null,
            TravelMode::Vehicle,
            ['QW-123-ER'],
            [$this->organizerZone->id()->toRfc4122()],
            true,
        ), $this->key())->requestId;
        $this->instruction()->approve($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $id, ApprovalDuration::fixed(DurationMode::H72));
        $this->clock->sleep(700);
        self::assertSame(null === $email ? [] : ['sent' => 1], $this->dispatch(), 'mise à disposition (sans adresse : enregistrée « sans destinataire », rien à envoyer)');
        $this->mailer->sent = [];
        $this->mailer->tried = [];
        $this->mailer->attempts = 0;

        return $id;
    }

    private function replace(string $requestId, ?string $reference = null): void
    {
        $c = self::getContainer();
        (new PermitTokenReplacementService($c->get(EventTransactions::class), $c->get(EventLocker::class), $c->get(PermitRevisionGuard::class), $c->get(PermitRevisionRepository::class), $c->get(PermitRepository::class), $this->clock, $c->get(AuditTrail::class), $c->get(NotificationIntentRecorder::class)))
            ->replace($this->managed($this->organizerAdmin), $this->organizer->id()->toRfc4122(), $requestId, new TokenReplacementRequest(Uuid::v7()->toRfc4122(), 'OTHER', self::COMMENT, true, $reference));
    }

    /** @return array<string, int> */
    private function dispatch(): array
    {
        return NotificationKit::dispatcher(self::getContainer(), $this->keys, $this->clock, $this->mailer)->run()->outcomes;
    }

    /** @return list<array<string, mixed>> */
    private function outbox(): array
    {
        return $this->em->getConnection()->fetchAllAssociative("SELECT status, attempts, last_error_code, available_at, occurred_at FROM notification_outbox WHERE type = 'QR_REPLACED' ORDER BY occurred_at, id");
    }

    private function assertNoLeak(string $requestId): void
    {
        $tokens = $this->em->getConnection()->fetchFirstColumn('SELECT token FROM permit_verification_token');
        $text = implode("\n", array_map(static fn (array $m): string => $m['text']."\n".$m['html'].$m['subject'], $this->mailer->sent));
        foreach ([...$tokens, ...array_map('strtoupper', $tokens), 'QW-123-ER', 'QW123ER', self::COMMENT, 'Autre motif', 'OTHER', 'http', '/v/', $requestId] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $text, 'ni jeton, ni lien, ni plaque, ni motif, ni commentaire : '.$forbidden);
        }
    }

    public function testAReplacementIsPriorityNeverGroupedAndSaysTheOldQrNoLongerWorks(): void
    {
        $requestId = $this->approved();
        $this->replace($requestId);

        $row = $this->outbox()[0];
        self::assertSame($row['occurred_at'], $row['available_at'], 'prioritaire : aucun délai');
        self::assertSame(['sent' => 1], $this->dispatch());

        $mail = $this->mailer->sent[0];
        self::assertStringContainsString('QR code remplacé', $mail['subject']);
        self::assertStringContainsString('LP-', $mail['subject'], 'la référence du laissez-passer');
        self::assertStringContainsString('ne fonctionnent plus', $mail['text']);
        self::assertStringContainsString('PDF', $mail['text']);
        self::assertStringContainsString('lien personnel', $mail['text']);
        self::assertStringContainsString('seul le contrôle du qr code fait foi', mb_strtolower($mail['text']));
        $this->assertNoLeak($requestId);
    }

    public function testTwoPendingReplacementsAreMergedIntoOneMessage(): void
    {
        $requestId = $this->approved();
        $this->replace($requestId);
        $reference = (string) $this->em->getConnection()->fetchOne('SELECT reference FROM permit_permit');
        $this->clock->sleep(30);
        $this->replace($requestId, $reference);

        self::assertSame(['sent' => 1, 'suppressed' => 1], $this->dispatch(), 'un seul message pour deux remplacements en attente');

        self::assertCount(1, $this->mailer->sent);
        $rows = $this->outbox();
        self::assertSame(['SENT', null], [$rows[0]['status'], $rows[0]['last_error_code']]);
        self::assertSame(['SUPPRESSED', 'ALREADY_INCLUDED'], [$rows[1]['status'], $rows[1]['last_error_code']]);
        $audits = array_map(static fn (string $j): string => json_decode($j, true)['reason'], $this->em->getConnection()->fetchFirstColumn("SELECT new_value FROM audit_log WHERE action = 'NOTIFICATION_SUPPRESSED'"));
        self::assertContains('ALREADY_INCLUDED', $audits, 'trace de la suppression');
        $this->assertNoLeak($requestId);
    }

    public function testAMessageAlreadySentNeverCoversANewerReplacement(): void
    {
        $requestId = $this->approved();
        $this->replace($requestId);
        self::assertSame(['sent' => 1], $this->dispatch());
        $this->clock->sleep(3601);
        $this->replace($requestId);

        self::assertSame(['sent' => 1], $this->dispatch(), 'le QR obtenu après le premier message est de nouveau invalide : nouveau message');

        self::assertCount(2, $this->mailer->sent);
        self::assertSame(['SENT', 'SENT'], array_column($this->outbox(), 'status'));
        $this->assertNoLeak($requestId);
    }

    public function testATemporaryFailureIsRetriedThenSentOnceWithAStableMessageId(): void
    {
        $requestId = $this->approved();
        $this->replace($requestId);
        $this->mailer->script(RecordingMailer::smtpFailure(451));

        self::assertSame(['retry' => 1], $this->dispatch());
        self::assertSame('PENDING', $this->outbox()[0]['status']);
        $this->clock->sleep(61);
        self::assertSame(['sent' => 1], array_intersect_key($this->dispatch(), ['sent' => 1]));

        self::assertSame('SENT', $this->outbox()[0]['status']);
        self::assertCount(1, array_unique(array_column($this->mailer->tried, 'id')), 'Message-ID stable');
        self::assertCount(1, $this->mailer->sent);
    }

    public function testAPermanentFailureIsAbandonedAndVisibleAndTheReplacementStaysInForce(): void
    {
        $requestId = $this->approved();
        $this->replace($requestId);
        $this->mailer->script(RecordingMailer::smtpFailure(550));

        $this->dispatch();

        $row = $this->outbox()[0];
        self::assertSame('ABANDONED', $row['status']);
        self::assertNotNull($row['last_error_code']);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM permit_verification_token WHERE retired_at IS NOT NULL'), 'le remplacement reste effectif');
    }

    public function testWithoutAnEmailAddressNothingIsSentAndTheAgentIsToldToDeliverTheQrThemselves(): void
    {
        $requestId = $this->approved(null);
        $this->replace($requestId);

        self::assertSame([], $this->dispatch(), 'enregistré sans destinataire : rien à envoyer');

        self::assertSame([], $this->mailer->sent);
        self::assertSame('NO_RECIPIENT', $this->outbox()[0]['status']);
    }

    public function testTheMessageIsSuppressedWhenNoNonRevokedAuthorizationRemains(): void
    {
        $requestId = $this->approved();
        $this->replace($requestId);
        $this->sql('UPDATE permit_authorization SET revoked_at = :t', ['t' => '2026-06-12 09:00:00']);

        $this->dispatch();

        self::assertSame([], $this->mailer->sent);
        self::assertSame('SUPPRESSED', $this->outbox()[0]['status']);
        self::assertContains($this->outbox()[0]['last_error_code'], ['INACCURATE', 'SUPERSEDED_BY_REVOCATION']);
    }

    public function testTheOutboxRowCarriesNoAuthorizationAndNoToken(): void
    {
        $requestId = $this->approved();
        $this->replace($requestId);

        $row = $this->em->getConnection()->fetchAssociative("SELECT authorization_id, deduplication_key FROM notification_outbox WHERE type = 'QR_REPLACED'");
        self::assertIsArray($row);
        self::assertNull($row['authorization_id'], 'niveau laissez-passer');
        foreach ($this->em->getConnection()->fetchFirstColumn('SELECT token FROM permit_verification_token') as $token) {
            self::assertStringNotContainsString($token, (string) $row['deduplication_key']);
        }
    }
}
