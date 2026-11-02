<?php

declare(strict_types=1);

namespace App\Tests\Unit\Municipality;

use App\Municipality\Application\EventCreationPolicy;
use App\Municipality\Domain\MunicipalityStatus;
use App\Municipality\Domain\SubscriptionStatus;
use App\Tests\Support\MunicipalityBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EventCreationPolicyTest extends TestCase
{
    #[DataProvider('matrix')]
    public function testCreateEventRequiresActiveStatusAndActiveSubscription(MunicipalityStatus $status, SubscriptionStatus $subscription, bool $expected): void
    {
        $municipality = MunicipalityBuilder::aMunicipality()->withStatus($status)->build();
        self::assertSame($expected, (new EventCreationPolicy())->canCreateEvent($municipality, $subscription));
    }

    /**
     * @return iterable<string, array{MunicipalityStatus, SubscriptionStatus, bool}>
     */
    public static function matrix(): iterable
    {
        foreach (MunicipalityStatus::cases() as $status) {
            foreach (SubscriptionStatus::cases() as $subscription) {
                yield $status->value.' × '.$subscription->value => [
                    $status,
                    $subscription,
                    MunicipalityStatus::Active === $status && SubscriptionStatus::Active === $subscription,
                ];
            }
        }
    }

    public function testNotSubscribedMunicipalityCanStillJoinAnEvent(): void
    {
        $policy = new EventCreationPolicy();
        self::assertTrue($policy->canJoinNewEvent(MunicipalityBuilder::aMunicipality()->build()));
    }

    #[DataProvider('nonActiveStatuses')]
    public function testSuspendedOrInactiveMunicipalityCannotJoinANewEvent(MunicipalityStatus $status): void
    {
        $municipality = MunicipalityBuilder::aMunicipality()->withStatus($status)->build();
        self::assertFalse((new EventCreationPolicy())->canJoinNewEvent($municipality));
    }

    /**
     * @return iterable<string, array{MunicipalityStatus}>
     */
    public static function nonActiveStatuses(): iterable
    {
        yield 'SUSPENDED' => [MunicipalityStatus::Suspended];
        yield 'INACTIVE' => [MunicipalityStatus::Inactive];
    }
}
