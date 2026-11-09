<?php

declare(strict_types=1);

namespace App\Permit\Application\Result;

/**
 * Lien émis : le SECRET n'existe ici que pour être affiché UNE fois à l'agent (il n'est ni enregistré, ni journalisé, ni auditable).
 * `__debugInfo` le masque pour qu'un var_dump ou une trace ne le divulgue pas.
 */
final readonly class IssuedAccessLink
{
    public function __construct(
        #[\SensitiveParameter] public string $secret,
        public string $linkId,
        public ?\DateTimeImmutable $expiresAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['secret' => '[masqué]', 'linkId' => $this->linkId, 'expiresAt' => $this->expiresAt];
    }
}
