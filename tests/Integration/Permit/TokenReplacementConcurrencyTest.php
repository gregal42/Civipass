<?php

declare(strict_types=1);

namespace App\Tests\Integration\Permit;

use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRole as Role;
use App\Permit\Application\PublicVerificationReader;
use App\Permit\Application\Result\PublicVerificationOutcome;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\RequestSubmissionService;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\DurationMode;
use App\Request\Domain\TravelMode;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationConflict;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\MunicipalDecisionTestCase;
use App\Tests\Support\ParallelPhp;
use App\Tests\Support\UserBuilder;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;

/**
 * Concurrence réelle (processus PHP sur MySQL) du remplacement du QR (LOT 05.5.5) : la première opération TIENT ses verrous (attente avant le commit) ; la seconde attend puis
 * voit l'état COMPLET de la première. Jamais deux QR valides ni aucun, même vu depuis la lecture publique pendant l'opération ; remplacement contre révocation (les deux
 * ordres), deux remplacements, double envoi du même identifiant d'opération, deux communes, remplacement contre modification de plaques : aucun interblocage.
 */
#[Group('requires-request-schema')]
final class TokenReplacementConcurrencyTest extends MunicipalDecisionTestCase
{
    private const HOLD_SECONDS = 2.5;

    private User $partnerAdmin;

    /** @var list<string> */
    private array $markers = [];

    protected function tearDown(): void
    {
        foreach ($this->markers as $marker) {
            @unlink($marker);
        }
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->partnerAdmin = UserBuilder::aUser()->build();
        $this->save($this->partnerAdmin, MembershipBuilder::aMembership($this->partnerAdmin, $this->partner)->withRoles(Role::MunicipalityAdmin)->build());
    }

