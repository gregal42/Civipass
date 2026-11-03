<?php

declare(strict_types=1);

namespace App\Event\Domain;

use App\Event\Domain\Time\LocalDate;
use App\Event\Domain\Time\LocalTime;
use App\Event\Domain\Validity\DstChoices;
use App\Event\Domain\Validity\ValidityKind;
use App\Event\Domain\Validity\ValiditySlot;
use App\Event\Domain\Validity\Weekdays;
use App\Shared\Domain\DomainRuleViolation;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Persistance d'un créneau de validité d'une zone (DM-014 à DM-018, DM-069, DM-075).
 *
 * Aucune logique temporelle ici : la règle vit dans ValiditySlot / SlotInspector / ZoneValidityCalculator (étape 1).
 * L'entité stocke les champs LOCAUX du créneau (jamais d'UTC) et le restitue via toSlot().
 * Immuable sauf suppression logique : « modifier » un créneau = le supprimer logiquement puis en créer un nouveau
 * (historique conservé, aucun rétablissement). Un créneau supprimé est exclu des calculs (ZoneValidityCalculator).
 */
#[ORM\Entity]
#[ORM\Table(name: 'event_zone_validity')]
#[ORM\Index(name: 'idx_event_zone_validity_zone', columns: ['zone_id', 'deleted_at'])]
class EventZoneValidity
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: ValidityKind::class)]
    private ValidityKind $kind;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $startDate;

    #[ORM\Column(type: Types::TIME_IMMUTABLE)]
    private \DateTimeImmutable $startTime;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endDate;

    #[ORM\Column(type: Types::TIME_IMMUTABLE)]
    private \DateTimeImmutable $endTime;

    /** Masque lundi = 1 … dimanche = 64 ; null hors récurrence. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $weekdays;

    /**
     * @var list<array{date: string, field: string, occurrence: string}>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $dstChoices;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        #[ORM\ManyToOne(targetEntity: EventZone::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'zone_id', nullable: false, onDelete: 'RESTRICT')]
        private EventZone $zone,
        ValiditySlot $slot,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
    ) {
        $midnight = LocalTime::fromString('00:00');
        $epoch = LocalDate::fromString('1970-01-01');

        $this->id = Uuid::v7();
        $this->kind = $slot->kind;
        $this->startDate = $slot->startDate->toWallClock($midnight);
        $this->startTime = $epoch->toWallClock($slot->startTime);
        $this->endDate = $slot->endDate?->toWallClock($midnight);
        $this->endTime = $epoch->toWallClock($slot->endTime);
        $this->weekdays = $slot->weekdays?->mask;
        $this->dstChoices = $slot->dstChoices->isEmpty() ? null : $slot->dstChoices->toArray();
        $this->updatedAt = $createdAt;
    }

    /**
     * Les règles de forme du créneau sont déjà garanties par ValiditySlot ; celles qui dépendent du fuseau
     * (SlotInspector) sont vérifiées par le service avant persistance.
     */
    public static function fromSlot(EventZone $zone, ValiditySlot $slot, \DateTimeImmutable $now): self
    {
        if ($zone->isDeleted()) {
            throw new DomainRuleViolation('Cette zone est supprimée.');
        }
        if ($slot->isDeleted()) {
            throw new DomainRuleViolation('Un créneau supprimé ne peut pas être créé.');
        }

        return new self($zone, $slot, $now);
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function zone(): EventZone
    {
        return $this->zone;
    }

    public function kind(): ValidityKind
    {
        return $this->kind;
    }

    public function deletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function isDeleted(): bool
    {
        return null !== $this->deletedAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Objet métier de l'étape 1, reconstruit par ses fabriques : l'historique supprimé reste lisible
     * (deletedAt reporté) et seuls les créneaux non supprimés comptent pour ZoneValidityCalculator.
     */
    public function toSlot(): ValiditySlot
    {
        $startDate = LocalDate::fromString($this->startDate->format('Y-m-d'));
        $startTime = LocalTime::fromString($this->startTime->format('H:i'));
        $endTime = LocalTime::fromString($this->endTime->format('H:i'));
        $endDate = null === $this->endDate ? null : LocalDate::fromString($this->endDate->format('Y-m-d'));
        $choices = DstChoices::fromArray($this->dstChoices ?? []);

        $slot = match ($this->kind) {
            ValidityKind::Continuous => ValiditySlot::continuous($startDate, $startTime, $endDate ?? $startDate, $endTime, $choices),
            ValidityKind::Punctual => ValiditySlot::punctual($startDate, $startTime, $endTime, $choices),
            ValidityKind::Recurring => ValiditySlot::recurring(
                $startDate,
                $endDate ?? $startDate,
                Weekdays::fromMask($this->weekdays ?? 0),
                $startTime,
                $endTime,
                $choices,
            ),
        };

        return null === $this->deletedAt ? $slot : $slot->markDeleted($this->deletedAt);
    }

    /** Suppression logique définitive : aucun rétablissement (DM-075). */
    public function markDeleted(\DateTimeImmutable $now): void
    {
        if ($this->isDeleted()) {
            throw new DomainRuleViolation('Ce créneau est déjà supprimé.');
        }
        $this->deletedAt = $now;
        $this->updatedAt = $now;
    }
}
