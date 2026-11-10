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
     * @param bool                                                   $authorizationRevoked l'autorisation visée est révoquée (message de révocation exact) ; `authorizationMunicipality` : sa commune ; `authorizationFrom` /
     *                                                                                     `authorizationUntil` : sa période COURANTE (fin nulle : jusqu'à la clôture), `timezone` celui de l'événement
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
        public bool $authorizationRevoked = false,
        public ?string $authorizationMunicipality = null,
        public ?\DateTimeImmutable $authorizationFrom = null,
        public ?\DateTimeImmutable $authorizationUntil = null,
        public string $timezone = '',
        public ?\DateTimeImmutable $revisionFrom = null,
        public ?\DateTimeImmutable $revisionUntil = null,
        public bool $revisionKnown = false,
        /** @var list<string> */
        public array $addedZones = [],
        /** @var list<string> */
        public array $removedZones = [],
        /** @var list<string> */
        public array $reinstatedZones = [],
        public bool $zonesChangedSince = false,
    ) {
    }

    /**
     * La période de l'autorisation a-t-elle CHANGÉ entre la décision (révision annoncée) et l'envoi ? Alors le message ne prétend pas décrire le résultat de CETTE
     * révision (« prolongé jusqu'à… ») : il dit que la période a été modifiée et donne la période d'AUJOURD'HUI. Faux si la révision n'est pas connue.
     */
    public function periodChangedSinceDecision(): bool
    {
        if (!$this->revisionKnown) {
            return false;
        }

        return $this->revisionFrom?->getTimestamp() !== $this->authorizationFrom?->getTimestamp() || $this->revisionUntil?->getTimestamp() !== $this->authorizationUntil?->getTimestamp();
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
