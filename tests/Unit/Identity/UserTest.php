<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Tests\Support\UserBuilder;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    public function testRegularUserHasOnlyRoleUser(): void
    {
        self::assertSame(['ROLE_USER'], UserBuilder::aUser()->build()->getRoles());
    }

    public function testSuperAdminHasGlobalRoleOnly(): void
    {
        $roles = UserBuilder::aUser()->superAdmin()->build()->getRoles();
        self::assertSame(['ROLE_USER', 'ROLE_SUPER_ADMIN'], $roles);
        foreach ($roles as $role) {
            self::assertStringNotContainsString('MUNICIPALITY', $role);
            self::assertStringNotContainsString('INSTRUCTOR', $role);
        }
    }

    public function testIdentifierIsNormalizedEmail(): void
    {
        self::assertSame('a@example.fr', UserBuilder::aUser()->withEmail(' A@Example.FR ')->build()->getUserIdentifier());
    }

    public function testIdIsUuidV7(): void
    {
        self::assertSame('7', UserBuilder::aUser()->build()->id()->toRfc4122()[14]);
    }

    public function testIsEqualToDetectsSecuritySensitiveChanges(): void
    {
        $user = UserBuilder::aUser()->withEmail('x@example.fr')->build();
        $same = clone $user;
        self::assertTrue($user->isEqualTo($same));

        $deactivated = clone $user;
        $deactivated->deactivate(new \DateTimeImmutable());
        self::assertFalse($user->isEqualTo($deactivated));

        $promoted = clone $user;
        $promoted->changeSuperAdmin(true, new \DateTimeImmutable());
        self::assertFalse($user->isEqualTo($promoted));

        $loggedIn = clone $user;
        $loggedIn->recordLogin(new \DateTimeImmutable());
        self::assertTrue($user->isEqualTo($loggedIn), 'lastLoginAt ne doit pas invalider la session.');
    }

    public function testRecordLoginDoesNotTouchUpdatedAt(): void
    {
        $user = UserBuilder::aUser()->build();
        $updatedAt = $user->updatedAt();
        $user->recordLogin(new \DateTimeImmutable('2030-01-01'));
        self::assertEquals($updatedAt, $user->updatedAt());
        self::assertNotNull($user->lastLoginAt());
    }
}
