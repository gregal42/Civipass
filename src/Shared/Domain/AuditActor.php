<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use Symfony\Component\Uid\Uuid;

final readonly class AuditActor
{
    private function __construct(
        public AuditOrigin $origin,
        public ?Uuid $id,
    ) {
    }

    public static function user(Uuid $id): self
    {
        return new self(AuditOrigin::User, $id);
    }

    public static function console(): self
    {
        return new self(AuditOrigin::Console, null);
    }

    public static function system(): self
    {
        return new self(AuditOrigin::System, null);
    }
}
