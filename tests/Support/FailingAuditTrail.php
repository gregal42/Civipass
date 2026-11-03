<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;

/**
 * Doublure d'injection d'échec (pas de mock) : un VRAI collaborateur dont une étape échoue volontairement, pour prouver
 * que modification et audit sont annulés ensemble.
 */
/**
 * Doublures d'injection d'échec (pas de mock) : un VRAI collaborateur dont une étape échoue volontairement, pour prouver
 * que modification et audit sont annulés ensemble.
 */
final class FailingAuditTrail implements AuditTrail
{
    private int $calls = 0;

    public function __construct(private readonly AuditTrail $inner, private readonly int $failOnCall)
    {
    }

    public function record(AuditEvent $event): void
    {
        if (++$this->calls === $this->failOnCall) {
            throw new \RuntimeException(sprintf('Échec injecté à l\'écriture d\'audit n° %d.', $this->failOnCall));
        }
        $this->inner->record($event);
    }
}
