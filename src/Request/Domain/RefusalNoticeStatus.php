<?php

declare(strict_types=1);

namespace App\Request\Domain;

/**
 * État de l'information d'un demandeur refusé (LOT 08.5, DM-136). SENT = remis au serveur de messagerie, JAMAIS « reçu » ni « lu ».
 */
enum RefusalNoticeStatus: string
{
    /** En attente d'envoi ou de réessai. */
    case Pending = 'PENDING';
    case Sent = 'SENT';
    /** Aucune adresse e-mail : à informer par un autre moyen (liste « Refus à communiquer »). */
    case NoRecipient = 'NO_RECIPIENT';
    /** Échec définitif d'envoi : à informer par un autre moyen. */
    case Abandoned = 'ABANDONED';
    /** Un agent a informé le demandeur par un autre moyen (canal, auteur, date conservés). */
    case Manual = 'MANUAL';

    /** L'agent doit encore informer le demandeur. */
    public function needsAgentAction(): bool
    {
        return self::NoRecipient === $this || self::Abandoned === $this;
    }
}
