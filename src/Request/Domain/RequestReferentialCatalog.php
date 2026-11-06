<?php

declare(strict_types=1);

namespace App\Request\Domain;

/**
 * Valeurs initiales validées des référentiels de demande (DM-097, DM-098, DT-039), dans l'ordre d'affichage
 * (positions 10, 20, …). Ne sert qu'à l'initialisation, jamais à écraser une entrée existante. Les libellés sont neutres
 * et identiques dans le parcours citoyen et la saisie agent.
 */
final class RequestReferentialCatalog
{
    /** @var list<array{code: string, label: string, needsDetail: bool}> */
    public const MOTIFS = [
        ['code' => 'home', 'label' => 'Accès au domicile', 'needsDetail' => false],
        ['code' => 'workplace', 'label' => 'Accès au lieu de travail', 'needsDetail' => false],
        ['code' => 'professional', 'label' => 'Intervention professionnelle', 'needsDetail' => false],
        ['code' => 'relative_assistance', 'label' => 'Assistance à un proche', 'needsDetail' => false],
        ['code' => 'priority', 'label' => 'Intervention prioritaire', 'needsDetail' => false],
        ['code' => 'land_or_holding', 'label' => 'Accès à une exploitation ou un terrain', 'needsDetail' => false],
        ['code' => 'other', 'label' => 'Autre', 'needsDetail' => true],
    ];

    /** @var list<array{code: string, label: string}> */
    public const CATEGORIES = [
        ['code' => 'private_person', 'label' => 'Particuliers'],
        ['code' => 'professional', 'label' => 'Professionnels'],
        ['code' => 'priority_responder', 'label' => 'Intervenants prioritaires'],
        ['code' => 'public_agent', 'label' => 'Agents publics'],
        ['code' => 'volunteer', 'label' => 'Bénévoles'],
    ];

    private function __construct()
    {
    }
}
