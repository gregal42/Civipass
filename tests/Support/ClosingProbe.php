<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Event\Application\EventStatusProbe;
use Symfony\Component\Uid\Uuid;

/**
 * Doublure de test : à la N-ième lecture de l'état d'un laissez-passer (garde G1 = 1, G2 = 2), exécute d'abord une action (la clôture de l'événement), puis
 * délègue à la vraie lecture. Sert à placer la clôture EXACTEMENT entre deux gardes du distributeur.
 */
final class ClosingProbe implements EventStatusProbe
{
    private int $calls = 0;

    /** @param \Closure(): void $beforeCall */
    public function __construct(private readonly EventStatusProbe $inner, private readonly int $onCall, private readonly \Closure $beforeCall)
    {
    }

    public function isClosed(Uuid $eventId): bool
    {
        return $this->inner->isClosed($eventId);
    }

    public function isClosedByPermit(Uuid $permitId): bool
    {
        if (++$this->calls === $this->onCall) {
            ($this->beforeCall)();
        }

        return $this->inner->isClosedByPermit($permitId);
    }
}
