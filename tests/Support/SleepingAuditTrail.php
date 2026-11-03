<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;

/**
 * Doublure de test de concurrence : à la PREMIÈRE écriture d'audit — donc après l'acquisition des verrous et la mutation,
 * avant le commit — elle dépose un fichier marqueur puis attend. Le test sait ainsi que l'opération tient réellement ses
 * verrous au moment où il lance l'opération concurrente.
 */
final class SleepingAuditTrail implements AuditTrail
{
    private bool $held = false;

    public function __construct(private readonly AuditTrail $inner, private readonly string $markerFile, private readonly float $seconds)
    {
    }

    public function record(AuditEvent $event): void
    {
        if (!$this->held) {
            $this->held = true;
            file_put_contents($this->markerFile, 'holding');
            usleep((int) ($this->seconds * 1_000_000));
        }
        $this->inner->record($event);
    }
}
