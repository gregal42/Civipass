<?php

declare(strict_types=1);

namespace App\Request\Application\Read;

use App\Request\Domain\ManualChannel;
use App\Request\Domain\RefusalNoticeStatus;

/** État de l'information du demandeur refusé, tel que le voit l'agent sur la fiche (LOT 08.5) : statut, canal manuel, date. Jamais l'adresse. */
final readonly class RefusalNoticeView
{
    public function __construct(
        public RefusalNoticeStatus $status,
        public ?\DateTimeImmutable $processedAt,
        public int $attempts,
        public ?ManualChannel $manualChannel,
        public ?string $errorCode,
    ) {
    }
}
