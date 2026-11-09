<?php

declare(strict_types=1);

namespace App\Permit\Application;

use App\Permit\Domain\ManualNotificationOutcome;
use Symfony\Component\Uid\Uuid;

/**
 * Port possédé par Permit, implémenté par Notification (LOT 05.3.4) : enregistre une NOUVELLE intention de mise à disposition à la demande d'un
 * agent. Appelé dans la transaction de l'appelant, SOUS le verrou du laissez-passer : aucun envoi, aucun flush, idempotent par `$nonce`
 * (identifiant du formulaire), jamais deux messages de mise à disposition en attente en même temps.
 */
interface ManualNotificationRequester
{
    public function request(Uuid $permitId, Uuid $nonce, \DateTimeImmutable $now): ManualNotificationOutcome;
}
