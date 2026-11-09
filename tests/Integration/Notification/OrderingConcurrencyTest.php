<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification;

use App\Identity\Domain\User;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\RequestSubmissionService;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\DurationMode;
use App\Request\Domain\TravelMode;
use App\Tests\Support\MunicipalDecisionTestCase;
use App\Tests\Support\NotificationKit;
use App\Tests\Support\ParallelPhp;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;

/**
 * Concurrence réelle (processus PHP sur MySQL) de l'ordonnancement, du regroupement et de l'envoi manuel (LOT 05.3.4) : une échéance commune et
 * déterministe pour des changements simultanés, un seul message manuel pour un double clic ou des requêtes concurrentes, une extension qui attend
 * un premier message planté puis se supprime sans jamais précéder la mise à disposition. Horloge des processus : 12 juin 2026 09:00 UTC.
 */
#[Group('requires-request-schema')]
final class OrderingConcurrencyTest extends MunicipalDecisionTestCase
{
    private string $mailbox;

    protected function setUp(): void
    {
        parent::setUp();
        [$keyring, $active] = NotificationKit::newKey('k1');
        $this->mailbox = (string) tempnam(sys_get_temp_dir(), 'civipass-mbox-');
        putenv('NOTIFICATION_KEYRING_TEST='.$keyring);
        putenv('NOTIFICATION_ACTIVE_KEY_ID_TEST='.$active);
        putenv('NOTIFICATION_TEST_MAILBOX='.$this->mailbox);
    }

    protected function tearDown(): void
    {
        foreach (['NOTIFICATION_KEYRING_TEST', 'NOTIFICATION_ACTIVE_KEY_ID_TEST', 'NOTIFICATION_TEST_MAILBOX', 'NOTIFICATION_TEST_CLOCK_OFFSET'] as $name) {
            putenv($name);
        }
        @unlink($this->mailbox);
        parent::tearDown();
    }

    private function submitRequest(): string
    {
        return $this->service(RequestSubmissionService::class)->submitPublic(new SubmitRequestCommand(
            $this->event->id()->toRfc4122(),
            'Dupont',
            'Léa',
            null,
            'lea@exemple.fr',
            null,
            'private_person',
            'home',
            null,
            TravelMode::Vehicle,
            ['QW-123-ER'],
            [$this->organizerZone->id()->toRfc4122(), $this->partnerZone->id()->toRfc4122()],
            true,
        ), $this->key())->requestId;
    }

    private function approveOrganizer(string $request): void
    {
        $user = $this->em->find(User::class, $this->instructor->id()) ?? $this->instructor;
        $this->instruction()->approve($user, $this->organizer->id()->toRfc4122(), $request, ApprovalDuration::fixed(DurationMode::H72));
    }

    /** @return list<array{id: string, secretHash: ?string, pid: int}> */
    private function mailbox(): array
    {
        return array_values(array_map(static fn (string $l): array => json_decode($l, true, 512, \JSON_THROW_ON_ERROR), array_filter(explode("\n", (string) file_get_contents($this->mailbox)))));
    }

    /**
     * @param list<array{status: int, stdout: string, stderr: string}> $raw
     *
     * @return list<array<string, mixed>>
     */
    private function decode(array $raw, bool $mustSucceed = true): array
    {
        return array_map(static function (array $r) use ($mustSucceed): array {
            $decoded = json_decode($r['stdout'], true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded, $r['stderr']);
            if ($mustSucceed) {
                self::assertTrue($decoded['ok'], json_encode($decoded).' '.$r['stderr']);
            }

            return $decoded;
        }, $raw);
    }

    private function permitId(): string
    {
        return Uuid::fromBinary((string) $this->em->getConnection()->fetchOne('SELECT id FROM permit_permit'))->toRfc4122();
    }

    public function testTwoCommunesApprovingAtTheSameTimeProduceOneFirstMessageAndOneExtensionThatIsThenSuppressed(): void
    {
        $request = $this->submitRequest();
        $results = ParallelPhp::run('decide-request.php', [
            [$this->instructor->id()->toRfc4122(), $this->organizer->id()->toRfc4122(), $request, 'approve', '{}'],
            [$this->partnerInstructor->id()->toRfc4122(), $this->partner->id()->toRfc4122(), $request, 'approve', '{}'],
        ]);
        foreach ($results as $r) {
            self::assertTrue(json_decode($r['stdout'], true)['ok'] ?? false, $r['stdout'].$r['stderr']);
        }
        self::assertSame(['AUTHORIZATION_ADDED', 'PERMIT_ISSUED'], $this->em->getConnection()->fetchFirstColumn('SELECT type FROM notification_outbox ORDER BY type'));

        putenv('NOTIFICATION_TEST_CLOCK_OFFSET=700');
        $this->decode(ParallelPhp::run('notification-process.php', [['dispatch'], ['dispatch']]));

        self::assertCount(1, $this->mailbox(), 'un seul message : le premier annonce déjà les deux communes');
        self::assertSame(['SUPPRESSED', 'SENT'], $this->em->getConnection()->fetchFirstColumn('SELECT status FROM notification_outbox ORDER BY type'));
        self::assertSame(1, $this->rows('permit_access_token'));
    }

