<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification;

use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\MunicipalInstructionService;
use App\Request\Application\RequestSubmissionService;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\TravelMode;
use App\Tests\Support\ClosedEventScenarioTestCase;
use App\Tests\Support\NotificationKit;
use App\Tests\Support\ParallelPhp;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Concurrence RÉELLE (processus PHP sur MySQL) entre la clôture et plusieurs distributeurs (LOT 05.6.2, P3 et P7) : jamais d'interblocage, jamais de ligne
 * en attente ou réservée après la clôture, aucun doublon, chaque message parti correspond à une ligne `SENT`. Le nombre de messages dont l'entrée dans
 * l'envoi SMTP suit la validation de la clôture est MESURÉ (fenêtre irréductible : aucun verrou n'est tenu pendant l'échange SMTP) ; la borne vérifiée est
 * « au plus un message par distributeur actif », jamais « un seul e-mail ». Un distributeur lancé APRÈS la clôture n'envoie rien.
 */
final class EventClosureConcurrencyTest extends ClosedEventScenarioTestCase
{
    private const DISTRIBUTORS = 3;

    private string $mailbox;

    protected function setUp(): void
    {
        parent::setUp();
        [$keyring, $active] = NotificationKit::newKey('k1');
        $this->mailbox = (string) tempnam(sys_get_temp_dir(), 'civipass-mbox-');
        putenv('NOTIFICATION_KEYRING_TEST='.$keyring);
        putenv('NOTIFICATION_ACTIVE_KEY_ID_TEST='.$active);
        putenv('NOTIFICATION_TEST_MAILBOX='.$this->mailbox);
        // Les processus ont une horloge simulée au 12 juin 2026 : on la ramène à l'instant réel (l'événement du scénario est à dates réelles).
        putenv('NOTIFICATION_TEST_CLOCK_OFFSET='.(time() - strtotime('2026-06-12 09:00:00 UTC')));
        putenv('NOTIFICATION_TEST_SEND_DELAY_US=200000');
    }

    protected function tearDown(): void
    {
        foreach (['NOTIFICATION_KEYRING_TEST', 'NOTIFICATION_ACTIVE_KEY_ID_TEST', 'NOTIFICATION_TEST_MAILBOX', 'NOTIFICATION_TEST_CLOCK_OFFSET', 'NOTIFICATION_TEST_SEND_DELAY_US'] as $name) {
            putenv($name);
        }
        @unlink($this->mailbox);
        parent::tearDown();
    }

