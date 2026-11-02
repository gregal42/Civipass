<?php

declare(strict_types=1);

namespace App\Tests\Unit\Municipality;

use App\Identity\Domain\EmailAddress;
use App\Municipality\Domain\MunicipalityStatus;
use App\Municipality\Domain\PhoneNumber;
use App\Shared\Domain\DomainRuleViolation;
use App\Tests\Support\MunicipalityBuilder;
use PHPUnit\Framework\TestCase;

final class MunicipalityTest extends TestCase
{
    private const NOW = '2026-02-01 12:00:00';

    public function testNewMunicipalityIsActiveWithUuidV7(): void
    {
        $municipality = MunicipalityBuilder::aMunicipality()->withInseeCode('2A004')->build();
        self::assertSame(MunicipalityStatus::Active, $municipality->status());
        self::assertSame('2A004', $municipality->inseeCode()->value);
        self::assertSame('7', $municipality->id()->toRfc4122()[14]);
    }

    public function testContactIsStoredNormalized(): void
    {
        $municipality = MunicipalityBuilder::aMunicipality()->build();
        $municipality->updateContact(
            ' Marie Martin ',
            EmailAddress::fromString('Mairie@Example.FR'),
            PhoneNumber::fromE164('+33123456789'),
            '1 place de la Mairie',
            '75001',
            'Paris',
            new \DateTimeImmutable(self::NOW),
        );
        self::assertSame('Marie Martin', $municipality->contactName());
        self::assertSame('mairie@example.fr', $municipality->contactEmail());
        self::assertSame('+33123456789', $municipality->contactPhone());
    }

    public function testPostalCodeAndCityMustBeProvidedTogether(): void
    {
        $this->expectException(DomainRuleViolation::class);
        MunicipalityBuilder::aMunicipality()->build()->updateContact(null, null, null, null, '75001', null, new \DateTimeImmutable(self::NOW));
    }

    public function testInvalidPostalCodeIsRejected(): void
    {
        $this->expectException(DomainRuleViolation::class);
        MunicipalityBuilder::aMunicipality()->build()->updateContact(null, null, null, null, '7500', 'Paris', new \DateTimeImmutable(self::NOW));
    }

    public function testPhoneNumberValueObjectRejectsNonE164(): void
    {
        $this->expectException(DomainRuleViolation::class);
        PhoneNumber::fromE164('0123456789');
    }
}
