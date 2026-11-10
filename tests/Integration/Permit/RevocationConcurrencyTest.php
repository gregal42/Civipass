<?php

declare(strict_types=1);

namespace App\Tests\Integration\Permit;

use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\RequestSubmissionService;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\DurationMode;
use App\Request\Domain\TravelMode;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationConflict;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\MunicipalDecisionTestCase;
use App\Tests\Support\ParallelPhp;
use PHPUnit\Framework\Attributes\Group;

/**
 * Concurrence réelle (processus PHP sur MySQL) des révocations (LOT 05.5.1) : la première opération TIENT ses verrous (attente avant le commit) ;
 * la seconde attend, puis voit l'état complet de la première. Deux révocations de la même autorisation n'écrivent qu'UNE révision, UN audit, UNE
 * notification ; deux communes différentes peuvent révoquer chacune la leur sans interblocage ni perte.
 */
#[Group('requires-request-schema')]
final class RevocationConcurrencyTest extends MunicipalDecisionTestCase
{
    private const HOLD_SECONDS = 2.5;

    /** @var list<string> */
    private array $markers = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!$this->em->getConnection()->createSchemaManager()->tablesExist(['permit_revision'])) {
            self::markTestSkipped('Schéma des révisions non migré (LOT 05.5.1) : test exécutable après la migration validée.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->markers as $marker) {
            @unlink($marker);
        }
        parent::tearDown();
    }

    private function approvedByBoth(): string
    {
        $id = $this->service(RequestSubmissionService::class)->submitPublic(new SubmitRequestCommand(
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
        foreach ([[$this->instructor, $this->organizer], [$this->partnerInstructor, $this->partner]] as [$agent, $commune]) {
            $user = $this->em->find(User::class, $agent->id()) ?? $agent;
            $this->instruction()->approve($user, $commune->id()->toRfc4122(), $id, ApprovalDuration::fixed(DurationMode::H72));
        }

        return $id;
    }

    /** @return array{resource, array<int, resource>, string} */
    private function start(User $agent, Municipality $commune, string $requestId, string $reason, bool $hold = false): array
    {
        $arguments = [$agent->id()->toRfc4122(), $commune->id()->toRfc4122(), $requestId, $reason];
        if ($hold) {
            $marker = tempnam(sys_get_temp_dir(), 'civipass-hold-');
            self::assertNotFalse($marker);
            unlink($marker);
            $this->markers[] = $marker;
            $arguments[] = $marker.':'.self::HOLD_SECONDS;
        }
        $process = ParallelPhp::start('revoke-authorization.php', $arguments);
        if ($hold) {
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
            self::assertContains($decoded['error'], [DomainRuleViolation::class, OperationNotAllowed::class, OperationConflict::class], 'refus contrôlé, jamais une erreur technique : '.json_encode($decoded));
        }

        return $decoded;
    }

    /** @return array{revisions: int, audits: int, outbox: int, revoked: int} */
    private function counts(): array
    {
        $c = $this->em->getConnection();

        return [
            'revisions' => $this->rows('permit_revision'),
            'audits' => (int) $c->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'PERMIT_AUTHORIZATION_REVOKED'"),
            'outbox' => (int) $c->fetchOne("SELECT COUNT(*) FROM notification_outbox WHERE type = 'AUTHORIZATION_REVOKED'"),
            'revoked' => (int) $c->fetchOne('SELECT COUNT(*) FROM permit_authorization WHERE revoked_at IS NOT NULL'),
        ];
    }

    public function testASecondRevocationWaitsForTheFirstThenSeesItAndWritesNothing(): void
    {
        $requestId = $this->approvedByBoth();

        $first = $this->start($this->instructor, $this->organizer, $requestId, 'DUPLICATE', hold: true);
        $second = $this->start($this->adminOnly, $this->organizer, $requestId, 'ISSUED_IN_ERROR');
        usleep(600_000);
        self::assertTrue(ParallelPhp::isRunning($second), 'la seconde révocation attend les verrous de la première');

        $a = $this->outcome($first);
        $b = $this->outcome($second);

        self::assertSame('Revoked', $a['outcome']);
        self::assertSame('AlreadyRevoked', $b['outcome'], 'elle voit l\'état complet de la première et n\'écrit rien');
        self::assertSame(['revisions' => 1, 'audits' => 1, 'outbox' => 1, 'revoked' => 1], $this->counts());
        self::assertSame('DUPLICATE', $this->em->getConnection()->fetchOne('SELECT reason_code FROM permit_revision'), 'le motif de la première est celui de l\'historique');
    }

    public function testTwoSimultaneousRevocationsOfTheSameAuthorizationLeaveExactlyOneRevision(): void
    {
        $requestId = $this->approvedByBoth();

        $results = array_map($this->outcome(...), [
            $this->start($this->instructor, $this->organizer, $requestId, 'DUPLICATE'),
            $this->start($this->adminOnly, $this->organizer, $requestId, 'MISUSE'),
        ]);

        $outcomes = array_map(static fn (array $r): string => (string) ($r['outcome'] ?? $r['error']), $results);
        sort($outcomes);
        self::assertContains($outcomes[0], ['AlreadyRevoked', OperationConflict::class], $outcomes[0]);
        self::assertTrue(\in_array('Revoked', $outcomes, true), 'une révocation aboutit : '.json_encode($outcomes));
        self::assertSame(['revisions' => 1, 'audits' => 1, 'outbox' => 1, 'revoked' => 1], $this->counts());
    }

    public function testTwoCommunesRevokingTheirOwnAuthorizationAtTheSameTimeBothSucceedWithoutDeadlock(): void
    {
        $requestId = $this->approvedByBoth();

        $first = $this->start($this->instructor, $this->organizer, $requestId, 'DUPLICATE', hold: true);
        $second = $this->start($this->partnerInstructor, $this->partner, $requestId, 'MISUSE');
        $a = $this->outcome($first);
        $b = $this->outcome($second);

        self::assertSame('Revoked', $a['outcome']);
        self::assertSame('Revoked', $b['outcome'], 'le verrou du laissez-passer sérialise : aucune perte, aucun interblocage');
        self::assertSame(['revisions' => 2, 'audits' => 2, 'outbox' => 2, 'revoked' => 2], $this->counts());
    }
}
