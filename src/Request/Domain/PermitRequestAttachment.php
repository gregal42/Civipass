<?php

declare(strict_types=1);

namespace App\Request\Domain;

use App\Shared\Domain\DomainRuleViolation;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Justificatif d'un dossier (S4) : métadonnées seules ; le fichier est stocké en privé sous une clé opaque (stockage et
 * vérification du MIME réel : incrément 2). L'entité n'accepte que les formats et la taille validés.
 */
#[ORM\Entity]
#[ORM\Table(name: 'request_permit_request_attachment')]
#[ORM\UniqueConstraint(name: 'uniq_request_attachment_storage_key', columns: ['storage_key'])]
class PermitRequestAttachment
{
    public const MAX_PER_REQUEST = 5;
    public const MAX_SIZE_BYTES = 10 * 1024 * 1024;
    /** PDF, JPG/JPEG, PNG, DOC : DOCX exclu. */
    public const ALLOWED_MIME_TYPES = ['application/pdf', 'image/jpeg', 'image/png', 'application/msword'];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $originalName;

    #[ORM\Column(type: Types::STRING, length: 120)]
    private string $mimeType;

    #[ORM\Column(type: Types::INTEGER)]
    private int $sizeBytes;

    #[ORM\Column(type: Types::STRING, length: 100, options: ['collation' => 'utf8mb4_bin'])]
    private string $storageKey;

    /**
     * @internal construit uniquement par PermitRequest::attach()
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: PermitRequest::class, inversedBy: 'attachments', fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'request_id', nullable: false, onDelete: 'RESTRICT')]
        private PermitRequest $request,
        string $originalName,
        string $mimeType,
        int $sizeBytes,
        string $storageKey,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $uploadedAt,
    ) {
        $originalName = trim($originalName);
        if ('' === $originalName || mb_strlen($originalName) > 255) {
            throw new DomainRuleViolation('Le nom du justificatif est obligatoire (255 caractères maximum).');
        }
        if (!\in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new DomainRuleViolation('Format de justificatif non accepté : PDF, JPG, PNG ou DOC.');
        }
        if ($sizeBytes < 1 || $sizeBytes > self::MAX_SIZE_BYTES) {
            throw new DomainRuleViolation('Un justificatif ne peut pas dépasser 10 Mo.');
        }
        if (1 !== preg_match('/^[A-Za-z0-9_\-\/.]{1,100}$/', $storageKey)) {
            throw new DomainRuleViolation('Clé de stockage invalide.');
        }
        $this->id = Uuid::v7();
        $this->originalName = $originalName;
        $this->mimeType = $mimeType;
        $this->sizeBytes = $sizeBytes;
        $this->storageKey = $storageKey;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function request(): PermitRequest
    {
        return $this->request;
    }

    public function originalName(): string
    {
        return $this->originalName;
    }

    public function mimeType(): string
    {
        return $this->mimeType;
    }

    public function sizeBytes(): int
    {
        return $this->sizeBytes;
    }

    public function storageKey(): string
    {
        return $this->storageKey;
    }

    public function uploadedAt(): \DateTimeImmutable
    {
        return $this->uploadedAt;
    }
}
