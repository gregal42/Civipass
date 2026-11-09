<?php

declare(strict_types=1);

namespace App\Permit\UI\Twig;

use App\Permit\Domain\AuthorizationRights;
use App\Permit\Domain\RightState;
use App\Permit\Domain\ZoneRight;
use Twig\Attribute\AsTwigFunction;

/**
 * Présentation française des droits d'un laissez-passer (page bénéficiaire, PDF) : un libellé, une pastille et une phrase par zone, jamais un
 * statut global. Les instants sont des UTC ; l'affichage est dans le fuseau de l'événement. Aucun calcul de validité ici : l'état vient de
 * PermitRightsEvaluator.
 */
final class PermitExtension
{
    /**
     * @return array{status: string, label: string, detail: string}
     */
    #[AsTwigFunction('cp_zone_state')]
    public function zoneState(ZoneRight $zone, string $timezone): array
    {
        $at = static fn (?\DateTimeImmutable $d): string => null === $d ? '' : self::format($d, $timezone);

        return match ($zone->state) {
            RightState::Valid => ['status' => 'valid', 'label' => 'Valide actuellement', 'detail' => null === $zone->effectiveUntil ? 'Jusqu’à la fin de l’événement' : 'Jusqu’au '.$at($zone->effectiveUntil)],
            RightState::NotYetValid => ['status' => 'pending', 'label' => 'Pas encore valide', 'detail' => null === $zone->nextStart ? 'Pas encore ouverte' : 'À partir du '.$at($zone->nextStart)],
            RightState::OutsidePeriod => ['status' => 'pending', 'label' => 'Hors créneau', 'detail' => null === $zone->nextStart ? 'En dehors des créneaux d’accès de la zone' : 'Prochain créneau : '.$at($zone->nextStart)],
            RightState::Expired => ['status' => 'closed', 'label' => 'Expirée', 'detail' => 'La période d’accès est terminée'],
            RightState::ZoneSuspended => ['status' => 'suspended', 'label' => 'Zone suspendue', 'detail' => 'Accès temporairement fermé par la commune'],
            RightState::ParticipationInactive => ['status' => 'suspended', 'label' => 'Suspendue', 'detail' => 'La commune ne délivre plus d’accès actifs pour cet événement'],
            RightState::CommuneInactive => ['status' => 'suspended', 'label' => 'Commune inactive', 'detail' => 'Accès suspendu par l’administration de la commune'],
            RightState::EventNotActive => ['status' => 'suspended', 'label' => 'Événement suspendu', 'detail' => 'Les accès sont fermés tant que l’événement n’est pas rouvert'],
            RightState::EventClosed => ['status' => 'closed', 'label' => 'Événement terminé', 'detail' => 'L’événement est clôturé'],
            RightState::Revoked => ['status' => 'revoked', 'label' => 'Révoquée', 'detail' => 'Cette autorisation a été retirée'],
            RightState::ZoneNotAuthorized => ['status' => 'invalid', 'label' => 'Non autorisée', 'detail' => 'Zone non comprise dans ce laissez-passer'],
        };
    }

    /** Période de l'autorisation d'une commune, dans le fuseau de l'événement. */
    #[AsTwigFunction('cp_authorization_period')]
    public function period(AuthorizationRights $authorization, string $timezone): string
    {
        $from = self::format($authorization->validFrom, $timezone);

        return null === $authorization->validUntil
            ? sprintf('À partir du %s, jusqu’à la clôture de l’événement', $from)
            : sprintf('Du %s au %s', $from, self::format($authorization->validUntil, $timezone));
    }

    private static function format(\DateTimeImmutable $date, string $timezone): string
    {
        return $date->setTimezone(new \DateTimeZone($timezone))->format('d/m/Y \à H:i');
    }
}
