<?php

declare(strict_types=1);

namespace App\Event\Domain;

use App\Shared\Domain\DomainRuleViolation;
use Symfony\Component\Uid\Uuid;

/**
 * Compteurs EXACTS d'une commune à la clôture (LOT 05.6.2) : dossiers communaux par statut et autorisations délivrées. Jamais affichés tels quels :
 * seule la publication seuillée (`ClosureStatisticsPublication`) sort du domaine. L'identifiant est celui d'une commune (organisation), jamais d'une personne.
 */
final readonly class MunicipalityCounters
{
    public function __construct(
        public string $municipalityId,
        public int $approved,
        public int $refused,
        public int $pending,
        public int $authorizations,
    ) {
        if (!Uuid::isValid($municipalityId)) {
            throw new DomainRuleViolation('Compteurs de clôture : identifiant de commune invalide.');
        }
        foreach ([$approved, $refused, $pending, $authorizations] as $count) {
            if ($count < 0) {
                throw new DomainRuleViolation('Compteurs de clôture : un compteur ne peut pas être négatif.');
            }
        }
        if ($authorizations > $approved) {
            throw new DomainRuleViolation('Compteurs de clôture : plus d\'autorisations que de dossiers approuvés.');
        }
    }

    public function dossiers(): int
    {
        return $this->approved + $this->refused + $this->pending;
    }
}
