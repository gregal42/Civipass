<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Event\Domain\PublicReference;
use App\Event\Domain\PublicReferenceGenerator;

/**
 * Doublure d'injection d'échec (pas de mock) : un VRAI collaborateur dont une étape échoue volontairement, pour prouver
 * que modification et audit sont annulés ensemble.
 */
/** Réserve un vrai numéro (UPDATE du compteur) puis échoue : le compteur doit revenir en arrière. */
final class ThrowingAfterReferenceGenerator implements PublicReferenceGenerator
{
    public function __construct(private readonly PublicReferenceGenerator $inner)
    {
    }

    public function next(\DateTimeImmutable $now): PublicReference
    {
        $this->inner->next($now);

        throw new \RuntimeException('Échec injecté après réservation de la référence.');
    }
}
