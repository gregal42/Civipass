<?php

declare(strict_types=1);

namespace App\Event\Domain;

use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Shared\Domain\DomainRuleViolation;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Participation d'une commune à un événement : une seule par couple (événement, commune) (DM-004).
 *
 * Chaque commune garde son autonomie (DM-002) : l'entité n'expose que des transitions sur SA participation ;
 * qui peut les déclencher (administrateur communal, gestionnaire d'événement) est vérifié par les politiques d'accès.
 * La participation organisatrice est créée ACTIVE avec l'événement ; elle ne se clôture qu'avec lui (DM-056).
 */
#[ORM\Entity]
#[ORM\Table(name: 'event_participation')]
#[ORM\UniqueConstraint(name: 'uniq_event_participation_event_municipality', columns: ['event_id', 'municipality_id'])]
#[ORM\Index(name: 'idx_event_participation_municipality_status', columns: ['municipality_id', 'status'])]
class EventParticipation
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: ParticipationStatus::class)]
    private ParticipationStatus $status;

    #[ORM\ManyToOne(targetEntity: User::class, fetch: 'LAZY')]
    #[ORM\JoinColumn(name: 'invited_by', nullable: true, onDelete: 'RESTRICT')]
    private ?User $invitedBy;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $invitationMessage;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $invitedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastInvitationSentAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $activatedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $suspendedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $suspensionReason = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        #[ORM\ManyToOne(targetEntity: Event::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'event_id', nullable: false, onDelete: 'RESTRICT')]
        private Event $event,
        #[ORM\ManyToOne(targetEntity: Municipality::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'municipality_id', nullable: false, onDelete: 'RESTRICT')]
        private Municipality $municipality,
        ParticipationStatus $status,
        ?User $invitedBy,
        ?string $invitationMessage,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->id = Uuid::v7();
        $this->status = $status;
        $this->invitedBy = $invitedBy;
        $this->invitationMessage = self::nullIfBlank($invitationMessage);
        $this->updatedAt = $createdAt;
    }

    /** Participation de la commune organisatrice : ACTIVE dès la création de l'événement (DM-053). */
    public static function forOrganizer(Event $event, \DateTimeImmutable $now): self
    {
        if ($event->status()->isClosed()) {
            throw new DomainRuleViolation('Un événement clôturé n\'accepte plus de participation.');
        }
        $participation = new self($event, $event->organizerMunicipality(), ParticipationStatus::Active, null, null, $now);
        $participation->activatedAt = $now;

        return $participation;
    }

    /**
     * Invitation d'une autre commune. Le droit d'inviter, le statut administratif de la commune invitée
     * (EventCreationPolicy::canJoinNewEvent) et l'unicité sont contrôlés par le service d'invitation.
     */
    public static function invite(Event $event, Municipality $municipality, User $invitedBy, ?string $message, \DateTimeImmutable $now): self
    {
        if ($event->status()->isClosed()) {
            throw new DomainRuleViolation('Un événement clôturé n\'accepte plus de participation.');
        }
        if ($event->isOrganizedBy($municipality)) {
            throw new DomainRuleViolation('La commune organisatrice participe déjà à son événement.');
        }
        $participation = new self($event, $municipality, ParticipationStatus::Invited, $invitedBy, $message, $now);
        $participation->invitedAt = $now;
        $participation->lastInvitationSentAt = $now;

        return $participation;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function event(): Event
    {
        return $this->event;
    }

    public function municipality(): Municipality
    {
        return $this->municipality;
    }

    public function status(): ParticipationStatus
    {
        return $this->status;
    }

    public function invitedBy(): ?User
    {
        return $this->invitedBy;
    }

    public function invitationMessage(): ?string
    {
        return $this->invitationMessage;
    }

    public function invitedAt(): ?\DateTimeImmutable
    {
        return $this->invitedAt;
    }

    public function lastInvitationSentAt(): ?\DateTimeImmutable
    {
        return $this->lastInvitationSentAt;
    }

    public function activatedAt(): ?\DateTimeImmutable
    {
        return $this->activatedAt;
    }

    /** Date de la dernière suspension connue : jamais la preuve d'une suspension en cours (voir status()). */
    public function suspendedAt(): ?\DateTimeImmutable
    {
        return $this->suspendedAt;
    }

    public function closedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    /** Motif de la dernière suspension connue : jamais la preuve d'une suspension en cours (voir status()). */
    public function suspensionReason(): ?string
    {
        return $this->suspensionReason;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isOrganizer(): bool
    {
        return $this->event->isOrganizedBy($this->municipality);
    }

    /**
     * Les zones se modifient quand la participation est ACTIVE ou SUSPENDED (DM-066) ; jamais INVITED ni CLOSED (DM-058).
     */
    public function allowsZoneEdition(): bool
    {
        return \in_array($this->status, [ParticipationStatus::Active, ParticipationStatus::Suspended], true);
    }

    /** Droits opérationnels : participation ET événement actifs (DM-056, DM-066). */
    public function isOperational(): bool
    {
        return ParticipationStatus::Active === $this->status && $this->event->isOperational();
    }

    /** Acceptation : réservée à l'administrateur communal (DM-057) ; une invitation ne se décline pas (DM-037). */
    public function accept(\DateTimeImmutable $now): void
    {
        if (ParticipationStatus::Invited !== $this->status) {
            throw new DomainRuleViolation('Seule une invitation en attente peut être acceptée.');
        }
        $this->assertEventNotClosed();
        $this->status = ParticipationStatus::Active;
        $this->activatedAt = $now;
        $this->updatedAt = $now;
    }

    public function resendInvitation(\DateTimeImmutable $now): void
    {
        if (ParticipationStatus::Invited !== $this->status) {
            throw new DomainRuleViolation('Seule une invitation en attente peut être renvoyée.');
        }
        $this->assertEventNotClosed();
        $this->lastInvitationSentAt = $now;
        $this->updatedAt = $now;
    }

    public function suspend(string $reason, \DateTimeImmutable $now): void
    {
        $reason = trim($reason);
        if ('' === $reason) {
            throw new DomainRuleViolation('Le motif de suspension est obligatoire.');
        }
        $this->transitionTo(ParticipationStatus::Suspended);
        $this->suspendedAt = $now;
        $this->suspensionReason = $reason;
        $this->updatedAt = $now;
    }

    /** Réactivation possible tant que l'événement n'est pas clôturé (DM-056). */
    public function reactivate(\DateTimeImmutable $now): void
    {
        if (ParticipationStatus::Suspended !== $this->status) {
            throw new DomainRuleViolation('Seule une participation suspendue peut être réactivée.');
        }
        $this->assertEventNotClosed();
        $this->transitionTo(ParticipationStatus::Active);
        // suspendedAt et suspensionReason restent « la dernière suspension connue » (DM-081) : seul le statut fait autorité.
        $this->updatedAt = $now;
    }

    /**
     * Clôture de sa propre participation (ACTIVE ou SUSPENDED). La participation organisatrice ne se clôture
     * qu'avec l'événement (A6.3) : voir closeWithEvent().
     */
    public function close(\DateTimeImmutable $now): void
    {
        if ($this->isOrganizer()) {
            throw new DomainRuleViolation('La participation de la commune organisatrice se clôture avec l\'événement.');
        }
        if (ParticipationStatus::Invited === $this->status) {
            throw new DomainRuleViolation('Une invitation ne se clôture pas isolément : elle est clôturée avec l\'événement.');
        }
        $this->transitionTo(ParticipationStatus::Closed);
        $this->closedAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * Clôture consécutive à celle de l'événement (déjà clôturé), dans la même transaction : s'applique aux
     * invitations jamais acceptées, à la participation organisatrice et aux participations restantes (DM-056, DM-077).
     */
    public function closeWithEvent(\DateTimeImmutable $now): void
    {
        if (!$this->event->status()->isClosed()) {
            throw new DomainRuleViolation('L\'événement doit être clôturé avant ses participations.');
        }
        $this->transitionTo(ParticipationStatus::Closed);
        $this->closedAt = $now;
        $this->updatedAt = $now;
    }

    private function transitionTo(ParticipationStatus $target): void
    {
        if (!$this->status->canTransitionTo($target)) {
            throw new DomainRuleViolation(sprintf('Transition de participation interdite : %s → %s.', $this->status->value, $target->value));
        }
        $this->status = $target;
    }

    private function assertEventNotClosed(): void
    {
        if ($this->event->status()->isClosed()) {
            throw new DomainRuleViolation('L\'événement est clôturé.');
        }
    }

    private static function nullIfBlank(?string $value): ?string
    {
        $value = null === $value ? null : trim($value);

        return '' === $value ? null : $value;
    }
}
