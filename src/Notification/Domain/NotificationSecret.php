<?php

declare(strict_types=1);

namespace App\Notification\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Secret du lien bénéficiaire, CHIFFRÉ, dans une table séparée de l'outbox (LOT 05.3.2, DT-049 révisée, D1). Une seule ligne par message
 * (`UNIQUE(outbox_id)`) et par lien (`UNIQUE(access_token_id)`) : un rejeu ne peut pas créer un second lien. Supprimée physiquement après
 * traitement définitif, et au plus tard à `expires_at` (48 heures). Jamais de clair, jamais de journal.
 */
#[ORM\Entity]
#[ORM\Table(name: 'notification_secret')]
#[ORM\UniqueConstraint(name: 'uniq_notification_secret_outbox', columns: ['outbox_id'])]
#[ORM\UniqueConstraint(name: 'uniq_notification_secret_token', columns: ['access_token_id'])]
#[ORM\Index(name: 'idx_notification_secret_expires', columns: ['expires_at'])]
class NotificationSecret
{
    public const MAX_AGE = 'PT48H';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: Types::STRING, length: 32)]
    private string $keyId;

    #[ORM\Column(type: Types::BINARY, length: 24, options: ['fixed' => true])]
    private string $nonce;

    #[ORM\Column(type: Types::BINARY, length: 128)]
    private string $ciphertext;

    public function __construct(
        #[ORM\Column(type: UuidType::NAME)]
        private Uuid $outboxId,
        #[ORM\Column(type: UuidType::NAME)]
        private Uuid $accessTokenId,
        EncryptedSecret $encrypted,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->id = Uuid::v7();
        $this->expiresAt = $createdAt->add(new \DateInterval(self::MAX_AGE));
        $this->keyId = $encrypted->keyId;
        $this->nonce = $encrypted->nonce;
        $this->ciphertext = $encrypted->ciphertext;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function outboxId(): Uuid
    {
        return $this->outboxId;
    }

    public function accessTokenId(): Uuid
    {
        return $this->accessTokenId;
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function encrypted(): EncryptedSecret
    {
        return new EncryptedSecret($this->keyId, $this->nonce, $this->ciphertext);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['id' => $this->id, 'outboxId' => $this->outboxId, 'keyId' => $this->keyId, 'secret' => '[masqué]'];
    }
}
