<?php

declare(strict_types=1);

namespace App\Retention\Domain;

/** Un constat du contrôle de restauration (LOT 05.6.7) : codes, identifiants et dates seulement, jamais une donnée personnelle. */
final readonly class RestoreItem
{
    public const SEVERITY_BLOCKING = 'BLOCKING';
    public const SEVERITY_WARNING = 'WARNING';
    public const SEVERITY_INFO = 'INFO';

    // Codes de constat.
    public const REINTRODUCED_DOSSIER = 'REINTRODUCED_DOSSIER';
    public const REINTRODUCED_SHARED = 'REINTRODUCED_SHARED';
    public const REINTRODUCED_EVENT = 'REINTRODUCED_EVENT';
    public const PURGED_BUT_PRESENT = 'PURGED_BUT_PRESENT';
    public const FILE_ONLY = 'FILE_ONLY';
    public const SQL_ONLY = 'SQL_ONLY';
    public const PROOF_PROBLEM = 'PROOF_PROBLEM';
    public const POSSIBLE_DATA_LOSS = 'POSSIBLE_DATA_LOSS';
    public const EVENT_MISSING = 'EVENT_MISSING';
    public const MUNICIPALITY_MISSING = 'MUNICIPALITY_MISSING';
    public const FILES_MISSING = 'FILES_MISSING';
    public const FILES_EXPECTED_MISSING = 'FILES_EXPECTED_MISSING';
    public const COUNT_MISMATCH = 'COUNT_MISMATCH';

    // Qualifications.
    public const REPLAYABLE = 'REPLAYABLE';
    public const BLOCKED_EVENT_NOT_CLOSED = 'BLOCKED_EVENT_NOT_CLOSED';
    public const BLOCKED_HOLD = 'BLOCKED_HOLD';
    public const BLOCKED_RIGHTS_OPEN = 'BLOCKED_RIGHTS_OPEN';
    public const BLOCKED_MISMATCH = 'BLOCKED_MISMATCH';
    public const BLOCKED_DOSSIERS_REMAIN = 'BLOCKED_DOSSIERS_REMAIN';
    public const MANUAL = 'MANUAL';
    public const ADOPTABLE = 'ADOPTABLE';
    public const REPAIRABLE = 'REPAIRABLE';
    public const INCIDENT = 'INCIDENT';
    public const ACKNOWLEDGE_REQUIRED = 'ACKNOWLEDGE_REQUIRED';
    public const EXPECTED = 'EXPECTED';

    public function __construct(
        public string $code,
        public string $severity,
        public string $qualification,
        public string $kind = '',
        public string $recordId = '',
        public string $eventReference = '',
        public string $municipalityId = '',
        public string $dossierId = '',
        public string $requestId = '',
        public string $detail = '',
    ) {
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public function blocking(): bool
    {
        return self::SEVERITY_BLOCKING === $this->severity;
    }
}
