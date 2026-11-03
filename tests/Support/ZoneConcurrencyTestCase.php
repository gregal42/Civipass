<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationConflict;
use App\Shared\Domain\OperationNotAllowed;

/**
 * Base des tests de concurrence des zones (processus PHP sur MySQL réel). Pour maîtriser l'ordre, la première opération
 * TIENT ses verrous (attente avant le commit, SleepingAuditTrail) et signale par un fichier qu'elle les tient ; la
 * seconde démarre alors, doit attendre, puis voit l'état complet de la première (jamais un état partiel ni périmé).
 */
abstract class ZoneConcurrencyTestCase extends ZoneServiceTestCase
{
    protected const HOLD_SECONDS = 2.5;

    /** @var list<string> */
    private array $markers = [];

    protected function tearDown(): void
    {
        foreach ($this->markers as $marker) {
            @unlink($marker);
        }
        parent::tearDown();
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{resource, array<int, resource>, string}
     */
    protected function start(array $arguments): array
    {
        return ParallelPhp::start('zone-action.php', $arguments);
    }

    /**
     * Démarre une opération qui tient ses verrous et attend qu'elle les tienne réellement.
     *
     * @param array<string, mixed>|null $payload
     *
     * @return array{resource, array<int, resource>, string}
     */
    protected function startHolding(string $userId, string $action, string $target, ?array $payload = null): array
    {
        $marker = tempnam(sys_get_temp_dir(), 'civipass-hold-');
        self::assertNotFalse($marker);
        unlink($marker);
        $this->markers[] = $marker;
        $process = $this->start([$userId, $action, $target, json_encode($payload ?? [], \JSON_THROW_ON_ERROR), $marker.':'.self::HOLD_SECONDS]);
        $deadline = microtime(true) + 8;
        while (!is_file($marker) && microtime(true) < $deadline) {
            usleep(20_000);
        }
        if (!is_file($marker)) {
            $raw = ParallelPhp::finish($process);
            self::fail('L\'opération tenante n\'a pas pris ses verrous : '.$raw['stdout'].' '.substr($raw['stderr'], -500));
        }

        return $process;
    }

    /**
     * @param array<string, mixed>|null $payload
     *
     * @return array{resource, array<int, resource>, string}
     */
    protected function startSecond(string $userId, string $action, string $target, ?array $payload = null): array
    {
        return $this->start([$userId, $action, $target, json_encode($payload ?? [], \JSON_THROW_ON_ERROR)]);
    }

    /**
     * @param array{resource, array<int, resource>, string} $process
     *
     * @return array<string, mixed>
     */
    protected function outcome(array $process): array
    {
        $raw = ParallelPhp::finish($process);
        $decoded = json_decode($raw['stdout'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded, $raw['stderr']);
        if (false === $decoded['ok']) {
            self::assertContains($decoded['error'], [OperationNotAllowed::class, DomainRuleViolation::class, OperationConflict::class], 'refus contrôlé, jamais une erreur technique : '.json_encode($decoded));
        }

        return $decoded;
    }

    /**
     * @param array{resource, array<int, resource>, string} $process
     */
    protected function assertBlocked(array $process): void
    {
        usleep(600_000);
        self::assertTrue(ParallelPhp::isRunning($process), 'la seconde opération doit attendre le verrou de la première');
    }

    protected function org(): string
    {
        return $this->w->adminO->id()->toRfc4122();
    }

    protected function adminB(): string
    {
        return $this->w->adminB->id()->toRfc4122();
    }

    /**
     * @return array<string, string>
     */
    protected function slot(string $date = '2026-12-02'): array
    {
        return ['kind' => 'PUNCTUAL', 'startDate' => $date, 'startTime' => '09:00', 'endTime' => '17:00'];
    }
}
