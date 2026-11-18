<?php

declare(strict_types=1);

namespace App\Request\Application\Read;

use App\Request\Domain\RefusalNoticeStatus;

/** Ligne de la liste « Refus à communiquer » d'UNE commune (LOT 08.5) : le demandeur n'a pas pu être informé par e-mail. */
final readonly class RefusalToCommunicate
{
    public function __construct(
        public string $noticeId,
        public string $requestId,
        public string $reference,
        public string $lastName,
        public string $firstName,
        public ?string $phone,
        public string $eventName,
        public \DateTimeImmutable $decidedAt,
        public RefusalNoticeStatus $status,
        public ?string $errorCode,
    ) {
    }
}
