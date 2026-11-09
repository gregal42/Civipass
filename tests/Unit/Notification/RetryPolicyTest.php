<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification;

use App\Notification\Domain\OutboxErrorCode;
use App\Notification\Domain\RetryPolicy;
use PHPUnit\Framework\TestCase;

/** Réessais SMTP (D6) : 1, 5, 15, 60, 240 minutes ; six tentatives ; 24 heures depuis la première tentative. */
final class RetryPolicyTest extends TestCase
{
    private function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('UTC'));
    }

    public function testTheDelaysFollowTheValidatedSchedule(): void
    {
        $first = $this->at('2026-06-12 09:00:00');
        $now = $first;
        $delays = [];
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $next = RetryPolicy::decide($attempt, $first, $now);
            self::assertInstanceOf(\DateTimeImmutable::class, $next);
            $delays[] = (int) (($next->getTimestamp() - $now->getTimestamp()) / 60);
            $now = $next;
        }

        self::assertSame([1, 5, 15, 60, 240], $delays);
    }

    public function testTheSixthFailedAttemptAbandonsTheMessage(): void
    {
        $first = $this->at('2026-06-12 09:00:00');

        self::assertSame(OutboxErrorCode::SmtpExhausted, RetryPolicy::decide(6, $first, $first->modify('+7 hours')));
        self::assertSame(OutboxErrorCode::SmtpExhausted, RetryPolicy::exhausted(6, $first, $first));
        self::assertNull(RetryPolicy::exhausted(5, $first, $first));
    }

    public function testTheTwentyFourHourWindowStartsAtTheFirstAttemptAndAbandonsEvenWithAttemptsLeft(): void
    {
        $first = $this->at('2026-06-12 09:00:00');

        self::assertSame(OutboxErrorCode::SmtpWindowExpired, RetryPolicy::decide(2, $first, $first->modify('+24 hours')));
        self::assertSame(OutboxErrorCode::SmtpWindowExpired, RetryPolicy::exhausted(2, $first, $first->modify('+24 hours')));
        self::assertNull(RetryPolicy::exhausted(2, $first, $first->modify('+23 hours 59 minutes 59 seconds')));
        self::assertNull(RetryPolicy::exhausted(0, null, $first->modify('+400 days')), 'sans première tentative, aucun plafond de fenêtre');
    }

    public function testAnAttemptThatWouldFallAfterTheWindowIsAbandonedNow(): void
    {
        $first = $this->at('2026-06-12 09:00:00');

        self::assertSame(OutboxErrorCode::SmtpWindowExpired, RetryPolicy::decide(5, $first, $first->modify('+23 hours 30 minutes')), '240 minutes dépasseraient les 24 heures');
        self::assertInstanceOf(\DateTimeImmutable::class, RetryPolicy::decide(1, $first, $first->modify('+23 hours 30 minutes')), '1 minute tient encore');
    }
}
