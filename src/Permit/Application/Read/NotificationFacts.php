<?php

declare(strict_types=1);

namespace App\Permit\Application\Read;

/**
 * État COURANT d'un laissez-passer tel que le distributeur de notifications en a besoin au moment d'écrire un message (LOT 05.3.3). Contient
 * l'adresse du destinataire, donc ne vit qu'en mémoire, le temps de composer le message : jamais persisté, journalisé ni copié.
 * Ni plaque, ni adresse postale, ni justificatif, ni motif (DM-117, D9).
 */
final readonly class NotificationFacts
{
    /**
     * @param list<array{municipality: string, zones: list<string>}> $communes             communes dont l'autorisation n'est pas révoquée, avec leurs zones
     * @param list<string>                                           $listedAuthorizations identifiants des autorisations qui figurent dans les `communes` (couverture d'un message)
     */
    public function __construct(
        public bool $usable,
        public string $firstName,
        public string $reference,
        public string $eventName,
        public array $communes,
        public array $listedAuthorizations,
        public bool $authorizationActive,
        #[\SensitiveParameter] private ?string $email,
    ) {
    }

    /** Adresse du destinataire (relue à l'envoi), ou `null` s'il n'y en a plus d'exploitable. */
    public function recipient(): ?string
    {
        $email = trim((string) $this->email);

        return '' === $email ? null : $email;
    }

    public function hasRecipient(): bool
    {
        return null !== $this->recipient();
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['usable' => $this->usable, 'reference' => $this->reference, 'communes' => \count($this->communes), 'email' => '[masqué]', 'firstName' => '[masqué]'];
    }
}
