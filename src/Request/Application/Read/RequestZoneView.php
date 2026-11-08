<?php

declare(strict_types=1);

namespace App\Request\Application\Read;

use App\Event\Domain\Validity\ValidityMode;

/** Zone demandée à la commune consultante, avec ce qui éclaire la décision : mode de validité et suspension éventuelle. */
final readonly class RequestZoneView
{
    public function __construct(
        public string $label,
        public ValidityMode $validityMode,
        public bool $suspended,
    ) {
    }
}
