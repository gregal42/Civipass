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
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\RecordingMailer;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;

/**
 * Distributeur de notifications (LOT 05.3.3) : envoi, états, réessais, plafonds, bail, clé indisponible, expiration du secret, révocations,
 * absence de destinataire, journaux sans secret, Message-ID stable, jamais de nouveau lien. Transport simulé ; horloge figée au 12 juin 2026 09:00 UTC.
 */
#[Group('requires-request-schema')]
final class NotificationDispatcherTest extends MunicipalDecisionTestCase
{
    private const PLATE = 'QW-123-ER';
    private const ADDRESS = '12 rue des Lilas';

    private KeyRingProvider $keys;
    private RecordingMailer $mailer;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->keys = NotificationKit::provider(...NotificationKit::newKey('k1'));
        $this->mailer = new RecordingMailer($this->em->getConnection());
        $this->logger = new RecordingLogger();
    }

    private function managed(User $user): User
    {
        return $this->em->find(User::class, $user->id()) ?? $user;
    }

    private function approve(User $agent, Municipality $commune, string $requestId, DurationMode $mode = DurationMode::H24): void
    {
        $this->instruction()->approve($this->managed($agent), $commune->id()->toRfc4122(), $requestId, DurationMode::UntilClosure === $mode ? ApprovalDuration::untilClosure() : ApprovalDuration::fixed($mode));
    }

    private function submitFor(?string $email = 'lea@exemple.fr'): string
    {
        return $this->service(RequestSubmissionService::class)->submitPublic(new SubmitRequestCommand(
            $this->event->id()->toRfc4122(),
            'Dupont',
            'Léa',
            self::ADDRESS.' 29200 Brest',
            $email,
            null === $email ? '+33612345678' : null,
            'private_person',
            'home',
            null,
            TravelMode::Vehicle,
            [self::PLATE],
            [$this->organizerZone->id()->toRfc4122(), $this->partnerZone->id()->toRfc4122()],
            true,
        ), $this->key())->requestId;
    }

    /** Approuve une demande par la commune organisatrice ; retourne l'identifiant de sa ligne PERMIT_ISSUED. */
    private function issued(?string $email = 'lea@exemple.fr', DurationMode $mode = DurationMode::H24): Uuid
    {
        $this->approve($this->instructor, $this->organizer, $this->submitFor($email), $mode);

        return Uuid::fromBinary((string) $this->em->getConnection()->fetchOne('SELECT id FROM notification_outbox WHERE type = \'PERMIT_ISSUED\' ORDER BY id DESC'));
    }

    /** @return array<string, int> */
    private function dispatch(?KeyRingProvider $keys = null, ?RecordingMailer $mailer = null): array
    {
        return NotificationKit::dispatcher(self::getContainer(), $keys ?? $this->keys, $this->clock, $mailer ?? $this->mailer, $this->logger)->run()->outcomes;
    }

    /** @return array<string, mixed> */
    private function row(string $type = 'PERMIT_ISSUED'): array
    {
        return $this->em->getConnection()->fetchAssociative('SELECT status, attempts, last_error_code, available_at, leased_until, first_attempt_at, processed_at FROM notification_outbox WHERE type = :t ORDER BY id DESC', ['t' => $type]) ?: [];
    }

    private function now(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function seconds(string $utc): int
    {
        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->getTimestamp();
    }

    public function testTheFirstApprovalSendsOneSoberMessageWithTheLinkAndCleansUp(): void
    {
        $id = $this->issued();

        self::assertSame(['sent' => 1], $this->dispatch());

        self::assertCount(1, $this->mailer->sent);
        $mail = $this->mailer->sent[0];
        $row = $this->row();
        self::assertSame(['SENT', '1', null], [$row['status'], (string) $row['attempts'], $row['last_error_code']]);
        self::assertNotNull($row['processed_at']);
        self::assertNotNull($row['first_attempt_at']);
        self::assertSame('<'.$id->toRfc4122().'@civipass.invalid>', $mail['id'], 'Message-ID dérivé du message, donc stable');
        self::assertSame('Votre laissez-passer LP-2026-0001 est disponible', $mail['subject']);
        foreach ([$mail['text'], $mail['html']] as $body) {
            self::assertStringContainsString('Léa', $body);
            self::assertStringContainsString('LP-2026-0001', $body);
            self::assertStringContainsString($this->event->name(), $body);
            self::assertStringContainsString($this->organizer->name(), $body);
            self::assertStringContainsString($this->organizerZone->label(), $body);
            self::assertStringContainsString('http://civipass.test/laissez-passer/', $body);
            foreach ([self::PLATE, 'QW123ER', self::ADDRESS, 'Brest', 'Dupont'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $body, 'ni plaque, ni adresse postale, ni nom');
            }
        }
        self::assertStringNotContainsString('<img', $mail['html']);
        self::assertStringNotContainsString('src=', $mail['html']);
        self::assertSame(1, substr_count($mail['html'], 'href='), 'aucune ressource externe : le seul lien est celui du bénéficiaire');
        self::assertSame(0, preg_match_all('#https?://(?!civipass\.test/laissez-passer/)#', $mail['html']), 'aucune autre URL');
        self::assertFalse($mail['inTransaction'], 'aucun envoi dans une transaction');
        self::assertSame(0, $this->rows('notification_secret'), 'secret supprimé après traitement définitif');
        self::assertSame(1, $this->rows('permit_access_token WHERE revoked_at IS NULL'), 'le lien transmis reste actif');
        self::assertSame($mail['secretHash'], $this->em->getConnection()->fetchOne('SELECT secret_hash FROM permit_access_token'));
    }

    public function testAnExtensionAfterTheFirstMessageIsAnnouncedAfterTheSilenceWithoutAnyNewLink(): void
    {
        $request = $this->submitFor();
        $this->approve($this->instructor, $this->organizer, $request);
        self::assertSame(['sent' => 1], $this->dispatch());
        $this->clock->sleep(120);
        $this->approve($this->partnerInstructor, $this->partner, $request);

        self::assertSame([], $this->dispatch(), 'dans la période de silence');
        $this->clock->sleep(600);
        self::assertSame(['sent' => 1], $this->dispatch());

        self::assertCount(2, $this->mailer->sent);
        $added = $this->mailer->sent[1];
        self::assertSame('Votre laissez-passer LP-2026-0001 : droits étendus', $added['subject']);
        self::assertNull($added['secretHash']);
        self::assertStringNotContainsString('/laissez-passer/', $added['text'].$added['html'], 'aucun nouveau lien');
        self::assertStringContainsString($this->partner->name(), $added['text']);
        self::assertStringContainsString($this->organizer->name(), $added['text']);
        self::assertSame(1, $this->rows('permit_access_token'), 'un seul lien pour toute la vie du laissez-passer');
        self::assertSame(['SENT', 'SENT'], $this->em->getConnection()->fetchFirstColumn('SELECT status FROM notification_outbox ORDER BY type'));
    }

    public function testAMessageNotYetDueIsLeftAlone(): void
    {
        $request = $this->submitFor();
        $this->approve($this->instructor, $this->organizer, $request);
        $this->dispatch();
        $this->approve($this->partnerInstructor, $this->partner, $request);

        $before = $this->mailer->attempts;
        $this->dispatch();

        self::assertSame($before, $this->mailer->attempts);
        self::assertSame('PENDING', $this->row('AUTHORIZATION_ADDED')['status']);
    }

    public function testATemporaryFailureFollowsTheFiveDelaysThenTheSixthAttemptAbandonsWithTheSameLinkAndMessageId(): void
    {
        $id = $this->issued();
        $this->mailer->script(...array_fill(0, 6, RecordingMailer::smtpFailure(451)));
        $this->sql('INSERT INTO permit_access_token (id, secret_hash, created_at, issued_by_user_id, issued_by_municipality_id, permit_id, issuer_type) SELECT UNHEX(REPLACE(UUID(), \'-\', \'\')), REPEAT(\'a\', 64), NOW(), :u, :m, id, \'AGENT\' FROM permit_permit', ['u' => $this->instructor->id()->toBinary(), 'm' => $this->organizer->id()->toBinary()]);

        foreach ([1, 5, 15, 60, 240] as $i => $minutes) {
            self::assertSame(['retry' => 1], $this->dispatch(), 'tentative '.($i + 1));
            $row = $this->row();
            self::assertSame(['PENDING', (string) ($i + 1), 'SMTP_TEMPORARY'], [$row['status'], (string) $row['attempts'], $row['last_error_code']]);
            self::assertSame($minutes * 60, $this->seconds((string) $row['available_at']) - $this->seconds($this->now()));
            $tried = $this->mailer->attempts;
            self::assertSame([], $this->dispatch(), 'rien avant l\'échéance');
            self::assertSame($tried, $this->mailer->attempts);
            $this->clock->sleep($minutes * 60);
        }

        self::assertSame(['abandoned' => 1], $this->dispatch(), 'sixième tentative');

        $row = $this->row();
        self::assertSame(['ABANDONED', '6', 'SMTP_EXHAUSTED'], [$row['status'], (string) $row['attempts'], $row['last_error_code']]);
        self::assertSame(6, $this->mailer->attempts);
        self::assertCount(1, array_unique(array_column($this->mailer->tried, 'id')), 'Message-ID stable');
        self::assertSame('<'.$id->toRfc4122().'@civipass.invalid>', $this->mailer->tried[0]['id']);
        self::assertCount(1, array_unique(array_column($this->mailer->tried, 'secretHash')), 'même secret à chaque tentative');
        self::assertSame(2, $this->rows('permit_access_token'), 'aucun nouveau lien pendant les réessais (1 automatique + 1 agent)');
        self::assertSame(0, $this->rows('notification_secret'));
        self::assertSame(['SYSTEM'], $this->em->getConnection()->fetchFirstColumn('SELECT issuer_type FROM permit_access_token WHERE revoked_at IS NOT NULL'), 'lien jamais transmis révoqué');
        self::assertSame(1, $this->rows('permit_access_token WHERE revoked_at IS NULL AND issuer_type = \'AGENT\''), 'le lien de l\'agent n\'est jamais touché');
        self::assertSame(1, $this->rows('audit_log WHERE action = \'PERMIT_ACCESS_LINK_REVOKED\''));
    }

    public function testAPermanentFailureAbandonsImmediately(): void
    {
        $this->issued();
        $this->mailer->script(RecordingMailer::smtpFailure(550));

        self::assertSame(['abandoned' => 1], $this->dispatch());

        $row = $this->row();
        self::assertSame(['ABANDONED', '1', 'SMTP_PERMANENT'], [$row['status'], (string) $row['attempts'], $row['last_error_code']]);
        self::assertSame(0, $this->rows('notification_secret'));
        self::assertNotNull($this->em->getConnection()->fetchOne('SELECT revoked_at FROM permit_access_token'));
        self::assertSame([], $this->dispatch());
    }

    public function testAnAuthenticationRefusalIsTreatedAsAConfigurationFaultAndRetried(): void
    {
        $this->issued();
        $this->mailer->script(RecordingMailer::smtpFailure(535));

        self::assertSame(['retry' => 1], $this->dispatch());
        self::assertSame('SMTP_TEMPORARY', $this->row()['last_error_code']);
    }

    public function testAnInvalidAddressIsAbandonedWithoutAnySmtpCall(): void
    {
        $this->issued();
        $this->sql('UPDATE request_permit_request SET email = \'pas une adresse\'');

        self::assertSame(['abandoned' => 1], $this->dispatch());

        self::assertSame(['ABANDONED', 'ADDRESS_INVALID'], [$this->row()['status'], $this->row()['last_error_code']]);
        self::assertSame(0, $this->mailer->attempts);
        self::assertNotNull($this->em->getConnection()->fetchOne('SELECT revoked_at FROM permit_access_token'));
    }

    public function testTheTwentyFourHourWindowAbandonsWithoutSendingAgain(): void
    {
        $this->issued(mode: DurationMode::UntilClosure);
        $this->mailer->script(RecordingMailer::smtpFailure(451));
        $this->dispatch();
        $this->clock->sleep(24 * 3600);

        self::assertSame(['abandoned' => 1], $this->dispatch());

        self::assertSame(['ABANDONED', 'SMTP_WINDOW_EXPIRED', 1], [$this->row()['status'], $this->row()['last_error_code'], $this->mailer->attempts]);
        self::assertSame(0, $this->rows('notification_secret'));
    }

    public function testAnExpiredLeaseIsTakenOverWhileAValidOneIsRespected(): void
    {
        $id = $this->issued();
        // Simule un distributeur mort après la réservation : lien et secret existent, message réservé.
        NotificationKit::provisioner(self::getContainer(), $this->keys, $this->clock)->provision($id);
        $this->sql('UPDATE notification_outbox SET status = \'RESERVED\', attempts = 1, first_attempt_at = :now, leased_until = :lease', ['now' => $this->now(), 'lease' => $this->clock->now()->modify('+10 minutes')->format('Y-m-d H:i:s')]);

        self::assertSame([], $this->dispatch(), 'bail valide : non repris');
        self::assertSame(0, $this->mailer->attempts);

        $this->clock->sleep(600);
        self::assertSame(['sent' => 1], $this->dispatch());

        self::assertSame(['SENT', '2'], [$this->row()['status'], (string) $this->row()['attempts']]);
        self::assertSame(1, $this->rows('permit_access_token'), 'aucun second lien à la reprise');
    }

    public function testALostLeaseNeverOverwritesTheNewOwner(): void
    {
        $this->issued();
        $stolen = $this->clock->now()->modify('+1 hour')->format('Y-m-d H:i:s');
        $this->mailer->duringSend = function () use ($stolen): void {
            $this->em->getConnection()->executeStatement('UPDATE notification_outbox SET leased_until = :l', ['l' => $stolen]);
        };

        self::assertSame(['lease_lost' => 1], $this->dispatch());

        self::assertSame(['RESERVED', $stolen], [$this->row()['status'], $this->row()['leased_until']]);
        self::assertSame(1, $this->rows('notification_secret'), 'secret conservé : le message n\'est pas définitif');
    }

    public function testWithoutAUsableKeyTheFirstMessageWaitsWhileNoAttemptIsConsumedAndNothingIsSent(): void
    {
        $this->issued();
        $none = NotificationKit::provider(null, null);

        self::assertSame(['waiting_crypto' => 1], $this->dispatch($none));

        $row = $this->row();
        self::assertSame(['PENDING', '0', 'CRYPTO_UNAVAILABLE', null], [$row['status'], (string) $row['attempts'], $row['last_error_code'], $row['first_attempt_at']]);
        self::assertSame([0, 0, 0], [$this->mailer->attempts, $this->rows('permit_access_token'), $this->rows('notification_secret')]);

        $this->clock->sleep(3600);
        self::assertSame(['sent' => 1], $this->dispatch());
        self::assertSame('SENT', $this->row()['status']);
    }

    public function testAfterSevenDaysWithoutAKeyTheMessageFailsWithoutAnyLink(): void
    {
        $this->issued(mode: DurationMode::UntilClosure);
        $none = NotificationKit::provider(null, null);
        $this->dispatch($none);
        $this->clock->sleep(7 * 86400);

        self::assertSame(['abandoned' => 1], $this->dispatch($none));

        self::assertSame(['ABANDONED', 'CRYPTO_UNAVAILABLE_EXPIRED'], [$this->row()['status'], $this->row()['last_error_code']]);
        self::assertSame([0, 0, 0], [$this->mailer->attempts, $this->rows('permit_access_token'), $this->rows('notification_secret')]);
    }

    public function testTheKeyDisappearingAfterTheLinkWasPreparedReleasesTheMessageWithoutConsumingTheAttempt(): void
    {
        $id = $this->issued();
        NotificationKit::provisioner(self::getContainer(), $this->keys, $this->clock)->provision($id);

        self::assertSame(['waiting_crypto' => 1], $this->dispatch(NotificationKit::provider(null, null)));

        self::assertSame(['PENDING', '0', 'CRYPTO_UNAVAILABLE'], [$this->row()['status'], (string) $this->row()['attempts'], $this->row()['last_error_code']]);
        self::assertNull($this->row()['first_attempt_at']);
        self::assertSame(['sent' => 1], $this->dispatch());
        self::assertSame(1, $this->rows('permit_access_token'));
    }

    public function testAnExpiredSecretFailsTheMessageAndRevokesItsNeverTransmittedLink(): void
    {
        $id = $this->issued();
        NotificationKit::provisioner(self::getContainer(), $this->keys, $this->clock)->provision($id);
        $this->sql('UPDATE notification_outbox SET available_at = :later', ['later' => $this->clock->now()->modify('+5 days')->format('Y-m-d H:i:s')]);
        $this->clock->sleep(48 * 3600);

        $report = NotificationKit::dispatcher(self::getContainer(), $this->keys, $this->clock, $this->mailer, $this->logger)->run();

        self::assertSame([], $report->outcomes);
        self::assertSame(['purged' => 1, 'revoked' => 1, 'expired' => 1], $report->purge);
        self::assertSame(['ABANDONED', 'SECRET_EXPIRED'], [$this->row()['status'], $this->row()['last_error_code']]);
        self::assertSame(0, $this->mailer->attempts);
    }

    public function testARetryWithoutItsKeptSecretIsAbandonedAndNeverCreatesANewLink(): void
    {
        $this->issued();
        $this->mailer->script(RecordingMailer::smtpFailure(451));
        $this->dispatch();
        $this->sql('DELETE FROM notification_secret');
        $this->clock->sleep(60);

        self::assertSame(['abandoned' => 1], $this->dispatch());

        self::assertSame(['ABANDONED', 'SECRET_UNAVAILABLE', 1], [$this->row()['status'], $this->row()['last_error_code'], $this->mailer->attempts]);
        self::assertSame(1, $this->rows('permit_access_token'), 'jamais de nouveau lien lors d\'un réessai');
    }

    public function testARevokedPermitReceivesNoMessageAndNoLinkIsCreated(): void
    {
        $this->issued();
        $this->sql('UPDATE permit_authorization SET revoked_at = :now', ['now' => $this->now()]);

        self::assertSame(['not_relevant' => 1], $this->dispatch());

        self::assertSame(['ABANDONED', 'NOT_RELEVANT'], [$this->row()['status'], $this->row()['last_error_code']]);
        self::assertSame([0, 0, 0], [$this->mailer->attempts, $this->rows('permit_access_token'), $this->rows('notification_secret')]);
    }

    public function testALinkRevokedByAnAgentBeforeSendingIsNeverAnnounced(): void
    {
        $id = $this->issued();
        NotificationKit::provisioner(self::getContainer(), $this->keys, $this->clock)->provision($id);
        $this->sql('UPDATE permit_access_token SET revoked_at = :now', ['now' => $this->now()]);

        self::assertSame(['abandoned' => 1], $this->dispatch());

        self::assertSame(['ABANDONED', 'LINK_REVOKED', 0], [$this->row()['status'], $this->row()['last_error_code'], $this->mailer->attempts]);
        self::assertSame(0, $this->rows('notification_secret'));
    }

    public function testARevocationDuringTheSmtpCallIsTheDocumentedResidualWindow(): void
    {
        $this->issued();
        $this->mailer->duringSend = function (): void {
            $this->em->getConnection()->executeStatement('UPDATE permit_authorization SET revoked_at = :now', ['now' => $this->now()]);
        };

        // Aucun verrou métier n'est tenu pendant l'appel SMTP : le message déjà parti reste valable ; la page du lien affiche les droits courants.
        self::assertSame(['sent' => 1], $this->dispatch());
        self::assertSame(1, $this->mailer->attempts);

        $this->mailer->duringSend = null;
        self::assertSame([], $this->dispatch(), 'jamais renvoyé');
    }

    public function testAnAddressRemovedBeforeSendingMovesTheMessageToNoRecipientWithoutAnyLink(): void
    {
        $this->issued();
        $this->sql('UPDATE request_permit_request SET email = NULL');

        self::assertSame(['no_recipient' => 1], $this->dispatch());

        self::assertSame(['NO_RECIPIENT', 0, 0], [$this->row()['status'], $this->mailer->attempts, $this->rows('permit_access_token')]);
        $this->sql('UPDATE request_permit_request SET email = \'tardif@exemple.fr\'');
        self::assertSame([], $this->dispatch(), 'une adresse corrigée plus tard ne provoque aucun envoi');
    }

    public function testAMessageAlreadyProcessedIsNeverSentAgain(): void
    {
        $id = $this->issued();
        $this->dispatch();

        self::assertSame('skipped', NotificationKit::dispatcher(self::getContainer(), $this->keys, $this->clock, $this->mailer, $this->logger)->dispatchOne($id));
        self::assertCount(1, $this->mailer->sent);
    }

    public function testNothingSecretOrPersonalReachesTheLogsTheAuditOrTheOutbox(): void
    {
        $this->issued();
        $this->mailer->script(RecordingMailer::smtpFailure(451), RecordingMailer::smtpFailure(550));
        $this->dispatch();
        $this->clock->sleep(60);
        $this->dispatch();
        $secretHash = $this->mailer->tried[0]['secretHash'];
        preg_match('#/laissez-passer/([A-Za-z0-9_-]{43})#', $this->mailer->tried[0]['text'], $m);
        $secret = $m[1];

        $dump = json_encode([
            $this->logger->lines,
            $this->em->getConnection()->fetchAllAssociative('SELECT * FROM audit_log'),
            $this->em->getConnection()->fetchAllAssociative('SELECT * FROM notification_outbox'),
            $this->em->getConnection()->fetchAllAssociative('SELECT * FROM notification_secret'),
        ], \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR);
        foreach ([$secret, (string) $secretHash, 'lea@exemple.fr', 'marie@exemple.fr', self::PLATE, self::ADDRESS] as $leak) {
            self::assertStringNotContainsString($leak, $dump);
        }
        self::assertNotSame([], $this->logger->lines, 'les échecs sont journalisés par code seulement');
    }

    /**
     * Non-régression (trouvée par la recette navigateur) : avec une horloge RÉELLE (microsecondes) le bail relu en base (`DATETIME`, à la seconde) ne
     * correspondait plus : le message était envoyé mais restait RESERVED (`lease_lost`), son secret n'était pas supprimé et il serait renvoyé.
     */
    public function testARealClockWithMicrosecondsStillMarksTheMessageSentAndPurgesItsSecret(): void
    {
        $this->issued();
        $realClock = new \Symfony\Component\Clock\MockClock('2026-06-12 09:00:00.654321 UTC');

        $outcomes = NotificationKit::dispatcher(self::getContainer(), $this->keys, $realClock, $this->mailer, $this->logger)->run()->outcomes;

        self::assertSame(['sent' => 1], $outcomes);
        self::assertSame('SENT', $this->row()['status']);
        self::assertSame(0, $this->rows('notification_secret'));
        self::assertCount(1, $this->mailer->sent);
    }

    public function testALinkThatExpiredBeforeTheSendIsNeverAnnounced(): void
    {
        $id = $this->issued(mode: DurationMode::UntilClosure);
        NotificationKit::provisioner(self::getContainer(), $this->keys, $this->clock)->provision($id);
        // Le lien automatique existe ; sa durée de vie (fin de l'événement + 30 jours) est dépassée avant le premier essai d'envoi.
        $this->clock->sleep(60 * 86400);

        self::assertSame(['not_relevant' => 1], $this->dispatch());

        self::assertSame(['ABANDONED', 'NOT_RELEVANT', 0], [$this->row()['status'], $this->row()['last_error_code'], $this->mailer->attempts]);
        self::assertSame(0, $this->rows('notification_secret'));
        self::assertNotNull($this->em->getConnection()->fetchOne('SELECT revoked_at FROM permit_access_token'), 'lien automatique jamais transmis : révoqué');
    }
}
