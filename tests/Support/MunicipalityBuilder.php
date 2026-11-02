<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Municipality\Domain\InseeCode;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityStatus;

final class MunicipalityBuilder
{
    private static int $sequence = 0;

    private ?string $inseeCode = null;
    private MunicipalityStatus $status = MunicipalityStatus::Active;

    public static function aMunicipality(): self
    {
        return new self();
    }

    public function withInseeCode(string $code): self
    {
        $this->inseeCode = $code;

        return $this;
    }

    public function withStatus(MunicipalityStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function build(): Municipality
    {
        $now = new \DateTimeImmutable('2026-01-01 10:00:00', new \DateTimeZone('UTC'));
        $code = $this->inseeCode ?? sprintf('%05d', 10000 + ++self::$sequence);
        $municipality = new Municipality('Commune '.$code, InseeCode::fromString($code), $now);
        if (MunicipalityStatus::Active !== $this->status) {
            $municipality->changeStatus($this->status, $now);
        }

        return $municipality;
    }
}
