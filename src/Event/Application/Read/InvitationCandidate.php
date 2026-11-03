<?php

declare(strict_types=1);

namespace App\Event\Application\Read;

/**
 * Commune trouvée par la recherche d'invitation. Aucune donnée commerciale ni de gestion : identifiant, nom, code INSEE
 * et situation vis-à-vis de l'événement (DM-084 : pas d'information commerciale entre communes).
 */
final readonly class InvitationCandidate
{
    public function __construct(
        public string $municipalityId,
        public string $name,
        public string $inseeCode,
        public InvitationCandidateState $state,
    ) {
    }
}
