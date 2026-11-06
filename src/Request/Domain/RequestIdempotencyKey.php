<?php

declare(strict_types=1);

namespace App\Request\Domain;

use App\Event\Domain\Event;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Empreinte de la clé d'idempotence d'un dépôt : une même tentative (même clé, même origine, même acteur) ne crée jamais
 * deux dossiers. L'unicité `(event_id, key_hash)` est le garde-fou ultime sous concurrence ; la clé elle-même n'est jamais
 * stockée, seulement son SHA-256.
 */
#[ORM\Entity]
#[ORM\Table(name: 'request_idempotency_key')]
#[ORM\UniqueConstraint(name: 'uniq_request_idempotency_event_key', columns: ['event_id', 'key_hash'])]
class RequestIdempotencyKey
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Event::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'event_id', nullable: false, onDelete: 'RESTRICT')]
        private Event $event,
        #[ORM\Column(type: Types::STRING, length: 64, options: ['fixed' => true, 'collation' => 'utf8mb4_bin'])]
        private string $keyHash,
        #[ORM\ManyToOne(targetEntity: PermitRequest::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'request_id', nullable: false, onDelete: 'RESTRICT')]
        private PermitRequest $request,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->id = Uuid::v7();
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function event(): Event
    {
        return $this->event;
    }

    public function keyHash(): string
    {
        return $this->keyHash;
    }

    public function request(): PermitRequest
    {
        return $this->request;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
