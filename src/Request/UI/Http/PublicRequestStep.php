<?php

declare(strict_types=1);

namespace App\Request\UI\Http;

/** Les six étapes du formulaire public (SCR-020 à SCR-025), dans l'ordre. Le slug est celui de l'URL. */
enum PublicRequestStep: string
{
    case Identity = 'identite';
    case Situation = 'situation';
    case Zones = 'zones';
    case Travel = 'deplacement';
    case Documents = 'justificatifs';
    case Recap = 'recapitulatif';

    public function label(): string
    {
        return match ($this) {
            self::Identity => 'Identité',
            self::Situation => 'Situation',
            self::Zones => 'Zones',
            self::Travel => 'Déplacement',
            self::Documents => 'Justificatifs',
            self::Recap => 'Récapitulatif',
        };
    }

    public function previous(): ?self
    {
        $cases = self::cases();
        $index = array_search($this, $cases, true);

        return \is_int($index) && $index > 0 ? $cases[$index - 1] : null;
    }

    public function next(): ?self
    {
        $cases = self::cases();
        $index = array_search($this, $cases, true);

        return \is_int($index) && $index < \count($cases) - 1 ? $cases[$index + 1] : null;
    }
}
