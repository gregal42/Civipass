<?php

declare(strict_types=1);

namespace App\Notification\Application;

use App\Notification\Domain\NotificationOutboxRepository;
use App\Notification\Domain\NotificationSecretRepository;
use App\Notification\Domain\SecretDecryptionFailed;
use Symfony\Component\Uid\Uuid;

/**
 * Restitue le secret d'un message au moment de composer l'e-mail (distributeur du 05.3.3). Le clair n'existe qu'en mémoire, le temps de
 * l'appel ; l'appelant ne doit ni le journaliser ni le copier. Le déchiffrement exige que le message, son type et son laissez-passer soient
 * ceux sous lesquels le secret a été chiffré.
 */
final readonly class SecretVault
{
    public function __construct(
        private NotificationOutboxRepository $outbox,
        private NotificationSecretRepository $secrets,
        private SecretCipher $cipher,
        private KeyRingProvider $keys,
    ) {
    }

    /**
     * @throws SecretDecryptionFailed secret absent, clé indisponible, données altérées ou liées à un autre message
     */
    public function reveal(Uuid $outboxId): string
    {
        $row = $this->outbox->find($outboxId);
        $secret = $this->secrets->findByOutbox($outboxId);
        $ring = $this->keys->keyRing();
        if (null === $row || null === $secret || !$ring->isUsable()) {
            throw new SecretDecryptionFailed('Déchiffrement impossible.');
        }

        return $this->cipher->decrypt($ring, $secret->encrypted(), $row->id(), $row->type(), $row->permitId());
    }
}
