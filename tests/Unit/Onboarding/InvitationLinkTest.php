<?php

declare(strict_types=1);

namespace App\Tests\Unit\Onboarding;

use App\Event\Domain\EventParticipation;
use App\Onboarding\Domain\InvitationDeliveryStatus;
use App\Onboarding\Domain\InvitationLink;
use App\Onboarding\Domain\InvitationLinkKind;
use PHPUnit\Framework\TestCase;

final class InvitationLinkTest extends TestCase
{
    private function now(string $date = '2026-11-04 10:00:00'): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date, new \DateTimeZone('UTC'));
    }

    private function link(InvitationLinkKind $kind): InvitationLink
    {
        return new InvitationLink((new \ReflectionClass(EventParticipation::class))->newInstanceWithoutConstructor(), $kind, 'contact@mairie.example', str_repeat('a', 64), $this->now());
    }

    public function testAContactLinkLivesFourteenDaysAndAnAdministratorLinkThree(): void
    {
        self::assertEquals($this->now('2026-11-18 10:00:00'), $this->link(InvitationLinkKind::Contact)->expiresAt());
        self::assertEquals($this->now('2026-11-07 10:00:00'), $this->link(InvitationLinkKind::Administrator)->expiresAt());
    }

    public function testAFreshLinkIsUsableUntilItsExpiryInstantExcluded(): void
    {
        $link = $this->link(InvitationLinkKind::Administrator);

        self::assertTrue($link->isUsableAt($this->now('2026-11-04 10:00:01')));
        self::assertTrue($link->isUsableAt($this->now('2026-11-07 09:59:59')));
        self::assertFalse($link->isUsableAt($this->now('2026-11-07 10:00:00')), 'expiré à l’instant d’expiration');
        self::assertTrue($link->isExpiredAt($this->now('2026-11-08 00:00:00')));
    }

    public function testAConsumedOrRevokedLinkIsNeverUsableAgain(): void
    {
        $consumed = $this->link(InvitationLinkKind::Contact);
        $consumed->markConsumed($this->now('2026-11-04 11:00:00'));
        $revoked = $this->link(InvitationLinkKind::Contact);
        $revoked->markRevoked($this->now('2026-11-04 11:00:00'));

        self::assertFalse($consumed->isUsableAt($this->now('2026-11-04 12:00:00')));
        self::assertFalse($revoked->isUsableAt($this->now('2026-11-04 12:00:00')));
        $consumed->markConsumed($this->now('2026-11-05 00:00:00'));
        self::assertEquals($this->now('2026-11-04 11:00:00'), $consumed->consumedAt(), 'la première consommation est conservée');
    }

    public function testDeliveryStartsPendingAndRecordsTheAttempt(): void
    {
        $link = $this->link(InvitationLinkKind::Contact);
        self::assertSame(InvitationDeliveryStatus::Pending, $link->deliveryStatus());
        self::assertNull($link->deliveryAttemptedAt());

        $link->markDelivery(InvitationDeliveryStatus::Failed, $this->now('2026-11-04 10:00:05'));

        self::assertSame(InvitationDeliveryStatus::Failed, $link->deliveryStatus());
        self::assertEquals($this->now('2026-11-04 10:00:05'), $link->deliveryAttemptedAt());
    }
}