    private function approveMore(int $count): void
    {
        $instructor = $this->managed($this->instructor);
        for ($i = 0; $i < $count; ++$i) {
            $id = static::getContainer()->get(RequestSubmissionService::class)->submitPublic(
                new SubmitRequestCommand($this->event->id()->toRfc4122(), 'Dupont'.$i, 'Léa', null, 'lea'.$i.'@exemple.fr', null, 'private_person', 'home', null, TravelMode::Vehicle, [sprintf('QW-%03d-ER', 100 + $i)], [$this->organizerZone->id()->toRfc4122()], true),
                sprintf('concurrency-%016d', $i),
            )->requestId;
            static::getContainer()->get(MunicipalInstructionService::class)->approve($instructor, $this->organizerId, $id, ApprovalDuration::untilClosure());
        }
        $this->em()->clear();
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

    /** @return list<array{id: string, pid: int, entered: float}> */
    private function mailbox(): array
    {
        return array_values(array_map(static fn (string $l): array => json_decode($l, true, 512, \JSON_THROW_ON_ERROR), array_filter(explode("\n", (string) file_get_contents($this->mailbox)))));
    }

    /** @return iterable<string, array{int}> délai (µs) avant la clôture : avant, pendant et après les premiers envois */
    public static function closureDelays(): iterable
    {
        yield 'clôture immédiate' => [0];
        yield 'clôture pendant les premiers envois' => [250000];
        yield 'clôture au milieu de la distribution' => [700000];
    }

    #[DataProvider('closureDelays')]
    public function testClosureRacingSeveralDistributorsNeverLeavesAPendingMessageAndTheLateSendsAreBounded(int $delay): void
    {
        $this->approveMore(8);
        // Graines : une réservation à bail expiré (processus mort) et un message ordinaire pas encore échu.
        $seed = $this->em()->getConnection()->fetchFirstColumn('SELECT LOWER(HEX(id)) FROM notification_outbox WHERE status = \'PENDING\' ORDER BY id DESC LIMIT 2');
        $this->sql('UPDATE notification_outbox SET status = \'RESERVED\', attempts = 1, first_attempt_at = UTC_TIMESTAMP(), leased_until = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE) WHERE id = UNHEX(:id)', ['id' => $seed[0]]);
        $this->sql('UPDATE notification_outbox SET available_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY) WHERE id = UNHEX(:id)', ['id' => $seed[1]]);
        $admin = $this->admin->id()->toRfc4122();
        $eventId = $this->event->id()->toRfc4122();

        $commands = array_merge(array_fill(0, self::DISTRIBUTORS, ['dispatch']), [['close', $admin, $eventId, (string) $delay]]);
        $results = $this->decode(ParallelPhp::run('notification-process.php', $commands));

        $committedAt = (float) $results[self::DISTRIBUTORS]['committedAt'];
        $mails = $this->mailbox();
        $connection = $this->em()->getConnection();
        self::assertSame('CLOSED', $connection->fetchOne('SELECT status FROM event_event'));
        $left = $connection->fetchAllAssociative('SELECT HEX(id) AS id, type, status, attempts, last_error_code, leased_until, available_at, processed_at FROM notification_outbox WHERE status IN (\'PENDING\', \'RESERVED\')');
        self::assertSame([], $left, 'rien n\'est resté en attente ni réservé : '.json_encode($left).' ; clôture validée à '.$committedAt.' ; processus : '.json_encode(array_map(static fn (array $r): mixed => [$r['outcomes'] ?? $r, $r['log'] ?? []], \array_slice($results, 0, self::DISTRIBUTORS))));
        self::assertCount(\count(array_unique(array_column($mails, 'id'))), $mails, 'aucun doublon');
        self::assertSame(\count($mails), (int) $connection->fetchOne('SELECT COUNT(*) FROM notification_outbox WHERE status = \'SENT\''), 'chaque message parti est une ligne SENT (état exact)');
        $late = \count(array_filter($mails, static fn (array $m): bool => $m['entered'] > $committedAt));
        self::assertLessThanOrEqual(self::DISTRIBUTORS, $late, 'au plus un message en vol par distributeur actif (mesuré ; fenêtre SMTP irréductible)');
        fwrite(\STDERR, sprintf("[mesure] délai %d µs : %d message(s) parti(s), dont %d entré(s) dans l'envoi après la validation de la clôture.\n", $delay, \count($mails), $late));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM notification_secret'), 'aucun secret chiffré ne subsiste');

        // Stabilisation : un passage ultérieur (bails échus) ne laisse AUCUNE ligne abandonnée en attente ou réservée. Les 60 passes de stabilité du 11 novembre 2026
        // ne sont pas une preuve d'absence d'interblocage ; la reprise (bail 10 minutes puis garde G1) en est le filet.
        putenv('NOTIFICATION_TEST_CLOCK_OFFSET='.(time() - strtotime('2026-06-12 09:00:00 UTC') + 700));
        $this->decode(ParallelPhp::run('notification-process.php', [['dispatch']]));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM notification_outbox WHERE status IN (\'PENDING\', \'RESERVED\')'), 'aucune ligne PENDING ou RESERVED abandonnée après stabilisation');
        putenv('NOTIFICATION_TEST_CLOCK_OFFSET='.(time() - strtotime('2026-06-12 09:00:00 UTC')));

        // Un distributeur lancé après la clôture n'envoie rien.
        $sentBefore = \count($mails);
        $this->decode(ParallelPhp::run('notification-process.php', [['dispatch']]));
        self::assertCount($sentBefore, $this->mailbox(), 'aucune prise en charge après la clôture');
    }

    public function testClosureWaitsForARevocationHoldingItsLocksThenCancelsTheMessageItProduced(): void
    {
        $this->approveMore(1);
        $marker = (string) tempnam(sys_get_temp_dir(), 'civipass-hold-');
        unlink($marker);
        $revocation = ParallelPhp::start('revoke-authorization.php', [$this->instructor->id()->toRfc4122(), $this->organizerId, $this->requestId, 'PUBLIC_SAFETY', $marker.':1.5']);
        $deadline = microtime(true) + 20;
        while (!file_exists($marker) && microtime(true) < $deadline) {
            usleep(20000);
        }
        self::assertFileExists($marker, 'la révocation tient ses verrous');

        // La clôture démarre PENDANT que la révocation tient les verrous : elle attend, puis voit la ligne prioritaire validée par la révocation.
        $started = microtime(true);
        $closure = $this->decode(ParallelPhp::run('notification-process.php', [['close', $this->admin->id()->toRfc4122(), $this->event->id()->toRfc4122(), '0']]));
        $revoked = ParallelPhp::finish($revocation);
        @unlink($marker);

        self::assertGreaterThan(0.5, (float) $closure[0]['committedAt'] - $started, 'la clôture a attendu les verrous de la révocation (ordre événement → participations → lignes)');
        $decoded = json_decode($revoked['stdout'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertTrue($decoded['ok'], $revoked['stdout']);
        $connection = $this->em()->getConnection();
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM notification_outbox WHERE type = \'AUTHORIZATION_REVOKED\''), 'le message prioritaire a bien été produit');
        self::assertSame('SUPPRESSED/EVENT_CLOSED', $connection->fetchOne('SELECT CONCAT(status, \'/\', last_error_code) FROM notification_outbox WHERE type = \'AUTHORIZATION_REVOKED\''));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM notification_outbox WHERE status IN (\'PENDING\', \'RESERVED\')'));
    }
}
