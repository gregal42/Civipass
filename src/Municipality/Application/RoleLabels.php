<?php

declare(strict_types=1);

namespace App\Municipality\Application;

use App\Municipality\Domain\MunicipalityRole;

/**
 * Libellés et descriptions des rôles communaux en langage non technique (DM-057) : un seul endroit pour l'interface, les e-mails et
 * les pages d'invitation.
 */
final class RoleLabels
{
    private const LABELS = [
        'MUNICIPALITY_ADMIN' => 'Administrateur communal',
        'EVENT_MANAGER' => 'Gestionnaire d’événements',
        'INSTRUCTOR' => 'Agent instructeur',
    ];

    private const DESCRIPTIONS = [
        'MUNICIPALITY_ADMIN' => 'Gère l’équipe, les événements et les zones de la commune.',
        'EVENT_MANAGER' => 'Crée et pilote les événements, invite des communes. Ne gère pas les zones.',
        'INSTRUCTOR' => 'Instruit les demandes de laissez-passer de la commune.',
    ];

    public static function label(MunicipalityRole $role): string
    {
        return self::LABELS[$role->value];
    }

    public static function description(MunicipalityRole $role): string
    {
        return self::DESCRIPTIONS[$role->value];
    }

    /**
     * @param list<MunicipalityRole> $roles
     */
    public static function join(array $roles): string
    {
        return implode(', ', array_map(self::label(...), $roles));
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return array_map(static fn (MunicipalityRole $r): array => ['value' => $r->value, 'label' => self::label($r), 'description' => self::description($r)], MunicipalityRole::cases());
    }
}
