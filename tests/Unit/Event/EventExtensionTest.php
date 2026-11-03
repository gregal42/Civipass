<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event;

use App\Event\Application\AccessDenialReason;
use App\Event\UI\Twig\EventExtension;
use PHPUnit\Framework\TestCase;

final class EventExtensionTest extends TestCase
{
    public function testDatesAreShownInTheTimezoneOfTheEvent(): void
    {
        $extension = new EventExtension();
        $winter = new \DateTimeImmutable('2026-12-01 08:00:00', new \DateTimeZone('UTC'));
        $summer = new \DateTimeImmutable('2026-07-01 08:00:00', new \DateTimeZone('UTC'));

        self::assertSame('01/12/2026 à 09:00', $extension->datetime($winter, 'Europe/Paris'));
        self::assertSame('01/07/2026 à 10:00', $extension->datetime($summer, 'Europe/Paris'), 'heure d\'été');
        self::assertSame('01/12/2026 à 04:00', $extension->datetime($winter, 'America/Martinique'));
        self::assertSame('', $extension->datetime(null));
    }

    public function testTypeIcons(): void
    {
        $extension = new EventExtension();

        self::assertSame('fire', $extension->icon('fire'));
        self::assertSame('wave', $extension->icon('flood'));
        self::assertSame('car', $extension->icon('accident'));
        self::assertSame('flag', $extension->icon('anything-else'));
    }

    public function testEveryDenialReasonHasASafeSentence(): void
    {
        $extension = new EventExtension();
        $messages = [];
        foreach ([...AccessDenialReason::cases(), null] as $reason) {
            $message = $extension->denialMessage($reason);
            self::assertNotSame('', $message);
            self::assertDoesNotMatchRegularExpression('/[A-Z]{3,}_[A-Z_]+/', $message, 'jamais un code interne');
            self::assertStringNotContainsString('membership', strtolower($message));
            $messages[] = $message;
        }
        self::assertSame($extension->denialMessage(AccessDenialReason::NoAccess), $extension->denialMessage(null), 'aucun accès : phrase générique, sans motif');
        self::assertSame($extension->denialMessage(AccessDenialReason::ZoneDeleted), $extension->denialMessage(AccessDenialReason::SlotDeleted));
    }
}
