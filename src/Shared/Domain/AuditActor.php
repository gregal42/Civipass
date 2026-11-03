<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use Symfony\Component\Uid\Uuid;

/**
 * `id` = utilisateur métier dont les droits sont exercés ; `operatorId` = opérateur CWS réellement connecté
 * lorsqu'il diffère (assistance, DM-061, DT-022). Sans opérateur, les deux coïncident.
 */
final readonly class AuditActor
{
    private function __construct(
        public AuditOrigin $origin,
        public ?Uuid $id,
        public ?Uuid $operatorId = null,
    ) {
    }

    public static function user(Uuid $id): self
    {
        return new self(AuditOrigin::User, $id);
    }

    public static function console(): self
    {
        return new self(AuditOrigin::Console, null);
    }

    public static function system(): self
    {
        return new self(AuditOrigin::System, null);
    }

    /**
     * Opérateur CWS agissant en assistance, avec ou sans compte impersoné (`$actingAs`). Préparé pour le lot
     * Administration CWS ; non persisté avant la migration des colonnes d'audit (étape 3).
     */
    public static function support(Uuid $operatorId, ?Uuid $actingAs = null): self
    {
        return new self(AuditOrigin::Support, $actingAs, $operatorId);
    }
}
