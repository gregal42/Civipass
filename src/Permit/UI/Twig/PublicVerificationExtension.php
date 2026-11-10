<?php

declare(strict_types=1);

namespace App\Permit\UI\Twig;

use App\Permit\Application\Read\PublicPeriod;
use App\Permit\Application\Read\PublicVerification;
use App\Permit\Application\Read\PublicZone;
use App\Permit\Domain\PublicVerdict;
use App\Permit\Domain\RightState;
use Twig\Attribute\AsTwigFunction;

/**
 * Présentation de la vérification publique (LOT 05.4.2) : AUCUNE règle métier, seulement la traduction d'états déjà calculés (PublicVerdict,
 * RightState) en texte, icône et classe visuelle. Chaque statut a un texte ET une icône : la couleur n'est jamais le seul indicateur.
 * Les `match` sont exhaustifs, sans cas par défaut : un nouvel état oblige à décider de son affichage.
 */
final class PublicVerificationExtension
{
    /**
     * Bandeau : « Droits actifs » dès qu'une zone est valide, vert si TOUTES le sont, ambre si une partie seulement ; neutre ou adapté sinon.
     * `tone` reprend les variantes du design system (`valid` vert, `uncertain` ambre, `invalid` rouge, `impossible` neutre).
     *
     * @return array{tone: string, icon: string, title: string, count: string, text: string}
     */
    #[AsTwigFunction('cp_vverdict')]
    public function verdict(PublicVerification $verification): array
    {
        $active = $verification->activeZoneCount;
        $total = $verification->zoneCount;
        $count = match (true) {
            0 === $total => 'Aucune zone associée',
            1 === $active => sprintf('1 zone active sur %d', $total),
            default => sprintf('%d zones actives sur %d', $active, $total),
        };
        [$tone, $icon, $title, $text] = match ($verification->verdict) {
            PublicVerdict::ActiveRights => $active === $total
                ? ['valid', 'check', 'Droits actifs', 'Toutes les zones sont actuellement autorisées.']
                : ['uncertain', 'alert', 'Droits actifs', 'Seules certaines zones sont actuellement autorisées : vérifiez la zone concernée ci-dessous.'],
            PublicVerdict::Upcoming => ['impossible', 'calendar', 'Aucun droit actif · droits à venir', 'Aucune zone n’est autorisée maintenant ; des droits commencent plus tard.'],
            PublicVerdict::Suspended => ['uncertain', 'alert', 'Droits suspendus', 'Les accès sont suspendus pour le moment. Ils peuvent redevenir actifs.'],
            PublicVerdict::EventClosed => ['impossible', 'lock', 'Événement terminé', 'L’événement est clôturé : plus aucun accès n’est autorisé.'],
            PublicVerdict::Revoked => ['invalid', 'x', 'Droits révoqués', 'Les autorisations ont été retirées.'],
            PublicVerdict::Expired => ['impossible', 'clock', 'Droits expirés', 'Les périodes d’accès sont terminées.'],
            PublicVerdict::NoActiveRights => ['invalid', 'x', 'Aucun droit actif', 'Ce laissez-passer n’autorise actuellement aucune zone.'],
        };

        return ['tone' => $tone, 'icon' => $icon, 'title' => $title, 'count' => $count, 'text' => $text];
    }

