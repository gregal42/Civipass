<?php

declare(strict_types=1);

namespace App\Notification\Domain;

/** Résultat d'un passage du distributeur : compteurs seulement (aucune adresse, aucun secret, aucun identifiant de laissez-passer). */
final readonly class DispatchReport
{
    /**
     * @param array<string, int>                             $outcomes compteurs par résultat (`sent`, `retry`, `abandoned`, …)
     * @param array{purged: int, revoked: int, expired: int} $purge
     */
    public function __construct(public array $outcomes, public array $purge)
    {
    }
}
