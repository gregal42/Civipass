<?php

declare(strict_types=1);

namespace App\Permit\Domain;

use App\Request\Domain\DurationMode;
use App\Request\Domain\GrantWindow;

/**
 * Résultat d'une règle de période (LOT 05.5.2) : ce qui sera écrit, calculé sans écrire (aperçu de l'écran agent) puis appliqué à l'identique.
 * `from`/`until` : la période COURANTE après l'opération ; `archivesCurrent` : la période courante actuelle devient une période close (reprise).
 * `requestedUntil` / `immediate` : raccourcissement vers une date passée (fin immédiate, jamais antidatée) — la date demandée est conservée.
 */
final readonly class PeriodPlan
{
    public function __construct(
        public PeriodKind $kind,
        public GrantWindow $before,
        public DurationMode $mode,
        public \DateTimeImmutable $from,
        public ?\DateTimeImmutable $until,
        public bool $archivesCurrent,
        public ?\DateTimeImmutable $requestedUntil = null,
        public bool $immediate = false,
    ) {
    }

    /** @return array<string, scalar|null> */
    public function beforeState(): array
    {
        return self::state($this->before->mode, $this->before->from, $this->before->until);
    }

    /** @return array<string, scalar|null> */
    public function afterState(): array
    {
        return self::state($this->mode, $this->from, $this->until) + [
            'archivedPrevious' => $this->archivesCurrent,
            'requestedUntil' => $this->requestedUntil?->format(\DATE_ATOM),
            'immediate' => $this->immediate,
        ];
    }

    /** @return array<string, scalar|null> */
    private static function state(DurationMode $mode, \DateTimeImmutable $from, ?\DateTimeImmutable $until): array
    {
        return ['durationMode' => $mode->value, 'validFrom' => $from->format(\DATE_ATOM), 'validUntil' => $until?->format(\DATE_ATOM)];
    }
}
