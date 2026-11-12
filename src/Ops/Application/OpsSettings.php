<?php

declare(strict_types=1);

namespace App\Ops\Application;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Emplacements et adresse d'alerte de l'exploitation (LOT 05.6.7) : aucune donnée personnelle ; l'adresse d'alerte est une boîte d'exploitation, jamais celle d'une personne concernée. */
final readonly class OpsSettings
{
    public function __construct(
        #[Autowire('%env(OPS_DIR)%')] private string $dir,
        #[Autowire('%env(OPS_ALERT_EMAIL)%')] private string $alertEmail,
    ) {
    }

    public function dir(): string
    {
        return rtrim($this->dir, '/');
    }

    public function alertEmail(): string
    {
        return trim($this->alertEmail);
    }

    public function maintenanceFile(): string
    {
        return $this->dir().'/MAINTENANCE';
    }

    public function heartbeatDir(): string
    {
        return $this->dir().'/heartbeat';
    }
}
