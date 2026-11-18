<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use Symfony\Component\Uid\Uuid;

interface CwsPermissionGrantRepository
{
    public function add(CwsPermissionGrant $grant): void;

    /** Attribution ACTIVE d'un compte pour une permission, sans verrou (lecture de contrôle d'accès). */
    public function findActive(Uuid $userId, CwsPermission $permission): ?CwsPermissionGrant;

    /** Même lecture, avec un verrou d'écriture : à appeler dans une transaction avant d'attribuer, de révoquer ou d'exécuter une fonction sensible. */
    public function lockActive(Uuid $userId, CwsPermission $permission): ?CwsPermissionGrant;

    /** @return list<CwsPermissionGrant> toutes les attributions (actives et révoquées), la plus récente d'abord */
    public function all(): array;
}
