<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Durées de session des utilisateurs AUTHENTIFIÉS (LOT 08.3, DM-138) : inactivité et durée absolue, plus courtes pour le support CWS (super-administrateur).
 * Valeurs réglables par variables d'environnement mais BORNÉES : on ne peut ni les rendre absurdement courtes, ni dépasser un plafond de sécurité.
 * Ne concerne jamais les visiteurs anonymes (brouillon de demande publique).
 */
final readonly class SessionLifetime
{
    public const MIN_IDLE = 300;
    public const MAX_IDLE = 7200;
    public const MAX_ABSOLUTE = 86400;
    public const MIN_WARNING = 30;
    public const MAX_WARNING = 600;

    public int $agentIdle;
    public int $agentAbsolute;
    public int $cwsIdle;
    public int $cwsAbsolute;
    public int $warning;

    public function __construct(
        #[Autowire('%env(int:SESSION_AGENT_IDLE_SECONDS)%')] int $agentIdle = 1800,
        #[Autowire('%env(int:SESSION_AGENT_ABSOLUTE_SECONDS)%')] int $agentAbsolute = 43200,
        #[Autowire('%env(int:SESSION_CWS_IDLE_SECONDS)%')] int $cwsIdle = 900,
        #[Autowire('%env(int:SESSION_CWS_ABSOLUTE_SECONDS)%')] int $cwsAbsolute = 28800,
        #[Autowire('%env(int:SESSION_WARNING_SECONDS)%')] int $warning = 120,
    )
    {
        $this->agentIdle = self::bound($agentIdle, self::MIN_IDLE, self::MAX_IDLE);
        $this->cwsIdle = self::bound($cwsIdle, self::MIN_IDLE, self::MAX_IDLE);
        $this->agentAbsolute = self::bound($agentAbsolute, $this->agentIdle, self::MAX_ABSOLUTE);
        $this->cwsAbsolute = self::bound($cwsAbsolute, $this->cwsIdle, self::MAX_ABSOLUTE);
        $this->warning = self::bound($warning, self::MIN_WARNING, self::MAX_WARNING);
    }

    public function idleFor(User $user): int
    {
        return $user->isSuperAdmin() ? $this->cwsIdle : $this->agentIdle;
    }

    public function absoluteFor(User $user): int
    {
        return $user->isSuperAdmin() ? $this->cwsAbsolute : $this->agentAbsolute;
    }

    /**
     * @return array{state: 'ok'|'idle'|'absolute', idleRemaining: int, absoluteRemaining: int, remaining: int}
     */
    public function evaluate(User $user, int $startedAt, int $lastSeenAt, int $now): array
    {
        $idleRemaining = $lastSeenAt + $this->idleFor($user) - $now;
        $absoluteRemaining = $startedAt + $this->absoluteFor($user) - $now;
        $state = 'ok';
        if ($absoluteRemaining <= 0) {
            $state = 'absolute';
        } elseif ($idleRemaining <= 0) {
            $state = 'idle';
        }

        return ['state' => $state, 'idleRemaining' => $idleRemaining, 'absoluteRemaining' => $absoluteRemaining, 'remaining' => min($idleRemaining, $absoluteRemaining)];
    }

    private static function bound(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