    public function testSixConcurrentChangesShareOneDeterministicDueDate(): void
    {
        $this->approveOrganizer($this->submitRequest());
        $permit = $this->permitId();
        $times = ['2026-06-12 09:00:00', '2026-06-12 09:03:00', '2026-06-12 09:41:00', '2026-06-12 09:07:00', '2026-06-12 09:20:00', '2026-06-12 09:12:00'];

        $this->decode(ParallelPhp::run('notification-process.php', array_map(static fn (string $t): array => ['record', $permit, $t], $times)));

        $pending = $this->em->getConnection()->fetchFirstColumn('SELECT available_at FROM notification_outbox WHERE type = \'AUTHORIZATION_ADDED\' ORDER BY id');
        self::assertCount(6, $pending);
        self::assertSame(['2026-06-12 09:51:00'], array_values(array_unique($pending)), 'min(dernier 09:41 + 10 min, premier 09:00 + 60 min) quel que soit l\'ordre d\'arrivée');
    }

    public function testADoubleClickAndConcurrentRequestsProduceOneManualMessage(): void
    {
        $request = $this->submitRequest();
        $this->approveOrganizer($request);
        $this->decode(ParallelPhp::run('notification-process.php', [['dispatch']]));
        $agent = $this->instructor->id()->toRfc4122();
        $commune = $this->organizer->id()->toRfc4122();
        $nonce = Uuid::v7()->toRfc4122();

        $same = $this->decode(ParallelPhp::run('notification-process.php', array_fill(0, 3, ['manual', $agent, $commune, $request, $nonce])));
        self::assertEqualsCanonicalizing(['CREATED', 'DUPLICATE', 'DUPLICATE'], array_column($same, 'outcome'), 'double clic : une seule intention');
        self::assertSame(1, $this->rows('notification_outbox WHERE deduplication_key LIKE \'PERMIT_ISSUED_MANUAL:%\''));

        $this->decode(ParallelPhp::run('notification-process.php', [['dispatch']])); // le message manuel part
        putenv('NOTIFICATION_TEST_CLOCK_OFFSET=400'); // au-delà de l'écart minimal de 5 minutes
        $different = $this->decode(ParallelPhp::run('notification-process.php', [
            ['manual', $agent, $commune, $request, Uuid::v7()->toRfc4122()],
            ['manual', $agent, $commune, $request, Uuid::v7()->toRfc4122()],
            ['manual', $agent, $commune, $request, Uuid::v7()->toRfc4122()],
        ]));
        self::assertEqualsCanonicalizing(['CREATED', 'ALREADY_PENDING', 'ALREADY_PENDING'], array_column($different, 'outcome'), 'requêtes concurrentes : un seul message en attente');
        self::assertSame(2, $this->rows('notification_outbox WHERE deduplication_key LIKE \'PERMIT_ISSUED_MANUAL:%\''));
        self::assertSame(2, $this->rows('audit_log WHERE action = \'PERMIT_NOTIFICATION_REQUESTED\''));
    }

    public function testAnExtensionWaitsWhileTheFirstMessageIsCrashedThenIsSuppressedAndNeverPrecedesIt(): void
    {
        $request = $this->submitRequest();
        $this->approveOrganizer($request);
        $user = $this->em->find(User::class, $this->partnerInstructor->id()) ?? $this->partnerInstructor;
        $this->instruction()->approve($user, $this->partner->id()->toRfc4122(), $request, ApprovalDuration::fixed(DurationMode::H72));

        putenv('NOTIFICATION_TEST_CLOCK_OFFSET=700');
        $crash = ParallelPhp::run('notification-process.php', [['dispatch-crash']]);
        self::assertSame(9, $crash[0]['status']);
        self::assertCount(1, $this->mailbox());

        // Bail encore valide : le premier message est RESERVED, l'extension attend et n'est pas envoyée.
        $this->decode(ParallelPhp::run('notification-process.php', [['dispatch']]));
        self::assertCount(1, $this->mailbox());
        self::assertSame('PENDING', $this->em->getConnection()->fetchOne('SELECT status FROM notification_outbox WHERE type = \'AUTHORIZATION_ADDED\''));

        // Bail expiré : le premier message est renvoyé (doublon assumé), puis l'extension devient redondante.
        putenv('NOTIFICATION_TEST_CLOCK_OFFSET=1400');
        $this->decode(ParallelPhp::run('notification-process.php', [['dispatch']]));

        $mails = $this->mailbox();
        self::assertCount(2, $mails);
        self::assertSame($mails[0]['id'], $mails[1]['id']);
        self::assertSame($mails[0]['secretHash'], $mails[1]['secretHash']);
        self::assertSame(['SUPPRESSED', 'SENT'], $this->em->getConnection()->fetchFirstColumn('SELECT status FROM notification_outbox ORDER BY type'));
        self::assertSame(1, $this->rows('permit_access_token'));
    }

