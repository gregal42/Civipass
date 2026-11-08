<?php

declare(strict_types=1);

namespace App\Tests\Integration\Request;

use App\Shared\Domain\ConflictCause;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationConflict;
use App\Tests\Support\MunicipalDecisionTestCase;
use App\Tests\Support\ParallelPhp;
use PHPUnit\Framework\Attributes\Group;

/**
 * Concurrence réelle (processus PHP sur MySQL) des décisions d'une même part. Comme pour les zones, la première opération TIENT ses
 * verrous (attente avant le commit) pour que l'ordre soit maîtrisé : la seconde doit attendre, puis voir l'état complet de la
 * première — jamais un état partiel ni périmé.
 */
#[Group('requires-request-schema')]
final class MunicipalDecisionConcurrencyTest extends MunicipalDecisionTestCase
{
    private const HOLD_SECONDS = 2.5;

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
     * @param array<string, string> $payload
     *
     * @return array{resource, array<int, resource>, string}
     */
    private function start(string $action, string $requestId, array $payload = [], bool $hold = false): array
    {
        $arguments = [$this->instructor->id()->toRfc4122(), $this->organizer->id()->toRfc4122(), $requestId, $action, json_encode($payload, \JSON_THROW_ON_ERROR)];
        $marker = null;
        if ($hold) {
            $marker = tempnam(sys_get_temp_dir(), 'civipass-hold-');
            self::assertNotFalse($marker);
            unlink($marker);
            $this->markers[] = $marker;
            $arguments[] = $marker.':'.self::HOLD_SECONDS;
        }
        $process = ParallelPhp::start('decide-request.php', $arguments);
        if (null !== $marker) {
            $deadline = microtime(true) + 8;
            while (!is_file($marker) && microtime(true) < $deadline) {
                usleep(20_000);
            }
            if (!is_file($marker)) {
                $raw = ParallelPhp::finish($process);
                self::fail('L\'opération tenante n\'a pas pris ses verrous : '.$raw['stdout'].' '.substr($raw['stderr'], -500));
            }
        }

        return $process;
    }

    /**
     * @param array{resource, array<int, resource>, string} $process
     *
     * @return array<string, mixed>
     */
    private function outcome(array $process): array
    {
        $raw = ParallelPhp::finish($process);
        $decoded = json_decode($raw['stdout'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded, $raw['stderr']);
        if (false === $decoded['ok']) {
            self::assertContains($decoded['error'], [DomainRuleViolation::class, \App\Shared\Domain\OperationNotAllowed::class, OperationConflict::class], 'refus contrôlé, jamais une erreur technique : '.json_encode($decoded));
        }

        return $decoded;
    }

    public function testAnApprovalHoldingItsLocksMakesTheConcurrentRefusalWaitThenFailWithAControlledConflict(): void
    {
        $requestId = $this->submit([$this->organizerZone]);

        $first = $this->start('approve', $requestId, [], hold: true);
        $second = $this->start('refuse', $requestId, ['reason' => 'duplicate']);
        usleep(600_000);
        self::assertTrue(ParallelPhp::isRunning($second), 'la seconde décision doit attendre les verrous de la première');

        $a = $this->outcome($first);
        $b = $this->outcome($second);

        self::assertTrue($a['ok']);
        self::assertFalse($b['ok']);
        self::assertSame(OperationConflict::class, $b['error']);
        self::assertSame(ConflictCause::StaleState->value, $b['cause']);
        self::assertSame(1, $this->rows('request_permit_request_decision'));
        self::assertSame('APPROVED', $this->partStatus($requestId, $this->organizer));
        self::assertCount(1, $this->decisionAudits());
    }

    public function testTwoSimultaneousApprovalsOfTheSamePartLeaveExactlyOneDecision(): void
    {
        $requestId = $this->submit([$this->organizerZone]);

        $results = array_map($this->outcome(...), [$this->start('approve', $requestId, ['mode' => 'H24']), $this->start('approve', $requestId, ['mode' => 'H72'])]);

        self::assertSame([false, true], array_map(static fn (array $r): bool => $r['ok'], $this->sortByOk($results)));
        $failed = array_values(array_filter($results, static fn (array $r): bool => !$r['ok']))[0];
        self::assertSame(OperationConflict::class, $failed['error']);
        self::assertSame(1, $this->rows('request_permit_request_decision'));
        self::assertCount(1, $this->decisionAudits());
    }

    public function testAZoneSuspensionHoldingTheParticipationLockIsSeenByTheWaitingApproval(): void
    {
        $requestId = $this->submit([$this->organizerZone]);
        $marker = tempnam(sys_get_temp_dir(), 'civipass-hold-');
        self::assertNotFalse($marker);
        unlink($marker);
        $this->markers[] = $marker;
        $suspension = ParallelPhp::start('zone-action.php', [$this->adminOnly->id()->toRfc4122(), 'zone-suspend', $this->organizerZone->id()->toRfc4122(), '{}', $marker.':'.self::HOLD_SECONDS]);
        $deadline = microtime(true) + 8;
        while (!is_file($marker) && microtime(true) < $deadline) {
            usleep(20_000);
        }
        self::assertFileExists($marker, 'la suspension doit tenir ses verrous');

        $approval = $this->start('approve', $requestId);
        usleep(600_000);
        self::assertTrue(ParallelPhp::isRunning($approval), 'l\'approbation doit attendre le verrou de participation');

        $raw = ParallelPhp::finish($suspension);
        self::assertSame(0, $raw['status'], $raw['stderr']);
        $result = $this->outcome($approval);

        // Revalidation SOUS verrou : la zone est désormais suspendue, aucune décision n'est fondée sur l'état d'avant.
        self::assertFalse($result['ok']);
        self::assertSame(DomainRuleViolation::class, $result['error']);
        self::assertStringContainsString('suspendue', (string) $result['message']);
        self::assertSame(0, $this->rows('request_permit_request_decision'));
        self::assertSame('PENDING', $this->partStatus($requestId, $this->organizer));
    }

    /**
     * @param list<array<string, mixed>> $results
     *
     * @return list<array<string, mixed>>
     */
    private function sortByOk(array $results): array
    {
        usort($results, static fn (array $a, array $b): int => (int) $a['ok'] <=> (int) $b['ok']);

        return $results;
    }
}
