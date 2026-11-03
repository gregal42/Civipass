<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Domain\CwsPermission;
use App\Identity\Infrastructure\Security\DenyAllCwsPermissionChecker;
use App\Tests\Support\UserBuilder;
use PHPUnit\Framework\TestCase;

final class CwsPermissionTest extends TestCase
{
    public function testPermissionsAreTheTwoValidatedOnes(): void
    {
        self::assertSame(['CWS_IMPERSONATE', 'CWS_FORCE_CLOSE_EVENT'], array_map(static fn (CwsPermission $p): string => $p->value, CwsPermission::cases()));
    }

    public function testEverythingIsDeniedByDefault(): void
    {
        $checker = new DenyAllCwsPermissionChecker();

        foreach ([UserBuilder::aUser()->build(), UserBuilder::aUser()->superAdmin()->build()] as $user) {
            foreach (CwsPermission::cases() as $permission) {
                self::assertFalse($checker->isGranted($user, $permission), $permission->value);
            }
        }
    }

    public function testSuperAdminAloneDoesNotGrantTheCwsPermissions(): void
    {
        $superAdmin = UserBuilder::aUser()->superAdmin()->build();

        self::assertTrue($superAdmin->isSuperAdmin());
        self::assertFalse((new DenyAllCwsPermissionChecker())->isGranted($superAdmin, CwsPermission::ForceCloseEvent));
        self::assertFalse((new DenyAllCwsPermissionChecker())->isGranted($superAdmin, CwsPermission::Impersonate));
    }
}
