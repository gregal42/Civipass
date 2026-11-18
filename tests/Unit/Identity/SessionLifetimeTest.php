<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Application\SessionLifetime;
use App\Identity\Domain\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SessionLifetimeTest extends TestCase
{
    private function user(bool $cws): User
    {
        $user = $this->createStub(User::class);
        $user->method('isSuperAdmin')->willReturn($cws);

        return $user;
    }

    public function testDefaultsMatchTheValidatedDecision(): void
    {
        $l = new SessionLifetime();
        self::assertSame(1800, $l->idleFor($this->user(false)));
        self::assertSame(43200, $l->absoluteFor($this->user(false)));
        self::assertSame(900, $l->idleFor($this->user(true)));
        self::assertSame(28800, $l->absoluteFor($this->user(true)));
        self::assertSame(120, $l->warning);
    }

    public function testConfigurationIsBounded(): void
    {
        $l = new SessionLifetime(agentIdle: 1, agentAbsolute: 10, cwsIdle: 999_999, cwsAbsolute: 999_999_999, warning: 5);
        self::assertSame(SessionLifetime::MIN_IDLE, $l->agentIdle);
        self::assertSame(SessionLifetime::MIN_IDLE, $l->agentAbsolute, 'la durée absolue ne peut être inférieure à l\'inactivité');
        self::assertSame(SessionLifetime::MAX_IDLE, $l->cwsIdle);
        self::assertSame(SessionLifetime::MAX_ABSOLUTE, $l->cwsAbsolute);
        self::assertSame(SessionLifetime::MIN_WARNING, $l->warning);
    }

    /** @return iterable<string, array{bool, int, int, string}> */
    public static function verdicts(): iterable
    {
        yield 'agent actif' => [false, 100, 100, 'ok'];
        yield 'agent juste avant 30 min' => [false, 1799, 1799, 'ok'];
        yield 'agent 30 min d\'inactivité' => [false, 1800, 1800, 'idle'];
        yield 'agent 12 h absolues même actif' => [false, 43200, 10, 'absolute'];
        yield 'CWS 15 min' => [true, 900, 900, 'idle'];
        yield 'CWS 8 h absolues' => [true, 28800, 5, 'absolute'];
        yield 'CWS actif à 14 min' => [true, 840, 840, 'ok'];
    }

    #[DataProvider('verdicts')]
    public function testVerdict(bool $cws, int $elapsedSinceStart, int $elapsedSinceSeen, string $expected): void
    {
        $now = 1_000_000;
        $verdict = (new SessionLifetime())->evaluate($this->user($cws), $now - $elapsedSinceStart, $now - $elapsedSinceSeen, $now);
        self::assertSame($expected, $verdict['state']);
    }
}
