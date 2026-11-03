<?php

declare(strict_types=1);

namespace App\Event\Domain\Validity;

use App\Event\Domain\Time\LocalDate;
use App\Event\Domain\Time\Occurrence;
use App\Shared\Domain\DomainRuleViolation;

/**
 * Choix d'occurrence horaire d'un créneau (DT-020), sérialisés en JSON : [{"date","field","occurrence"}].
 * La cohérence avec le créneau est garantie par SlotInspector.
 */
final readonly class DstChoices
{
    /** @var array<string, DstChoice> */
    private array $choices;

    /**
     * @param list<DstChoice> $choices
     */
    public function __construct(array $choices = [])
    {
        $indexed = [];
        foreach ($choices as $choice) {
            if (isset($indexed[$choice->key()])) {
                throw new DomainRuleViolation(sprintf('Choix en double pour le %s (%s).', $choice->date, $choice->field->value));
            }
            $indexed[$choice->key()] = $choice;
        }
        ksort($indexed);
        $this->choices = $indexed;
    }

    /**
     * @param list<array<string, mixed>> $data JSON décodé : [{"date","field","occurrence"}]
     */
    public static function fromArray(array $data): self
    {
        $choices = [];
        foreach ($data as $row) {
            $field = \is_string($row['field'] ?? null) ? ChoiceField::tryFrom($row['field']) : null;
            $occurrence = \is_string($row['occurrence'] ?? null) ? Occurrence::tryFrom($row['occurrence']) : null;
            if (null === $field || null === $occurrence || !\is_string($row['date'] ?? null)) {
                throw new DomainRuleViolation('Choix d\'occurrence horaire invalide.');
            }
            $choices[] = new DstChoice(LocalDate::fromString($row['date']), $field, $occurrence);
        }

        return new self($choices);
    }

    /**
     * @return list<array{date: string, field: string, occurrence: string}>
     */
    public function toArray(): array
    {
        return array_values(array_map(
            static fn (DstChoice $c): array => ['date' => $c->date->toString(), 'field' => $c->field->value, 'occurrence' => $c->occurrence->value],
            $this->choices,
        ));
    }

    public function find(LocalDate $date, ChoiceField $field): ?Occurrence
    {
        $key = $date->toString().'|'.$field->value;

        return isset($this->choices[$key]) ? $this->choices[$key]->occurrence : null;
    }

    /**
     * @return list<DstChoice>
     */
    public function all(): array
    {
        return array_values($this->choices);
    }

    public function isEmpty(): bool
    {
        return [] === $this->choices;
    }
}
