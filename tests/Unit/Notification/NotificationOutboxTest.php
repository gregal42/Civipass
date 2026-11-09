<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification;

use App\Notification\Domain\NotificationOutbox;
use App\Notification\Domain\NotificationType;
use App\Notification\Domain\OutboxStatus;
use App\Notification\Domain\SchedulePolicy;
use App\Permit\Domain\NotificationIntent;
use App\Permit\Domain\NotificationKind;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class NotificationOutboxTest extends TestCase
{
    private function at(string $time = '2026-06-12 09:00:00'): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('UTC'));
    }

    public function testTheFirstApprovalIsAvailableImmediatelyAndAnAddedCommuneAfterTheSilence(): void
    {
        $permit = Uuid::v7();
        $authorization = Uuid::v7();

        $first = NotificationOutbox::record('k1', NotificationType::PermitIssued, $permit, $authorization, $this->at(), true);
        $added = NotificationOutbox::record('k2', NotificationType::AuthorizationAdded, $permit, $authorization, $this->at(), true);

        self::assertSame(OutboxStatus::Pending, $first->status());
        self::assertEquals($this->at(), $first->availableAt());
        self::assertEquals($this->at('2026-06-12 09:10:00'), $added->availableAt());
        self::assertNull($first->processedAt());
        self::assertSame(0, $first->attempts());
    }

    public function testWithoutRecipientTheRowIsFinalFromTheStartAndNeverPending(): void
    {
        $row = NotificationOutbox::record('k', NotificationType::PermitIssued, Uuid::v7(), Uuid::v7(), $this->at(), false);

        self::assertSame(OutboxStatus::NoRecipient, $row->status());
        self::assertTrue($row->status()->isFinal());
        self::assertEquals($this->at(), $row->processedAt());
    }

    public function testOnlySentAbandonedNoRecipientAndSuppressedAreFinal(): void
    {
        $final = array_filter(OutboxStatus::cases(), static fn (OutboxStatus $s): bool => $s->isFinal());

        self::assertEqualsCanonicalizing([OutboxStatus::Sent, OutboxStatus::Abandoned, OutboxStatus::NoRecipient, OutboxStatus::Suppressed], array_values($final));
    }

    public function testDeduplicationKeysAreStablePerBusinessEvent(): void
    {
        $permit = Uuid::v7();
        $authorization = Uuid::v7();
        $issued = new NotificationIntent(NotificationKind::PermitIssued, $permit, $authorization, $this->at(), true);
        $added = new NotificationIntent(NotificationKind::AuthorizationAdded, $permit, $authorization, $this->at(), true);

        self::assertSame('PERMIT_ISSUED:'.$permit->toRfc4122(), $issued->deduplicationKey());
        self::assertSame('AUTHORIZATION_ADDED:'.$authorization->toRfc4122(), $added->deduplicationKey());
        self::assertSame($added->deduplicationKey(), (new NotificationIntent(NotificationKind::AuthorizationAdded, $permit, $authorization, $this->at('2026-06-13 00:00:00'), false))->deduplicationKey());
    }

    public function testTheMaximumWaitIsSixtyMinutes(): void
    {
        self::assertEquals($this->at('2026-06-12 10:00:00'), SchedulePolicy::deadline($this->at()));
    }
}
