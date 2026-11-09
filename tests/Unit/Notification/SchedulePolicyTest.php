<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification;

use App\Notification\Domain\ChangeCategory;
use App\Notification\Domain\NotificationType;
use App\Notification\Domain\SchedulePolicy;
use PHPUnit\Framework\TestCase;

/** Regroupement (D5, LOT 05.3.4) : 10 minutes de silence, 60 minutes au plus, première approbation et révocation sans attente. */
final class SchedulePolicyTest extends TestCase
{
    private function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-06-12 '.$time, new \DateTimeZone('UTC'));
    }

    public function testTheFirstIssuanceAndARevocationNeverWait(): void
    {
        self::assertEquals($this->at('09:00:00'), SchedulePolicy::availableAt(NotificationType::PermitIssued, $this->at('09:00:00')));
        self::assertEquals($this->at('09:00:00'), SchedulePolicy::availableAt(NotificationType::AuthorizationRevoked, $this->at('09:00:00')));
    }

    public function testOrdinaryChangesWaitForTheSilence(): void
    {
        foreach ([NotificationType::AuthorizationAdded, NotificationType::PermitExtended, NotificationType::ZonesChanged, NotificationType::VehiclesChanged] as $type) {
            self::assertSame(ChangeCategory::Ordinary, $type->category());
            self::assertEquals($this->at('09:10:00'), SchedulePolicy::availableAt($type, $this->at('09:00:00')));
        }
    }

    public function testTheGroupIsDueTenMinutesAfterTheLastChange(): void
    {
        self::assertEquals($this->at('09:18:00'), SchedulePolicy::groupAvailableAt($this->at('09:00:00'), $this->at('09:08:00')));
    }

    public function testTheGroupNeverWaitsMoreThanSixtyMinutesAfterTheFirstChange(): void
    {
        self::assertEquals($this->at('10:00:00'), SchedulePolicy::groupAvailableAt($this->at('09:00:00'), $this->at('09:55:00')), '09:55 + 10 min dépasse le plafond');
        self::assertEquals($this->at('10:00:00'), SchedulePolicy::groupAvailableAt($this->at('09:00:00'), $this->at('11:00:00')));
    }

    public function testCategoriesCoverEveryTypeAndRevocationIsPrioritary(): void
    {
        foreach (NotificationType::cases() as $type) {
            self::assertInstanceOf(ChangeCategory::class, $type->category());
        }
        self::assertSame(ChangeCategory::Revocation, NotificationType::AuthorizationRevoked->category());
        self::assertSame(ChangeCategory::FirstIssuance, NotificationType::PermitIssued->category());
    }
}
