<?php

declare(strict_types=1);

namespace App\Tests\Integration\Request;

use App\Shared\Domain\ConflictCause;
use App\Shared\Domain\OperationConflict;
use App\Tests\Support\MunicipalDecisionTestCase;
use App\Tests\Support\ParallelPhp;
use PHPUnit\Framework\Attributes\Group;

/**
 * Concurrence réelle (processus PHP sur MySQL) de la délivrance directe avec une MÊME clé d'idempotence : jamais deux dossiers ni deux décisions.
 * La première opération TIENT ses verrous (attente avant le commit) pour que l'ordre soit maîtrisé.
 */
#[Group('requires-request-schema')]
final class DirectIssuanceConcurrencyTest extends MunicipalDecisionTestCase
{
    private const HOLD_SECONDS = 2.5;
    private const KEY = 'concurrent-issuance-0000001';

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
     * @return array{resource, array<int, resource>, string}
     */
    private function start(string $key, string $mode = 'H24', bool $hold = false, bool $partner = false): array
    {
        $arguments = $partner
            ? [$this->partnerInstructor->id()->toRfc4122(), $this->partner->id()->toRfc4122(), $this->event->id()->toRfc4122(), $this->partnerZone->id()->toRfc4122(), $key, $mode]
            : [$this->instructor->id()->toRfc4122(), $this->organizer->id()->toRfc4122(), $this->event->id()->toRfc4122(), $this->organizerZone->id()->toRfc4122(), $key, $mode];
        $marker = null;
        if ($hold) {
            $marker = tempnam(sys_get_temp_dir(), 'civipass-hold-');
            self::assertNotFalse($marker);
            unlink($marker);
            $this->markers[] = $marker;
            $arguments[] = $marker.':'.self::HOLD_SECONDS;
        }
        $process = ParallelPhp::start('issue-request.php', $arguments);
        if (null !== $marker) {
            $deadline = microtime(true) + 8;
            while (!is_file($marker) && microtime(true) < $deadline) {
                usleep(20_000);
            }
            self::assertFileExists($marker, 'l\'opération tenante doit prendre ses verrous : '.(is_file($marker) ? '' : json_encode(ParallelPhp::finish($process))));
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
            self::assertSame(OperationConflict::class, $decoded['error'], 'refus contrôlé, jamais une erreur technique : '.json_encode($decoded));
        }

        return $decoded;
    }

    public function testAReplayWhileTheFirstIssuanceHoldsItsLocksWaitsThenReturnsTheExistingResult(): void
    {
        $first = $this->start(self::KEY, 'H24', hold: true);
        $second = $this->start(self::KEY, 'H72');
        usleep(600_000);
        self::assertTrue(ParallelPhp::isRunning($second), 'le rejeu doit attendre les verrous de la première opération');

        $a = $this->outcome($first);
        $b = $this->outcome($second);

        self::assertTrue($a['ok']);
        self::assertFalse($a['replayed']);
        self::assertTrue($b['ok'], json_encode($b));
        self::assertTrue($b['replayed']);
        self::assertSame($a['reference'], $b['reference']);
        self::assertSame('H24', $b['mode'], 'la décision d\'origine est retrouvée, non réappliquée avec 72 h');
        self::assertSame([1, 1, 1], [$this->rows('request_permit_request'), $this->rows('request_permit_request_decision'), $this->rows('request_idempotency_key')]);
        self::assertSame(1, $this->rows('audit_log WHERE action = \'REQUEST_APPROVED\''));
    }

    public function testTwoSimultaneousIssuancesWithTheSameKeyLeaveExactlyOneRequestAndOneDecision(): void
    {
        $results = array_map($this->outcome(...), [$this->start(self::KEY.'b'), $this->start(self::KEY.'b')]);

        $created = array_filter($results, static fn (array $r): bool => $r['ok'] && !$r['replayed']);
        self::assertCount(1, $created, json_encode($results));
        foreach ($results as $r) {
            if (!$r['ok']) {
                self::assertSame(ConflictCause::UniqueViolation->value, $r['cause']);
            }
        }
        self::assertSame([1, 1, 1, 1], [$this->rows('request_permit_request'), $this->rows('request_permit_request_municipality'), $this->rows('request_permit_request_decision'), $this->rows('request_idempotency_key')]);
        self::assertSame('APPROVED', $this->em->getConnection()->fetchOne('SELECT status FROM request_permit_request_municipality'));
        self::assertSame([1, 1, 1], [$this->rows('permit_permit'), $this->rows('permit_authorization'), $this->rows('permit_verification_token')], 'un seul laissez-passer, même sous délivrances simultanées');
    }

    /**
     * Deux communes distinctes (verrous de participation disjoints) délivrent en même temps avec des clés DIFFÉRENTES : les deux relectures
     * verrouillantes de l'empreinte (clé absente) puis les deux insertions ne doivent jamais s'interbloquer.
     */
    public function testTwoCommunesIssuingAtTheSameTimeWithDifferentKeysNeverDeadlock(): void
    {
        $first = $this->start(self::KEY.'c', hold: true);
        $second = $this->start(self::KEY.'d', hold: true, partner: true);

        $results = [$this->outcome($first), $this->outcome($second)];

        foreach ($results as $r) {
            self::assertTrue($r['ok'], 'aucun interblocage : '.json_encode($r));
        }
        self::assertSame([2, 2, 2], [$this->rows('request_permit_request'), $this->rows('request_permit_request_decision'), $this->rows('request_idempotency_key')]);
        self::assertSame(2, $this->rows('permit_permit'));
    }
}
