<?php

declare(strict_types=1);

namespace App\Permit\Application\Read;

/** Période d'accès prévue d'une zone, en UTC (`until` nul : sans borne de fin). */
final readonly class PublicPeriod
{
    public function __construct(public \DateTimeImmutable $from, public ?\DateTimeImmutable $until)
    {
    }
}
