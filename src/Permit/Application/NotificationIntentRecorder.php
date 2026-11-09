<?php

declare(strict_types=1);

namespace App\Permit\Application;

use App\Permit\Domain\NotificationIntent;

/**
 * Port possédé par Permit, implémenté par le module Notification (DT-049, D11). Appelé DANS la transaction métier : l'implémentation
 * n'écrit qu'en base (jamais de SMTP, de réseau ni de flush), est idempotente par clé de dédoublonnage et partage le sort de la transaction.
 */
interface NotificationIntentRecorder
{
    public function record(NotificationIntent $intent): void;
}
