<?php

declare(strict_types=1);

namespace App\Tests\Unit\Onboarding;

use App\Municipality\Domain\MunicipalityRole;
use App\Onboarding\Domain\InvitationDeliveryStatus;
use App\Onboarding\Domain\InvitationToken;
use App\Onboarding\Domain\MunicipalityAdminInvitation;
use App\Tests\Support\MunicipalityBuilder;
use App\Tests\Support\UserBuilder;
use App\Shared\Domain\DomainRuleViolation;
use PHPUnit\Framework\TestCase;

final class MunicipalityAdminInvitationTest extends TestCase
{
    private static function at(string $v): \DateTimeImmutable
    {
        return new \DateTimeImmutable($v, new \DateTimeZone('UTC'));
    }

    private function invitation(string $lifetime = 'P7D'): MunicipalityAdminInvitation
    {
        $cws = UserBuilder::aUser()->superAdmin()->build();

        return new MunicipalityAdminInvitation(MunicipalityBuilder::aMunicipality()->build(), 'a@mairie.example', InvitationToken::generate()->hash(), $cws, self::at('2026-06-01 10:00'), $lifetime);
    }

    public function testItIsValidSevenDaysByDefaultThenExpires(): void
    {
        $i = $this->invitation();
        self::assertSame('PENDING', $i->stateAt(self::at('2026-06-08 09:59')));
        self::assertSame('EXPIRED', $i->stateAt(self::at('2026-06-08 10:00')));
        self::assertFalse($i->isUsableAt(self::at('2026-06-09 00:00')));
    }

    public function testTheLifetimeIsConfigurable(): void
    {
        self::assertSame('EXPIRED', $this->invitation('P1D')->stateAt(self::at('2026-06-02 10:00')));
    }

    public function testRevocationAndConsumptionAreFinalAndExclusive(): void
    {
        $cws = UserBuilder::aUser()->superAdmin()->build();
        $revoked = $this->invitation();
        $revoked->markRevoked($cws, self::at('2026-06-02 00:00'));
        self::assertSame('REVOKED', $revoked->stateAt(self::at('2026-06-03 00:00')));
        $revoked->markConsumed($cws, self::at('2026-06-03 00:00'));
        self::assertNotNull($revoked->consumedAt(), 'la consommation reste un fait distinct');

        $used = $this->invitation();
        $used->markConsumed($cws, self::at('2026-06-02 00:00'));
        $used->markRevoked($cws, self::at('2026-06-03 00:00'));
        self::assertSame('USED', $used->stateAt(self::at('2026-06-04 00:00')));
        self::assertNull($used->revokedAt(), 'une invitation acceptée ne se révoque pas');
    }

    public function testDeliveryStartsPendingAndNeverExposesTheToken(): void
    {
        $i = $this->invitation();
        self::assertSame(InvitationDeliveryStatus::Pending, $i->deliveryStatus());
        $i->markDelivery(InvitationDeliveryStatus::Failed, self::at('2026-06-01 10:01'));
        self::assertSame(InvitationDeliveryStatus::Failed, $i->deliveryStatus());
        self::assertSame(64, \strlen($i->tokenHash()), 'seule l’empreinte SHA-256 est conservée');
    }

    public function testRolesDefaultToAdministratorAreOrderedAndDeduplicated(): void
    {
        $cws = UserBuilder::aUser()->superAdmin()->build();
        self::assertSame([MunicipalityRole::MunicipalityAdmin], $this->invitation()->roles());
        $team = new MunicipalityAdminInvitation(MunicipalityBuilder::aMunicipality()->build(), 'a@mairie.example', InvitationToken::generate()->hash(), $cws, self::at('2026-06-01 10:00'), 'P7D', null, [MunicipalityRole::Instructor, MunicipalityRole::EventManager, MunicipalityRole::Instructor]);
        self::assertSame([MunicipalityRole::EventManager, MunicipalityRole::Instructor], $team->roles());
        self::assertFalse($team->grantsAdministrator());
        self::assertTrue($this->invitation()->grantsAdministrator());
    }

    public function testAnInvitationWithoutRoleIsRefused(): void
    {
        $this->expectException(DomainRuleViolation::class);
        new MunicipalityAdminInvitation(MunicipalityBuilder::aMunicipality()->build(), 'a@mairie.example', InvitationToken::generate()->hash(), UserBuilder::aUser()->build(), self::at('2026-06-01 10:00'), 'P7D', null, []);
    }
}
