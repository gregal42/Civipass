<?php

declare(strict_types=1);

namespace App\Permit\Domain;

/**
 * Statut GLOBAL affiché par la vérification publique (LOT 05.4, DM-115) : dérivé des états de zone, jamais stocké, jamais un second moteur
 * de droits. « Droits actifs » dès qu'UNE zone est valide (le détail par zone et le compteur N/M disent lesquelles) : aucune priorité ne
 * masque un droit actif. Les zones retirées n'y entrent pas.
 */
enum PublicVerdict: string
{
    case ActiveRights = 'ACTIVE_RIGHTS';
    case Upcoming = 'UPCOMING';
    case Suspended = 'SUSPENDED';
    case EventClosed = 'EVENT_CLOSED';
    case Revoked = 'REVOKED';
    case Expired = 'EXPIRED';
    case NoActiveRights = 'NO_ACTIVE_RIGHTS';

    /**
     * Ordre : 1. au moins une zone valide ; 2. sinon une zone à venir (pas encore valide ou hors créneau) ; 3. sinon une zone suspendue
     * (susceptible de redevenir active) ; 4. toutes les zones sur événement clôturé ; 5. toutes révoquées ; 6. toutes expirées ; 7. le reste
     * (états définitifs mélangés, aucune zone).
     *
     * @param list<RightState> $states états des zones actuellement associées (hors zones retirées)
     */
    public static function derive(array $states): self
    {
        $states = array_values(array_filter($states, static fn (RightState $s): bool => RightState::Removed !== $s));
        $has = static fn (RightState ...$wanted): bool => [] !== array_filter($states, static fn (RightState $s): bool => \in_array($s, $wanted, true));
        $all = static fn (RightState $wanted): bool => [] !== $states && [] === array_filter($states, static fn (RightState $s): bool => $wanted !== $s);

        return match (true) {
            $has(RightState::Valid) => self::ActiveRights,
            $has(RightState::NotYetValid, RightState::OutsidePeriod) => self::Upcoming,
            $has(RightState::ZoneSuspended, RightState::ParticipationInactive, RightState::CommuneInactive, RightState::EventNotActive) => self::Suspended,
            $all(RightState::EventClosed) => self::EventClosed,
            $all(RightState::Revoked) => self::Revoked,
            $all(RightState::Expired) => self::Expired,
            default => self::NoActiveRights,
        };
    }
}