    private function approved(): string
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
            ['AA-111-AA', 'BB-222-BB'],
            [$this->organizerZone->id()->toRfc4122(), $this->partnerZone->id()->toRfc4122()],
            true,
        ), $this->key())->requestId;
        foreach ([[$this->instructor, $this->organizer], [$this->partnerInstructor, $this->partner]] as [$agent, $commune]) {
            $user = $this->em->find(User::class, $agent->id()) ?? $agent;
            $this->instruction()->approve($user, $commune->id()->toRfc4122(), $id, ApprovalDuration::fixed(DurationMode::H72));
        }

        return $id;
    }

    private function marker(): string
    {
        $marker = tempnam(sys_get_temp_dir(), 'civipass-hold-');
        self::assertNotFalse($marker);
        unlink($marker);
        $this->markers[] = $marker;

        return $marker;
    }

    /** @param array{resource, array<int, resource>, string} $process */
    private function waitFor(string $marker, array $process): void
    {
        $deadline = microtime(true) + 8;
        while (!is_file($marker) && microtime(true) < $deadline) {
            usleep(20_000);
        }
        if (!is_file($marker)) {
            $raw = ParallelPhp::finish($process);
            self::fail('L\'opération tenante n\'a pas pris ses verrous : '.$raw['stdout'].' '.substr($raw['stderr'], -500));
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{resource, array<int, resource>, string}
     */
    private function launch(string $requestId, string $action, array $payload = [], bool $hold = false, ?User $agent = null, ?Municipality $commune = null): array
    {
        $arguments = [($agent ?? $this->adminOnly)->id()->toRfc4122(), ($commune ?? $this->organizer)->id()->toRfc4122(), $requestId, $action, json_encode($payload + ['operation' => Uuid::v7()->toRfc4122()], \JSON_THROW_ON_ERROR)];
        $marker = null;
        if ($hold) {
            $marker = $this->marker();
            $arguments[] = $marker.':'.self::HOLD_SECONDS;
        }
        $process = ParallelPhp::start('token-replace.php', $arguments);
        if (null !== $marker) {
            $this->waitFor($marker, $process);
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
        if (false === ($decoded['ok'] ?? true)) {
            self::assertContains($decoded['error'], [DomainRuleViolation::class, OperationNotAllowed::class, OperationConflict::class], 'refus contrôlé, jamais une erreur technique : '.json_encode($decoded));
        }

        return $decoded;
    }

    /** @return array{active: list<string>, retired: list<string>} */
    private function tokens(): array
    {
        $this->em->clear();
        $rows = $this->em->getConnection()->fetchAllAssociative('SELECT token, active_permit_id FROM permit_verification_token ORDER BY created_at, id');

        return [
            'active' => array_values(array_map(static fn (array $r): string => (string) $r['token'], array_filter($rows, static fn (array $r): bool => null !== $r['active_permit_id']))),
            'retired' => array_values(array_map(static fn (array $r): string => (string) $r['token'], array_filter($rows, static fn (array $r): bool => null === $r['active_permit_id']))),
        ];
    }

    private function lookup(string $token): PublicVerificationOutcome
    {
        $this->em->clear();

        return $this->service(PublicVerificationReader::class)->find($token, new \DateTimeImmutable('2026-06-12 09:00:00', new \DateTimeZone('UTC')))->outcome;
    }

    public function testThePublicReadNeverSeesTwoValidQrNorNoneWhileAReplacementHoldsItsLocksAndTheSwapAppearsOnlyAtCommit(): void
    {
        $requestId = $this->approved();
        $old = $this->tokens()['active'][0];

        $replacement = $this->launch($requestId, 'replace', hold: true);
        for ($i = 0; $i < 5; ++$i) {
            $state = $this->tokens();
            self::assertSame([$old], $state['active'], 'avant le commit : un seul QR actif, l\'ancien');
            self::assertSame(PublicVerificationOutcome::Found, $this->lookup($old));
            usleep(100_000);
        }
        self::assertSame('REPLACED:1:done', $this->outcome($replacement)['outcome']);

        $after = $this->tokens();
        self::assertCount(1, $after['active']);
        self::assertSame([$old], $after['retired']);
        self::assertSame(PublicVerificationOutcome::Replaced, $this->lookup($old));
        self::assertSame(PublicVerificationOutcome::Found, $this->lookup($after['active'][0]));
    }

    public function testAReplacementHoldingItsLocksMakesTheConcurrentRevocationWaitThenBothApply(): void
    {
        $requestId = $this->approved();

        $replacement = $this->launch($requestId, 'replace', hold: true);
        $revocation = $this->launch($requestId, 'revoke');
        usleep(600_000);
        self::assertTrue(ParallelPhp::isRunning($revocation), 'la révocation attend les verrous du remplacement');

        self::assertSame('REPLACED:1:done', $this->outcome($replacement)['outcome']);
        self::assertSame('Revoked', $this->outcome($revocation)['outcome']);
        self::assertCount(1, $this->tokens()['active']);
        self::assertCount(1, $this->tokens()['retired']);
    }

    public function testARevocationHoldingItsLocksMakesTheWaitingReplacementFailAndNothingIsChanged(): void
    {
        $requestId = $this->approved();
        $old = $this->tokens()['active'][0];

        $revocation = $this->launch($requestId, 'revoke', hold: true);
        $replacement = $this->launch($requestId, 'replace');
        usleep(600_000);
        self::assertTrue(ParallelPhp::isRunning($replacement));

        self::assertSame('Revoked', $this->outcome($revocation)['outcome']);
        $result = $this->outcome($replacement);

        self::assertFalse($result['ok']);
        self::assertSame(DomainRuleViolation::class, $result['error']);
        self::assertStringContainsString('révoquée', (string) $result['message']);
        self::assertSame(['active' => [$old], 'retired' => []], $this->tokens(), 'la révocation ne se contourne pas : le QR est inchangé');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM permit_revision WHERE type = 'TOKEN_REPLACED'"), 'aucune révision de remplacement : seule la révocation existe');
    }

    public function testTwoReplacementsAreSerializedAndLeaveExactlyOneActiveQr(): void
    {
        $requestId = $this->approved();

        $first = $this->launch($requestId, 'replace', hold: true);
        $second = $this->launch($requestId, 'replace', ['offset' => 3700], agent: $this->partnerAdmin, commune: $this->partner);
        usleep(600_000);
        self::assertTrue(ParallelPhp::isRunning($second), 'la seconde attend le verrou du laissez-passer');

        self::assertSame('REPLACED:1:done', $this->outcome($first)['outcome']);
        self::assertSame('REPLACED:2:done', $this->outcome($second)['outcome'], 'le second remplace le NOUVEAU jeton et voit le numéro d\'ordre du premier');

        $state = $this->tokens();
        self::assertCount(1, $state['active']);
        self::assertCount(2, $state['retired']);
        foreach ($state['retired'] as $token) {
            self::assertSame(PublicVerificationOutcome::Replaced, $this->lookup($token));
        }
        self::assertSame(2, $this->rows('permit_revision'));
    }

    public function testASecondConcurrentReplacementWithinTheHourIsAskedForTheReferenceNotBlocked(): void
    {
        $requestId = $this->approved();

        $first = $this->launch($requestId, 'replace', hold: true);
        $second = $this->launch($requestId, 'replace', agent: $this->partnerAdmin, commune: $this->partner);
        self::assertSame('REPLACED:1:done', $this->outcome($first)['outcome']);
        $result = $this->outcome($second);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('ressaisissez la référence', (string) $result['message'], 'la seconde voit le remplacement de la première, committé : confirmation renforcée');
        self::assertCount(1, $this->tokens()['retired']);
    }

    public function testTheSameOperationIdentifierSubmittedTwiceAtTheSameTimeReplacesOnce(): void
    {
        $requestId = $this->approved();
        $operation = Uuid::v7()->toRfc4122();

        $first = $this->launch($requestId, 'replace', ['operation' => $operation], hold: true);
        $second = $this->launch($requestId, 'replace', ['operation' => $operation]);
        usleep(600_000);
        self::assertTrue(ParallelPhp::isRunning($second), 'le double envoi attend le verrou');

        $a = $this->outcome($first);
        $b = $this->outcome($second);

        self::assertSame('REPLACED:1:done', $a['outcome']);
        self::assertSame('REPLACED:1:replayed', $b['outcome'], 'résultat initial restitué, aucun second remplacement');
        self::assertCount(1, $this->tokens()['retired']);
        self::assertSame(1, $this->rows('permit_revision'));
    }

    public function testAReplacementAndAPlateChangeOfTheSamePermitNeverDeadlock(): void
    {
        $requestId = $this->approved();

        $replacement = $this->launch($requestId, 'replace', hold: true);
        $plates = $this->launch($requestId, 'vehicles', ['adds' => ['CC-333-CC']]);
        $a = $this->outcome($replacement);
        $b = $this->outcome($plates);

        self::assertTrue($a['ok'] && $b['ok'], json_encode([$a, $b], \JSON_THROW_ON_ERROR));
        self::assertCount(1, $this->tokens()['active']);
        self::assertSame(3, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM permit_vehicle WHERE removed_at IS NULL'));
    }
}
