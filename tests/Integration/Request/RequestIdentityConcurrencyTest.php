<?php

declare(strict_types=1);

namespace App\Tests\Integration\Request;

use App\Shared\Domain\ConflictCause;
use App\Shared\Domain\OperationConflict;
use App\Tests\Support\MunicipalDecisionTestCase;
use App\Tests\Support\ParallelPhp;
use PHPUnit\Framework\Attributes\Group;

/**
 * Concurrence réelle (processus PHP sur MySQL) de la correction d'identité : deux agents partent de la MÊME version ; la première correction tient
 * son verrou, la seconde attend puis est refusée (StaleState) au lieu d'écraser silencieusement la première.
 */
#[Group('requires-request-schema')]
final class RequestIdentityConcurrencyTest extends MunicipalDecisionTestCase
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
     * @return array{resource, array<int, resource>, string}
     */
    private function start(string $requestId, string $name, bool $hold = false): array
    {
        $arguments = [$this->instructor->id()->toRfc4122(), $this->organizer->id()->toRfc4122(), $requestId, '0', $name];
        $marker = null;
        if ($hold) {
            $marker = tempnam(sys_get_temp_dir(), 'civipass-hold-');
            self::assertNotFalse($marker);
            unlink($marker);
            $this->markers[] = $marker;
            $arguments[] = $marker.':'.self::HOLD_SECONDS;
        }
        $process = ParallelPhp::start('correct-identity.php', $arguments);
        if (null !== $marker) {
            $deadline = microtime(true) + 8;
            while (!is_file($marker) && microtime(true) < $deadline) {
                usleep(20_000);
            }
            self::assertFileExists($marker, 'l\'opération tenante doit prendre son verrou');
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
            self::assertContains($decoded['error'], [OperationConflict::class, \App\Request\Application\IdentityCorrectionUnavailable::class], 'refus contrôlé, jamais une erreur technique : '.json_encode($decoded));
        }

        return $decoded;
    }

    public function testTwoAgentsFromTheSameVersionLeaveExactlyOneCorrectionAndNoSilentOverwrite(): void
    {
        $id = $this->submit([$this->organizerZone]);

        $first = $this->start($id, 'PremierProcessus', hold: true);
        $second = $this->start($id, 'SecondProcessus');
        usleep(600_000);
        self::assertTrue(ParallelPhp::isRunning($second), 'la seconde correction doit attendre le verrou de la demande');

        $a = $this->outcome($first);
        $b = $this->outcome($second);

        self::assertTrue($a['ok'], json_encode($a));
        self::assertFalse($b['ok'], json_encode($b));
        self::assertSame(ConflictCause::StaleState->value, $b['cause']);
        $conn = $this->em->getConnection();
        self::assertSame('PremierProcessus', $conn->fetchOne('SELECT last_name FROM request_permit_request'));
        self::assertSame(1, (int) $conn->fetchOne('SELECT identity_version FROM request_permit_request'));
        self::assertSame(1, $this->rows('audit_log WHERE action = \'REQUEST_IDENTITY_CORRECTED\''));
    }

    public function testACorrectionAndADecisionOnTheSameRequestAreSerializedByTheSharedLockOrderWithoutDeadlock(): void
    {
        $id = $this->submit([$this->organizerZone]);
        $first = $this->start($id, 'PendantDecision', hold: true);

        // La correction tient l'événement (partagé) et la participation : la décision de la même commune attend (même ordre DT-024), puis passe.
        $this->instruction()->approve($this->instructor, $this->organizer->id()->toRfc4122(), $id, \App\Request\Domain\ApprovalDuration::fixed(\App\Request\Domain\DurationMode::H24));

        self::assertTrue($this->outcome($first)['ok']);
        self::assertSame('APPROVED', $this->partStatus($id, $this->organizer));
        self::assertSame('PendantDecision', $this->em->getConnection()->fetchOne('SELECT last_name FROM request_permit_request'));
    }

    public function testASuspensionOfTheParticipationWhileACorrectionHoldsItsLocksWaitsThenTheCorrectionAlreadyDone(): void
    {
        $id = $this->submit([$this->organizerZone]);
        $first = $this->start($id, 'AvantSuspension', hold: true);

        $this->sql('UPDATE event_participation SET status = \'SUSPENDED\' WHERE id = :id', ['id' => $this->organizerParticipation->id()->toBinary()]);

        self::assertTrue($this->outcome($first)['ok'], 'la correction avait pris ses verrous quand la participation était active');
        $second = $this->outcome($this->start($id, 'ApresSuspension'));
        self::assertFalse($second['ok']);
        self::assertSame(\App\Request\Application\IdentityCorrectionUnavailable::class, $second['error'] ?? null);
        self::assertSame('AvantSuspension', $this->em->getConnection()->fetchOne('SELECT last_name FROM request_permit_request'));
    }
}