    /**
     * État d'UNE zone à l'instant de la vérification : pastille (texte compris), détail, et périodes prévues avec leur situation écrite.
     * Les périodes d'une zone dont le droit n'est pas actif sont données « à titre prévu » : jamais présentées comme un droit en cours.
     *
     * @return array{status: string, label: string, detail: string, periods: list<array{text: string, phase: string, phaseLabel: string}>}
     */
    #[AsTwigFunction('cp_vzone')]
    public function zone(PublicZone $zone, \DateTimeImmutable $at, string $timezone): array
    {
        $current = null;
        $next = null;
        foreach ($zone->periods as $period) {
            if (self::contains($period, $at)) {
                $current = $period;
            } elseif ($period->from > $at && null === $next) {
                $next = $period;
            }
        }
        $date = static fn (?\DateTimeImmutable $d): string => null === $d ? '' : self::format($d, $timezone);
        [$status, $label, $detail] = match ($zone->state) {
            RightState::Valid => ['valid', 'Valide actuellement', null === $current || null === $current->until ? 'Jusqu’à la fin de l’événement' : 'Jusqu’au '.$date($current->until)],
            RightState::NotYetValid => ['pending', 'Pas encore valide', null === $next ? 'Pas encore ouverte' : 'À partir du '.$date($next->from)],
            RightState::OutsidePeriod => ['pending', 'Hors créneau', null === $next ? 'En dehors des créneaux d’accès de la zone' : 'Prochain créneau : '.$date($next->from)],
            RightState::Expired => ['closed', 'Expirée', 'La période d’accès est terminée'],
            RightState::ZoneSuspended => ['suspended', 'Zone suspendue', 'Accès temporairement fermé par la commune'],
            RightState::ParticipationInactive => ['suspended', 'Suspendue', 'La commune ne délivre plus d’accès actifs pour cet événement'],
            RightState::CommuneInactive => ['suspended', 'Commune inactive', 'Accès suspendu par l’administration de la commune'],
            RightState::EventNotActive => ['suspended', 'Événement suspendu', 'Les accès sont fermés tant que l’événement n’est pas rouvert'],
            RightState::EventClosed => ['closed', 'Événement terminé', 'L’événement est clôturé'],
            RightState::Revoked => ['revoked', 'Révoquée', 'Cette autorisation a été retirée'],
            RightState::Removed => ['closed', 'Retirée', null === $zone->removedAt ? 'Cette zone a été retirée du laissez-passer' : 'Retirée le '.$date($zone->removedAt)],
            RightState::ZoneNotAuthorized => ['invalid', 'Non autorisée', 'Zone non comprise dans ce laissez-passer'],
        };
        $active = RightState::Valid === $zone->state;
        $periods = [];
        foreach ($zone->periods as $period) {
            [$phase, $phaseLabel] = match (true) {
                self::contains($period, $at) => $active ? ['current', 'En cours'] : ['planned', 'Prévue (droit non actif)'],
                $period->from > $at => ['future', 'À venir'],
                default => ['past', 'Passée'],
            };
            $periods[] = ['text' => self::range($period, $timezone), 'phase' => $phase, 'phaseLabel' => $phaseLabel];
        }

        return ['status' => $status, 'label' => $label, 'detail' => $detail, 'periods' => $periods];
    }

    /** Fenêtre de l'autorisation d'une commune (donnée de contexte : ce sont les périodes de chaque zone qui font foi). */
    #[AsTwigFunction('cp_vwindow')]
    public function window(\DateTimeImmutable $from, ?\DateTimeImmutable $until, string $timezone): string
    {
        return null === $until
            ? sprintf('Autorisation à partir du %s, jusqu’à la clôture de l’événement', self::format($from, $timezone))
            : sprintf('Autorisation du %s au %s', self::format($from, $timezone), self::format($until, $timezone));
    }

    #[AsTwigFunction('cp_vtimezone')]
    public function timezone(string $identifier): string
    {
        return 'Europe/Paris' === $identifier ? 'heure de Paris' : 'heure '.$identifier;
    }

    private static function contains(PublicPeriod $period, \DateTimeImmutable $at): bool
    {
        return $at >= $period->from && (null === $period->until || $at < $period->until);
    }

    private static function range(PublicPeriod $period, string $timezone): string
    {
        $zone = new \DateTimeZone($timezone);
        $from = $period->from->setTimezone($zone);
        if (null === $period->until) {
            return sprintf('À partir du %s, jusqu’à la clôture de l’événement', self::format($period->from, $timezone));
        }
        $until = $period->until->setTimezone($zone);

        return $from->format('Y-m-d') === $until->format('Y-m-d')
            ? sprintf('Le %s de %s à %s', $from->format('d/m/Y'), $from->format('H:i'), $until->format('H:i'))
            : sprintf('Du %s au %s', self::format($period->from, $timezone), self::format($period->until, $timezone));
    }

    private static function format(\DateTimeImmutable $date, string $timezone): string
    {
        return $date->setTimezone(new \DateTimeZone($timezone))->format('d/m/Y \à H:i');
    }
}
