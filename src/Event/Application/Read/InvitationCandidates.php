<?php

declare(strict_types=1);

namespace App\Event\Application\Read;

/** Résultat d'une recherche de commune à inviter ; `tooShort` : la saisie est trop courte pour lancer la recherche. */
final readonly class InvitationCandidates
{
    /**
     * @param list<InvitationCandidate> $items
     */
    public function __construct(public string $query, public array $items, public bool $tooShort = false)
    {
    }
}
