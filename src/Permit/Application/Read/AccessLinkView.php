<?php

declare(strict_types=1);

namespace App\Permit\Application\Read;

/** Un lien bénéficiaire tel que le voit l'agent : jamais son secret ni son empreinte (le secret n'est pas récupérable). */
final readonly class AccessLinkView
{
    public const ACTIVE = 'active';
    public const REVOKED = 'revoked';
    public const EXPIRED = 'expired';

    public function __construct(
        public string $id,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $expiresAt,
        public ?\DateTimeImmutable $revokedAt,
        public string $state,
        public bool $issuedByOwnMunicipality,
        public bool $issuedBySystem = false,
    ) {
    }
}
