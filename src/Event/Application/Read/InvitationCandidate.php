<?php

declare(strict_types=1);

namespace App\Event\Application\Read;

/**
 * Commune trouvée par la recherche d'invitation (référentiel national ou commune déjà inscrite). Aucune donnée commerciale ni de
 * gestion : nom, code INSEE, situation vis-à-vis de l'événement (DM-084 : pas d'information commerciale entre communes).
 * `municipalityId` est nul tant que la commune n'est pas inscrite sur Civipass (elle le sera à l'envoi de l'invitation).
 */
final readonly class InvitationCandidate
{
    public function __construct(
        public ?string $municipalityId,
        public string $name,
        public string $inseeCode,
        public InvitationCandidateState $state,
        public bool $registered = true,
    ) {
    }
}
