<?php

declare(strict_types=1);

namespace App\Permit\Domain;

/**
 * Motifs d'une PROLONGATION ou d'une REPRISE d'autorisation (DM-119 §8, catalogue validé le 10 novembre 2026 pour le LOT 05.5.2). Facultatif pour une
 * prolongation ordinaire, OBLIGATOIRE pour une reprise après expiration. Jamais exposé au contrôle public, ni dans une notification.
 */
enum PeriodReason: string
{
    case NeedExtended = 'NEED_EXTENDED';
    case EventExtended = 'EVENT_EXTENDED';
    case InitialDurationInsufficient = 'INITIAL_DURATION_INSUFFICIENT';
    case BeneficiaryRequest = 'BENEFICIARY_REQUEST';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::NeedExtended => 'Besoin prolongé',
            self::EventExtended => 'Événement prolongé',
            self::InitialDurationInsufficient => 'Durée initiale insuffisante',
            self::BeneficiaryRequest => 'Demande du bénéficiaire',
            self::Other => 'Autre motif',
        };
    }

    public function needsComment(): bool
    {
        return self::Other === $this;
    }
}
