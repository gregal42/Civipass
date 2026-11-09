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

/**
 * Concurrence réelle (processus PHP sur MySQL) du distributeur (LOT 05.3.3) : plusieurs distributeurs simultanés n'envoient chaque message qu'une
 * fois ; un arrêt brutal APRÈS l'acceptation SMTP provoque un doublon de message (même Message-ID, même lien) mais jamais un nouveau lien.
 */
#[Group('requires-request-schema')]
final class DispatcherConcurrencyTest extends MunicipalDecisionTestCase
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
        foreach (['NOTIFICATION_KEYRING_TEST', 'NOTIFICATION_ACTIVE_KEY_ID_TEST', 'NOTIFICATION_TEST_MAILBOX', 'NOTIFICATION_TEST_CLOCK_OFFSET', 'NOTIFICATION_TEST_SEND_DELAY_US'] as $name) {
            putenv($name);
        }
        @unlink($this->mailbox);
        parent::tearDown();
    }

    private function approve(): void
    {
        $user = $this->em->find(User::class, $this->instructor->id()) ?? $this->instructor;
        $request = $this->service(RequestSubmissionService::class)->submitPublic(new SubmitRequestCommand(
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
            [$this->organizerZone->id()->toRfc4122()],
            true,
        ), $this->key())->requestId;
        $this->instruction()->approve($user, $this->organizer->id()->toRfc4122(), $request, ApprovalDuration::fixed(DurationMode::H72));
    }

    /** @return list<array{id: string, secretHash: ?string, pid: int}> */
    private function mailbox(): array
    {
        $lines = array_filter(explode("\n", (string) file_get_contents($this->mailbox)));

        return array_values(array_map(static fn (string $l): array => json_decode($l, true, 512, \JSON_THROW_ON_ERROR), $lines));
    }

    /**
     * @param list<array{status: int, stdout: string, stderr: string}> $raw
     *
     * @return list<array<string, mixed>>
     */
    private function decode(array $raw): array
    {
        return array_map(static function (array $r): array {
            $decoded = json_decode($r['stdout'], true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded, $r['stderr']);
            self::assertTrue($decoded['ok'], json_encode($decoded).' '.$r['stderr']);

            return $decoded;
        }, $raw);
    }

    public function testFourSimultaneousDistributorsSendEachOfSixMessagesExactlyOnce(): void
    {
        for ($i = 0; $i < 6; ++$i) {
            $this->approve();
        }
        putenv('NOTIFICATION_TEST_SEND_DELAY_US=150000');

        $results = $this->decode(ParallelPhp::run('notification-process.php', array_fill(0, 4, ['dispatch'])));

        $sent = array_sum(array_map(static fn (array $r): int => (int) ($r['outcomes']['sent'] ?? 0), $results));
        self::assertSame(6, $sent, 'chaque message est envoyé par un seul distributeur');
        $mails = $this->mailbox();
        self::assertCount(6, $mails);
        self::assertCount(6, array_unique(array_column($mails, 'id')), 'aucun doublon');
        self::assertCount(6, array_unique(array_column($mails, 'secretHash')));
        self::assertSame([6, 6, 0], [$this->rows('notification_outbox WHERE status = \'SENT\' AND attempts = 1'), $this->rows('permit_access_token'), $this->rows('notification_secret')]);
        self::assertSame(0, $this->rows('permit_access_token WHERE revoked_at IS NOT NULL'));
    }

    public function testACrashAfterSmtpAcceptanceCausesADuplicateMessageButNeverANewLink(): void
    {
        $this->approve();

        $crash = ParallelPhp::run('notification-process.php', [['dispatch-crash']]);
        self::assertSame(9, $crash[0]['status'], 'le processus est mort après l\'acceptation SMTP');
        self::assertCount(1, $this->mailbox());
        self::assertSame(['RESERVED', 1], [$this->em->getConnection()->fetchOne('SELECT status FROM notification_outbox'), $this->rows('notification_secret')]);

        // Bail encore valide : un autre distributeur ne reprend pas le message.
        $this->decode(ParallelPhp::run('notification-process.php', [['dispatch']]));
        self::assertCount(1, $this->mailbox());

        // Bail expiré : renvoi (doublon assumé), même Message-ID, même lien, jamais un nouveau lien.
        putenv('NOTIFICATION_TEST_CLOCK_OFFSET=660');
        $this->decode(ParallelPhp::run('notification-process.php', [['dispatch']]));

        $mails = $this->mailbox();
        self::assertCount(2, $mails, 'garantie au moins une fois : un doublon est possible');
        self::assertSame($mails[0]['id'], $mails[1]['id']);
        self::assertSame($mails[0]['secretHash'], $mails[1]['secretHash'], 'même lien');
        self::assertSame(1, $this->rows('permit_access_token'), 'aucun nouveau lien');
        self::assertSame($mails[0]['secretHash'], $this->em->getConnection()->fetchOne('SELECT secret_hash FROM permit_access_token'));
        self::assertSame(['SENT', '2', 0], [$this->em->getConnection()->fetchOne('SELECT status FROM notification_outbox'), (string) $this->em->getConnection()->fetchOne('SELECT attempts FROM notification_outbox'), $this->rows('notification_secret')]);
    }
}
