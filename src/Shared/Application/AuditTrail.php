<?php

declare(strict_types=1);

namespace App\Shared\Application;

interface AuditTrail
{
    /**
     * Enregistre l'événement sans flusher : la transaction appartient au cas d'usage appelant.
     */
    public function record(AuditEvent $event): void;
}
