<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification;

use App\Notification\Domain\ManualRequestPolicy;
use App\Permit\Domain\ManualNotificationOutcome;
use PHPUnit\Framework\TestCase;

/** Limites des renvois manuels (LOT 05.3.5) : 5 minutes d'écart, 3 par 24 heures glissantes. */
final class ManualRequestPolicyTest extends TestCase
{
    private function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('UTC'));
    }

    public function testTheFirstRequestIsAccepted(): void
    {
        self::assertNull(ManualRequestPolicy::refusal([], $this->at('2026-06-12 09:00:00')));
    }

    public function testTheFiveMinuteGapIsExclusiveOfTheBoundary(): void
    {
        $accepted = [$this->at('2026-06-12 09:00:00')];

        self::assertSame(ManualNotificationOutcome::TooSoon, ManualRequestPolicy::refusal($accepted, $this->at('2026-06-12 09:04:59')));
        self::assertNull(ManualRequestPolicy::refusal($accepted, $this->at('2026-06-12 09:05:00')));
    }

    public function testTheThirdIsAcceptedAndTheFourthRefusedWithinTwentyFourHours(): void
    {
        $two = [$this->at('2026-06-12 09:00:00'), $this->at('2026-06-12 09:10:00')];
        $three = [...$two, $this->at('2026-06-12 09:20:00')];

        self::assertNull(ManualRequestPolicy::refusal($two, $this->at('2026-06-12 09:30:00')));
        self::assertSame(ManualNotificationOutcome::DailyLimitReached, ManualRequestPolicy::refusal($three, $this->at('2026-06-12 09:30:00')));
    }

    public function testTheWindowSlidesSoOldRequestsStopCounting(): void
    {
        $three = [$this->at('2026-06-12 09:00:00'), $this->at('2026-06-12 09:10:00'), $this->at('2026-06-12 09:20:00')];

        self::assertSame(ManualNotificationOutcome::DailyLimitReached, ManualRequestPolicy::refusal($three, $this->at('2026-06-13 08:59:59')), 'la première a 23 h 59 min 59 s : encore dans la fenêtre');
        self::assertNull(ManualRequestPolicy::refusal($three, $this->at('2026-06-13 09:00:00')), 'à 24 h pile elle sort de la fenêtre glissante');
    }

    public function testTheGapRuleIsCheckedBeforeTheDailyLimit(): void
    {
        $three = [$this->at('2026-06-12 09:00:00'), $this->at('2026-06-12 09:10:00'), $this->at('2026-06-12 09:20:00')];

        self::assertSame(ManualNotificationOutcome::TooSoon, ManualRequestPolicy::refusal($three, $this->at('2026-06-12 09:22:00')));
    }
}
