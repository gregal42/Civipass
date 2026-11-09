<?php

declare(strict_types=1);

namespace App\Request\UI\Http;

/**
 * Les étapes du formulaire de demande, dans l'ordre. Le slug est celui de l'URL. Les six premières sont celles du dépôt public (SCR-020 à
 * SCR-025) ; `Duration` n'existe que pour la délivrance directe par un agent (DM-108) et s'insère avant le récapitulatif.
 */
enum PublicRequestStep: string
{
    case Identity = 'identite';
    case Situation = 'situation';
    case Zones = 'zones';
    case Travel = 'deplacement';
    case Documents = 'justificatifs';
    case Duration = 'duree';
    case Recap = 'recapitulatif';

    public function label(): string
    {
        return match ($this) {
            self::Identity => 'Identité',
            self::Situation => 'Situation',
            self::Zones => 'Zones',
            self::Travel => 'Déplacement',
            self::Documents => 'Justificatifs',
            self::Duration => 'Durée',
            self::Recap => 'Récapitulatif',
        };
    }

    /**
     * Étapes du parcours : sans « Durée » pour le dépôt public, avec elle pour la délivrance directe par un agent.
     *
     * @return list<self>
     */
    public static function sequence(bool $agent): array
    {
        return array_values(array_filter(self::cases(), static fn (self $s): bool => $agent || self::Duration !== $s));
    }

    public function previous(bool $agent = false): ?self
    {
        $cases = self::sequence($agent);
        $index = array_search($this, $cases, true);

        return \is_int($index) && $index > 0 ? $cases[$index - 1] : null;
    }

    public function next(bool $agent = false): ?self
    {
        $cases = self::sequence($agent);
        $index = array_search($this, $cases, true);

        return \is_int($index) && $index < \count($cases) - 1 ? $cases[$index + 1] : null;
    }
}
