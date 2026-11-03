<?php

declare(strict_types=1);

namespace App\Event\Domain;

/**
 * Types d'événement initiaux validés (DT-023), dans l'ordre d'affichage. Données de référence évolutives :
 * ce catalogue ne sert qu'à l'initialisation, jamais à écraser un type existant.
 */
final class EventTypeCatalog
{
    /** @var list<array{code: string, label: string}> */
    public const DEFAULTS = [
        ['code' => 'fire', 'label' => 'Incendie'],
        ['code' => 'flood', 'label' => 'Inondation'],
        ['code' => 'storm', 'label' => 'Tempête / événement climatique'],
        ['code' => 'accident', 'label' => 'Accident / incident majeur'],
        ['code' => 'security', 'label' => 'Sécurité publique'],
        ['code' => 'sport', 'label' => 'Manifestation sportive'],
        ['code' => 'cultural', 'label' => 'Manifestation culturelle'],
        ['code' => 'works', 'label' => 'Travaux / intervention technique'],
        ['code' => 'other', 'label' => 'Autre événement'],
    ];

    private function __construct()
    {
    }
}
