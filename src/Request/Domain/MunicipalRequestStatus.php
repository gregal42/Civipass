<?php

declare(strict_types=1);

namespace App\Request\Domain;

/**
 * Statut de la part communale d'une demande (SFD §2, CDC §7.1). Les décisions sont indépendantes par commune : il
 * n'existe aucun statut global. Seul `Pending` est écrit au dépôt ; l'approbation et le refus (LOT 04, DM-106) sont
 * terminaux : ni réouverture ni modification dans cet incrément. Source unique des transitions (DT-031).
 */
enum MunicipalRequestStatus: string
{
    case Pending = 'PENDING';
    case Approved = 'APPROVED';
    case Refused = 'REFUSED';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Approved, self::Refused],
            self::Approved, self::Refused => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return \in_array($target, $this->allowedTransitions(), true);
    }
}
