<?php

declare(strict_types=1);

namespace App\Ops\Application;

use Psr\Clock\ClockInterface;

/**
 * Mode maintenance (LOT 05.6.7) : tant que le fichier `MAINTENANCE` existe, l'enveloppe `ops/bin/run-job` refuse toute tâche planifiée (aucune purge, alerte ni vérification ne
 * s'exécute sur une base en cours de remise en état). On le lève par `app:ops:release`, qui exige un contrôle de restauration sans anomalie bloquante non reconnue.
 */
final class MaintenanceMode
{
    public function __construct(private readonly OpsSettings $settings, private readonly ClockInterface $clock)
    {
    }

    public function active(): bool
    {
        return is_file($this->settings->maintenanceFile());
    }

    /** Date d'activation (UTC), ou null si la maintenance n'est pas active ou son fichier illisible. */
    public function since(): ?\DateTimeImmutable
    {
        if (!$this->active()) {
            return null;
        }
        $data = json_decode((string) file_get_contents($this->settings->maintenanceFile()), true);

        return \is_array($data) && \is_string($data['since'] ?? null) ? new \DateTimeImmutable($data['since'], new \DateTimeZone('UTC')) : null;
    }

    public function enable(string $reason): void
    {
        $directory = $this->settings->dir();
        if (!is_dir($directory) && !@mkdir($directory, 0o750, true) && !is_dir($directory)) {
            throw new \RuntimeException('Répertoire d\'exploitation inaccessible.');
        }
        file_put_contents($this->settings->maintenanceFile(), json_encode(['since' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'), 'reason' => mb_substr($reason, 0, 200)], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));
    }

    public function release(): void
    {
        @unlink($this->settings->maintenanceFile());
    }
}
