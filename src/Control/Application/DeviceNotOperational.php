<?php

declare(strict_types=1);

namespace App\Control\Application;

use App\Control\Domain\DeviceStatus;

/** Appareil non autorisé à recevoir des données : en attente d'approbation, désactivé ou expiré. Aucune donnée n'est construite. */
final class DeviceNotOperational extends \RuntimeException
{
    /** @param 'PENDING'|'DEACTIVATED'|'EXPIRED' $state */
    public function __construct(public readonly string $state)
    {
        parent::__construct('Appareil non autorisé.');
    }

    public static function of(\App\Control\Domain\ControlDevice $device, \DateTimeImmutable $now): self
    {
        return new self(match (true) {
            DeviceStatus::Deactivated === $device->status() => 'DEACTIVATED',
            $device->expiresAt() <= $now => 'EXPIRED',
            default => 'PENDING',
        });
    }
}