    public function testConcurrentManualRequestsRespectTheLimitsWhateverTheirNumber(): void
    {
        $request = $this->submitRequest();
        $this->approveOrganizer($request);
        $this->decode(ParallelPhp::run('notification-process.php', [['dispatch']]));
        $agent = $this->instructor->id()->toRfc4122();
        $commune = $this->organizer->id()->toRfc4122();

        $results = $this->decode(ParallelPhp::run('notification-process.php', array_map(static fn (): array => ['manual', $agent, $commune, $request, Uuid::v7()->toRfc4122()], range(1, 8))));

        $outcomes = array_count_values(array_column($results, 'outcome'));
        self::assertSame(1, $outcomes['CREATED'] ?? 0, 'huit demandes simultanées : une seule acceptée');
        self::assertSame(7, ($outcomes['ALREADY_PENDING'] ?? 0) + ($outcomes['TOO_SOON'] ?? 0));
        self::assertSame(1, $this->rows('notification_outbox WHERE deduplication_key LIKE \'PERMIT_ISSUED_MANUAL:%\''));
        self::assertSame(1, $this->rows('audit_log WHERE action = \'PERMIT_NOTIFICATION_REQUESTED\''));
    }

    public function testAManualRequestAndASecondCommuneApprovalRunTogetherWithoutDeadlock(): void
    {
        $request = $this->submitRequest();
        $this->approveOrganizer($request);
        $this->decode(ParallelPhp::run('notification-process.php', [['dispatch']]));
        putenv('NOTIFICATION_TEST_CLOCK_OFFSET=60');

        // Démarrés ensemble : la demande manuelle (verrou du laissez-passer) et l'approbation de la seconde commune (même verrou, même ordre).
        $manual = ParallelPhp::start('notification-process.php', ['manual', $this->instructor->id()->toRfc4122(), $this->organizer->id()->toRfc4122(), $request, Uuid::v7()->toRfc4122()]);
        $approval = ParallelPhp::start('decide-request.php', [$this->partnerInstructor->id()->toRfc4122(), $this->partner->id()->toRfc4122(), $request, 'approve', '{}']);
        $manualResult = $this->decode([ParallelPhp::finish($manual)])[0];
        $approvalRaw = ParallelPhp::finish($approval);

        self::assertTrue($manualResult['ok']);
        self::assertTrue(json_decode($approvalRaw['stdout'], true)['ok'] ?? false, $approvalRaw['stdout'].$approvalRaw['stderr']);
        self::assertSame(2, $this->rows('permit_authorization'));
        self::assertSame(1, $this->rows('notification_outbox WHERE deduplication_key LIKE \'PERMIT_ISSUED_MANUAL:%\''));
        self::assertSame(1, $this->rows('notification_outbox WHERE type = \'AUTHORIZATION_ADDED\''));
    }

    public function testPurgingTheOutboxWhileDistributorsRunNeverTouchesActiveMessages(): void
    {
        $this->approveOrganizer($this->submitRequest());
        $this->approveOrganizer($this->submitRequest());
        $old = '2026-01-01 00:00:00';
        for ($i = 0; $i < 12; ++$i) {
            $this->em->getConnection()->executeStatement('INSERT INTO notification_outbox (id, status, attempts, deduplication_key, type, permit_id, authorization_id, occurred_at, available_at, processed_at) VALUES (:id, \'ABANDONED\', 1, :k, \'AUTHORIZATION_ADDED\', :p, :a, :t, :t, :t)', ['id' => Uuid::v7()->toBinary(), 'k' => 'AUTHORIZATION_ADDED:old-'.$i, 'p' => Uuid::v7()->toBinary(), 'a' => Uuid::v7()->toBinary(), 't' => $old]);
        }

        $results = $this->decode(ParallelPhp::run('notification-process.php', [['dispatch'], ['purge-outbox'], ['dispatch'], ['purge-outbox'], ['purge-outbox']]));

        $purged = array_sum(array_map(static fn (array $r): int => (int) ($r['report']['purged'] ?? 0), $results));
        self::assertSame(12, $purged, 'chaque ancien message est supprimé une seule fois');
        self::assertSame(['SENT', 'SENT'], $this->em->getConnection()->fetchFirstColumn('SELECT status FROM notification_outbox ORDER BY id'), 'les messages actifs ont été traités, jamais supprimés');
        self::assertCount(2, $this->mailbox());
    }
}
