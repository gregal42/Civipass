<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidFormat;
use Doctrine\DBAL\Types\DateTimeImmutableType;

/**
 * Remplace datetime_immutable : toute date est convertie en UTC à l'écriture et relue en UTC.
 */
final class UtcDateTimeImmutableType extends DateTimeImmutableType
{
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value instanceof \DateTimeImmutable) {
            $value = $value->setTimezone(new \DateTimeZone('UTC'));
        }

        return parent::convertToDatabaseValue($value, $platform);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?\DateTimeImmutable
    {
        if (null === $value || $value instanceof \DateTimeImmutable) {
            return $value;
        }

        $date = \DateTimeImmutable::createFromFormat('!'.$platform->getDateTimeFormatString(), (string) $value, new \DateTimeZone('UTC'));
        if (false === $date) {
            throw InvalidFormat::new((string) $value, self::class, $platform->getDateTimeFormatString());
        }

        return $date;
    }
}
