<?php

declare(strict_types=1);

namespace App\Rights\Domain;

/** Motif codé et obligatoire d'un refus (aucun texte libre : aucune donnée personnelle dans le registre). */
enum RightsRefusalReason: string
{
    case LegalHold = 'LEGAL_HOLD';
    case EventOngoing = 'EVENT_ONGOING';
    case RetentionJustified = 'RETENTION_JUSTIFIED';
    case LegalObligation = 'LEGAL_OBLIGATION';
    case IdentityNotEstablished = 'IDENTITY_NOT_ESTABLISHED';
    case OtherGrounds = 'OTHER_GROUNDS';

    public function label(): string
    {
        return match ($this) {
            self::LegalHold => 'Gel de conservation actif',
            self::EventOngoing => 'Événement non clos',
            self::RetentionJustified => 'Conservation justifiée (preuve, contestation)',
            self::LegalObligation => 'Obligation légale de conservation',
            self::IdentityNotEstablished => 'Identité non établie',
            self::OtherGrounds => 'Autre motif légitime',
        };
    }
}
