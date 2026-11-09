<?php

declare(strict_types=1);

namespace App\Tests\Integration\Permit;

use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Shared\Domain\ConflictCause;
use App\Shared\Domain\OperationConflict;
use App\Tests\Support\MunicipalDecisionTestCase;
use App\Tests\Support\ParallelPhp;
use PHPUnit\Framework\Attributes\Group;

/**
 * Concurrence réelle (processus PHP sur MySQL) de la création des laissez-passer (LOT 05.1, DT-046) : deux communes qui approuvent en même
 * temps la même demande ne créent qu'UN laissez-passer ; des premières approbations de demandes différentes ne s'interbloquent pas ; une même
 * part ne s'approuve qu'une fois. Le premier processus TIENT ses verrous (attente à l'écriture de l'audit du laissez-passer, donc après le
 * verrou de la demande et celui du compteur) pour que l'ordre soit maîtrisé.
 */
#[Group('requires-request-schema')]
final class PermitIssuanceConcurrencyTest extends MunicipalDecisionTestCase
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
    private function start(User $agent, Municipality $commune, string $requestId, ?string $holdOnAction = null): array
    {
        $arguments = [$agent->id()->toRfc4122(), $commune->id()->toRfc4122(), $requestId, 'approve', '{}'];
        $marker = null;
        if (null !== $holdOnAction) {
            $marker = tempnam(sys_get_temp_dir(), 'civipass-hold-');
            self::assertNotFalse($marker);
            unlink($marker);
            $this->markers[] = $marker;
            array_push($arguments, $marker.':'.self::HOLD_SECONDS, $holdOnAction);
        }
        $process = ParallelPhp::start('decide-request.php', $arguments);
        if (null !== $marker) {
            $deadline = microtime(true) + 8;
            while (!is_file($marker) && microtime(true) < $deadline) {
                usleep(20_000);
            }
            if (!is_file($marker)) {
                $raw = ParallelPhp::finish($process);
                self::fail('L\'opération tenante n\'a pas pris ses verrous : '.$raw['stdout'].' '.substr($raw['stderr'], -400));
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
            self::assertSame(OperationConflict::class, $decoded['error'], 'refus contrôlé, jamais une erreur technique : '.json_encode($decoded));
        }

        return $decoded;
    }

    public function testTwoCommunesApprovingTheSameRequestAtTheSameTimeCreateOnePermitWithTwoAuthorizations(): void
    {
        $id = $this->submit();

        $first = $this->start($this->instructor, $this->organizer, $id, 'PERMIT_ISSUED');
        $second = $this->start($this->partnerInstructor, $this->partner, $id);
        usleep(600_000);
        self::assertTrue(ParallelPhp::isRunning($second), 'la seconde commune doit attendre le verrou de la demande');

        $a = $this->outcome($first);
        $b = $this->outcome($second);

        self::assertTrue($a['ok'], json_encode($a));
        self::assertTrue($b['ok'], json_encode($b));
        $conn = $this->em->getConnection();
        self::assertSame([1, 2, 1], [$this->rows('permit_permit'), $this->rows('permit_authorization'), $this->rows('permit_verification_token')]);
        self::assertSame(2, $this->rows('permit_authorization_zone'));
        self::assertSame(1, (int) $conn->fetchOne('SELECT last_number FROM permit_reference_counter'), 'un seul numéro consommé');
        self::assertSame('LP-2026-0001', $conn->fetchOne('SELECT reference FROM permit_permit'));
        self::assertSame(1, $this->rows('audit_log WHERE action = \'PERMIT_ISSUED\''));
        self::assertSame(1, $this->rows('audit_log WHERE action = \'PERMIT_AUTHORIZATION_ADDED\''));
        // Outbox (LOT 05.3.1) : exactement une intention par événement métier, malgré la concurrence.
        self::assertSame(['AUTHORIZATION_ADDED', 'PERMIT_ISSUED'], $conn->fetchFirstColumn('SELECT type FROM notification_outbox ORDER BY type'));
        $firstDecisionCommune = $conn->fetchOne('SELECT LOWER(HEX(d.municipality_id)) FROM permit_permit p JOIN request_permit_request_decision d ON d.id = p.first_decision_id');
        self::assertSame(bin2hex($this->organizer->id()->toBinary()), $firstDecisionCommune, 'la première décision est celle de la commune qui a créé le laissez-passer');
    }

    public function testFirstApprovalsOfDifferentRequestsRunConcurrentlyWithoutDeadlockNorDuplicateNumbers(): void
    {
        $requests = [];
        for ($i = 0; $i < 6; ++$i) {
            $requests[] = $this->submit();
        }

        // Vague 1 : six créations de laissez-passer simultanées (communes alternées).
        $wave = [];
        foreach ($requests as $i => $id) {
            $wave[] = 0 === $i % 2 ? $this->start($this->instructor, $this->organizer, $id) : $this->start($this->partnerInstructor, $this->partner, $id);
        }
        foreach ($wave as $process) {
            $outcome = $this->outcome($process);
            self::assertTrue($outcome['ok'], 'aucun interblocage ni conflit attendu : '.json_encode($outcome));
        }
        self::assertSame(6, $this->rows('permit_permit'));
        self::assertSame(['LP-2026-0001', 'LP-2026-0002', 'LP-2026-0003', 'LP-2026-0004', 'LP-2026-0005', 'LP-2026-0006'], $this->em->getConnection()->fetchFirstColumn('SELECT reference FROM permit_permit ORDER BY reference'));

        // Vague 2 : l'autre commune approuve chacune des six demandes en même temps.
        $wave = [];
        foreach ($requests as $i => $id) {
            $wave[] = 0 === $i % 2 ? $this->start($this->partnerInstructor, $this->partner, $id) : $this->start($this->instructor, $this->organizer, $id);
        }
        foreach ($wave as $process) {
            self::assertTrue($this->outcome($process)['ok']);
        }
        self::assertSame([6, 12, 6], [$this->rows('permit_permit'), $this->rows('permit_authorization'), $this->rows('permit_verification_token')]);
        self::assertSame([6, 6, 12], [$this->rows('notification_outbox WHERE type = \'PERMIT_ISSUED\''), $this->rows('notification_outbox WHERE type = \'AUTHORIZATION_ADDED\''), $this->rows('notification_outbox')]);
        self::assertSame(12, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(DISTINCT deduplication_key) FROM notification_outbox'));
    }

    public function testTheSamePartApprovedTwiceAtTheSameTimeLeavesOneAuthorization(): void
    {
        $id = $this->submit();

        $first = $this->start($this->instructor, $this->organizer, $id, 'PERMIT_ISSUED');
        $second = $this->start($this->instructor, $this->organizer, $id);

        $a = $this->outcome($first);
        $b = $this->outcome($second);

        self::assertTrue($a['ok']);
        self::assertFalse($b['ok'], json_encode($b));
        self::assertSame(ConflictCause::StaleState->value, $b['cause']);
        self::assertSame([1, 1], [$this->rows('permit_permit'), $this->rows('permit_authorization')]);
        self::assertSame(1, $this->rows('notification_outbox'), 'le refus concurrent n\'enregistre rien');
    }

    public function testAnApprovalThatFailsInTheSecondCommuneLeavesTheFirstPermitIntact(): void
    {
        $id = $this->submit();
        $this->outcome($this->start($this->instructor, $this->organizer, $id));
        $before = $this->em->getConnection()->fetchAssociative('SELECT reference FROM permit_permit');
        // La commune partenaire est suspendue : son approbation est refusée, rien n'est ajouté ni modifié.
        $this->sql('UPDATE event_participation SET status = \'SUSPENDED\' WHERE id = :id', ['id' => $this->partnerParticipation->id()->toBinary()]);

        $raw = ParallelPhp::run('decide-request.php', [[$this->partnerInstructor->id()->toRfc4122(), $this->partner->id()->toRfc4122(), $id, 'approve', '{}']]);
        $decoded = json_decode($raw[0]['stdout'], true, 512, \JSON_THROW_ON_ERROR);

        self::assertFalse($decoded['ok']);
        self::assertSame([1, 1], [$this->rows('permit_permit'), $this->rows('permit_authorization')]);
        self::assertSame(1, $this->rows('notification_outbox'), 'la commune refusée n\'ajoute aucune intention');
        self::assertSame($before, $this->em->getConnection()->fetchAssociative('SELECT reference FROM permit_permit'));
    }
}
