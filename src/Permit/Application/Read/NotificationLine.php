<?php

declare(strict_types=1);

namespace App\Permit\Application\Read;

/** Une notification vue par l'agent : libellés en français, aucun code interne, aucun secret, aucune empreinte, aucun identifiant technique. */
final readonly class NotificationLine
{
    public const PENDING = 'pending';
    public const SENT = 'sent';
    public const NONE = 'none';
    public const FAILED = 'failed';
    public const SKIPPED = 'skipped';

    public function __construct(
        public string $title,
        public string $state,
        public string $stateLabel,
        public string $detail,
        public ?\DateTimeImmutable $at,
    ) {
    }
}
