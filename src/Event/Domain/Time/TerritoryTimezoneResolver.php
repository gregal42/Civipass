<?php

declare(strict_types=1);

namespace App\Event\Domain\Time;

use App\Municipality\Domain\InseeCode;

/**
 * Fuseau proposé pour une commune organisatrice (DM-062). Retourne null quand le fuseau ne peut pas
 * être déterminé sans hypothèse : l'utilisateur le choisit alors explicitement dans la liste blanche.
 *
 * - Hexagone et Corse : Europe/Paris.
 * - Collectivités d'outre-mer à fuseau unique : correspondance par préfixe INSEE de 3 chiffres.
 * - Polynésie française (987) : trois fuseaux ; aucune hypothèse, uniquement les exceptions fournies.
 * - 984 (TAAF) et 989 (Clipperton) : pas de commune, non résolus.
 */
final readonly class TerritoryTimezoneResolver
{
    private const BY_PREFIX = [
        '971' => 'America/Guadeloupe',
        '972' => 'America/Martinique',
        '973' => 'America/Cayenne',
        '974' => 'Indian/Reunion',
        '975' => 'America/Miquelon',
        '976' => 'Indian/Mayotte',
        '977' => 'America/St_Barthelemy',
        '978' => 'America/Marigot',
        '986' => 'Pacific/Wallis',
        '988' => 'Pacific/Noumea',
    ];

    public function __construct(private CommuneTimezoneOverrides $overrides = new EmptyCommuneTimezoneOverrides())
    {
    }

    public function resolve(InseeCode $code): ?EventTimezone
    {
        $override = $this->overrides->find($code);
        if (null !== $override) {
            return $override;
        }

        $value = $code->value;
        if (isset(self::BY_PREFIX[substr($value, 0, 3)])) {
            return EventTimezone::fromIdentifier(self::BY_PREFIX[substr($value, 0, 3)]);
        }
        if (1 === preg_match('/^(0[1-9]|[1-8]\d|9[0-5]|2A|2B)\d{3}$/', $value)) {
            return EventTimezone::fromIdentifier('Europe/Paris');
        }

        return null;
    }
}
