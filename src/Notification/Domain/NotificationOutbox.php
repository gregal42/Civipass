<?php

declare(strict_types=1);

namespace App\Notification\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Intention de notification persistée dans la transaction métier (LOT 05.3.1, DT-049). Identifiants et dates seulement : ni adresse, ni nom,
 * ni plaque, ni secret, ni contenu de message (l'adresse est relue à l'envoi ; le secret chiffré vivra dans une table séparée, 05.3.2).
 *
 * `NO_RECIPIENT` est posé dès l'enregistrement quand aucune adresse n'existe : une adresse corrigée plus tard ne déclenche aucun envoi (D8).
 * Les transitions d'envoi (réservation, succès, abandon) relèvent du distributeur (05.3.2/05.3.3).
 */
#[ORM\Entity]
#[ORM\Table(name: 'notification_outbox')]
#[ORM\UniqueConstraint(name: 'uniq_notification_outbox_key', columns: ['deduplication_key'])]
#[ORM\Index(name: 'idx_notification_outbox_dispatch', columns: ['status', 'available_at'])]
#[ORM\Index(name: 'idx_notification_outbox_processed', columns: ['processed_at'])]
#[ORM\Index(name: 'idx_notification_outbox_permit', columns: ['permit_id'])]
class NotificationOutbox
{
    public const MANUAL_KEY_PREFIX = 'PERMIT_ISSUED_MANUAL:';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: OutboxStatus::class)]
    private OutboxStatus $status;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true, 'default' => 0])]
    private int $attempts = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $leasedUntil = null;

    /** Début du plafond de 24 heures des erreurs SMTP : premier essai réellement tenté (jamais une attente de clé). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $firstAttemptAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $processedAt = null;

    /**
     * Pour un message ENVOYÉ (mise à disposition ou extension) : identifiants des autorisations qui figuraient dans son contenu (aucune donnée
     * personnelle). Sert à décider qu'une extension est redondante (DÉJÀ mentionnée) et jamais autrement.
     *
     * @var list<string>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $coveredAuthorizations = null;

    /**
     * Révision qui a produit cette ligne (LOT 05.5.1, DM-119 §10) : identifiant seulement. Nul pour les lignes produites par une approbation
     * (mise à disposition, nouvelle commune) : leur couverture se prouve par les autorisations (`coveredAuthorizations`).
     */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $revisionId = null;

    /**
     * Pour un message ENVOYÉ qui annonce des RÉVISIONS : identifiants des révisions dont il décrit l'effet. Preuve distincte de la couverture des
     * autorisations : une révision n'est jamais jugée redondante parce que son autorisation figurait dans un message déjà envoyé.
     *
     * @var list<string>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $coveredRevisions = null;

    /** Code court (`OutboxErrorCode`, jamais un message ni une exception). */
    #[ORM\Column(type: Types::STRING, length: 40, nullable: true)]
    private ?string $lastErrorCode = null;

    private function __construct(
        #[ORM\Column(type: Types::STRING, length: 100, options: ['collation' => 'utf8mb4_bin'])]
        private string $deduplicationKey,
        #[ORM\Column(type: Types::STRING, length: 30, enumType: NotificationType::class)]
        private NotificationType $type,
        #[ORM\Column(type: UuidType::NAME)]
        private Uuid $permitId,
        #[ORM\Column(type: UuidType::NAME, nullable: true)]
        private ?Uuid $authorizationId,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $occurredAt,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $availableAt,
        OutboxStatus $status,
    ) {
        $this->id = Uuid::v7();
        $this->status = $status;
        $this->processedAt = OutboxStatus::NoRecipient === $status ? $occurredAt : null;
    }

    public static function record(string $deduplicationKey, NotificationType $type, Uuid $permitId, ?Uuid $authorizationId, \DateTimeImmutable $occurredAt, bool $hasRecipient, ?Uuid $revisionId = null): self
    {
        $row = new self(
            $deduplicationKey,
            $type,
            $permitId,
            $authorizationId,
            $occurredAt,
            SchedulePolicy::availableAt($type, $occurredAt),
            $hasRecipient ? OutboxStatus::Pending : OutboxStatus::NoRecipient,
        );
        $row->revisionId = $revisionId;

        return $row;
    }

    /**
     * Nouvelle mise à disposition demandée par un agent (LOT 05.3.4) : clé propre au formulaire (`$nonce`), disponible aussitôt. Même type que la
     * première, donc même contenu (avec un NOUVEAU lien, créé à l'envoi) ; un envoi manuel ne révoque aucun lien.
     */
    public static function requestManual(Uuid $permitId, Uuid $nonce, \DateTimeImmutable $now): self
    {
        return new self(self::MANUAL_KEY_PREFIX.$permitId->toRfc4122().':'.$nonce->toRfc4122(), NotificationType::PermitIssued, $permitId, null, $now, $now, OutboxStatus::Pending);
    }

    public function isManualRequest(): bool
    {
        return str_starts_with($this->deduplicationKey, self::MANUAL_KEY_PREFIX);
    }

    public function category(): ChangeCategory
    {
        return $this->type->category();
    }

    /** @return list<string> */
    public function coveredAuthorizations(): array
    {
        return $this->coveredAuthorizations ?? [];
    }

    public function revisionId(): ?Uuid
    {
        return $this->revisionId;
    }

    /** @return list<string> */
    public function coveredRevisions(): array
    {
        return $this->coveredRevisions ?? [];
    }

    /** Regroupement : nouvelle échéance commune d'un changement ordinaire encore en attente. */
    public function rescheduleTo(\DateTimeImmutable $availableAt): void
    {
        $this->assertPending();
        $this->availableAt = $availableAt;
    }

    /** Non envoyé volontairement (redondant, inexact, premier message non remis) : définitif, motif conservé, jamais compté comme un échec. */
    public function suppress(OutboxErrorCode $reason, \DateTimeImmutable $now): void
    {
        if ($this->status->isFinal()) {
            throw new \App\Shared\Domain\DomainRuleViolation('Cette notification est déjà dans un état définitif.');
        }
        $this->status = OutboxStatus::Suppressed;
        $this->lastErrorCode = $reason->value;
        $this->processedAt = $now;
        $this->leasedUntil = null;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function status(): OutboxStatus
    {
        return $this->status;
    }

    public function type(): NotificationType
    {
        return $this->type;
    }

    public function deduplicationKey(): string
    {
        return $this->deduplicationKey;
    }

    public function permitId(): Uuid
    {
        return $this->permitId;
    }

    public function authorizationId(): ?Uuid
    {
        return $this->authorizationId;
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function availableAt(): \DateTimeImmutable
    {
        return $this->availableAt;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function leasedUntil(): ?\DateTimeImmutable
    {
        return $this->leasedUntil;
    }

    public function lastErrorCode(): ?string
    {
        return $this->lastErrorCode;
    }

    public function hasError(OutboxErrorCode $code): bool
    {
        return $this->lastErrorCode === $code->value;
    }

    /** Ligne en attente d'une clé : l'anomalie est signalée, AUCUNE tentative n'est consommée. */
    public function flagCryptoUnavailable(): void
    {
        $this->assertPending();
        $this->lastErrorCode = OutboxErrorCode::CryptoUnavailable->value;
    }

    public function clearError(): void
    {
        $this->assertPending();
        $this->lastErrorCode = null;
    }

    /** Échec définitif (sans nouvel essai). */
    public function abandon(OutboxErrorCode $code, \DateTimeImmutable $now): void
    {
        if ($this->status->isFinal()) {
            throw new \App\Shared\Domain\DomainRuleViolation('Cette notification est déjà dans un état définitif.');
        }
        $this->status = OutboxStatus::Abandoned;
        $this->lastErrorCode = $code->value;
        $this->processedAt = $now;
        $this->leasedUntil = null;
    }

    /** Prêt à être traité : en attente et échu, ou réservé dont le bail a expiré (processus mort ou trop lent). */
    public function isDispatchable(\DateTimeImmutable $now): bool
    {
        return (OutboxStatus::Pending === $this->status && $this->availableAt <= $now)
            || (OutboxStatus::Reserved === $this->status && null !== $this->leasedUntil && $this->leasedUntil <= $now);
    }

    /** Réserve le message pour UN distributeur pendant `$leaseUntil` ; l'essai est compté dès la réservation (un arrêt brutal le consomme). */
    public function reserve(\DateTimeImmutable $now, \DateTimeImmutable $leaseUntil): void
    {
        if (!$this->isDispatchable($now)) {
            throw new \App\Shared\Domain\DomainRuleViolation('Cette notification n\'est pas disponible pour un envoi.');
        }
        $this->status = OutboxStatus::Reserved;
        $this->leasedUntil = $leaseUntil->setTime((int) $leaseUntil->format('G'), (int) $leaseUntil->format('i'), (int) $leaseUntil->format('s')); // sans microsecondes, comme en base
        ++$this->attempts;
        $this->firstAttemptAt ??= $now;
    }

    /** Ce distributeur détient-il encore la réservation (le bail n'a pas été repris par un autre) ? */
    public function holdsLease(\DateTimeImmutable $leaseUntil): bool
    {
        // Comparaison à la SECONDE : la base ne conserve pas les microsecondes (`DATETIME`), une horloge réelle en a.
        return OutboxStatus::Reserved === $this->status && null !== $this->leasedUntil && $this->leasedUntil->getTimestamp() === $leaseUntil->getTimestamp();
    }

    /**
     * @param list<string> $coveredAuthorizations autorisations listées dans le message envoyé
     * @param list<string> $coveredRevisions      révisions dont le message décrit l'effet
     */
    public function completeSent(\DateTimeImmutable $now, array $coveredAuthorizations = [], array $coveredRevisions = []): void
    {
        $this->assertReserved();
        $this->coveredAuthorizations = $coveredAuthorizations;
        $this->coveredRevisions = [] === $coveredRevisions ? null : $coveredRevisions;
        $this->status = OutboxStatus::Sent;
        $this->processedAt = $now;
        $this->leasedUntil = null;
        $this->lastErrorCode = null;
    }

    /** Erreur temporaire : retour en attente, nouvel essai à `$availableAt`. */
    public function retryAt(\DateTimeImmutable $availableAt, OutboxErrorCode $code): void
    {
        $this->assertReserved();
        $this->status = OutboxStatus::Pending;
        $this->availableAt = $availableAt;
        $this->leasedUntil = null;
        $this->lastErrorCode = $code->value;
    }

    /** Clé indisponible au moment d'envoyer : l'essai n'est pas consommé, le message attend. */
    public function releaseWithoutAttempt(): void
    {
        $this->assertReserved();
        $this->status = OutboxStatus::Pending;
        $this->leasedUntil = null;
        $this->attempts = max(0, $this->attempts - 1);
        if (0 === $this->attempts) {
            $this->firstAttemptAt = null;
        }
        $this->lastErrorCode = OutboxErrorCode::CryptoUnavailable->value;
    }

    /** L'adresse a disparu avant l'envoi : état définitif, sans envoi automatique ultérieur (D8). */
    public function markNoRecipient(\DateTimeImmutable $now): void
    {
        if ($this->status->isFinal()) {
            throw new \App\Shared\Domain\DomainRuleViolation('Cette notification est déjà dans un état définitif.');
        }
        $this->status = OutboxStatus::NoRecipient;
        $this->processedAt = $now;
        $this->leasedUntil = null;
        $this->lastErrorCode = null;
    }

    public function firstAttemptAt(): ?\DateTimeImmutable
    {
        return $this->firstAttemptAt;
    }

    private function assertReserved(): void
    {
        if (OutboxStatus::Reserved !== $this->status) {
            throw new \App\Shared\Domain\DomainRuleViolation('Cette notification n\'est pas réservée.');
        }
    }

    private function assertPending(): void
    {
        if (OutboxStatus::Pending !== $this->status) {
            throw new \App\Shared\Domain\DomainRuleViolation('Cette notification n\'est pas en attente.');
        }
    }

    public function processedAt(): ?\DateTimeImmutable
    {
        return $this->processedAt;
    }
}
