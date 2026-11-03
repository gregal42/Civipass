<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event\Application;

use App\Event\Application\EventPermission;
use App\Event\Application\PermissionSubject;
use App\Municipality\Domain\MunicipalityRole as Role;
use PHPUnit\Framework\TestCase;

/**
 * La matrice de DM-057, écrite ici indépendamment de son implémentation (source unique : EventPermission).
 */
final class EventPermissionMatrixTest extends TestCase
{
    /** @return array<string, array{admin: bool, manager: bool, instructor: bool}> */
    private static function expected(): array
    {
        $admin = ['admin' => true, 'manager' => false, 'instructor' => false];
        $both = ['admin' => true, 'manager' => true, 'instructor' => false];
        $anyMember = ['admin' => true, 'manager' => true, 'instructor' => true];

        return [
            'EVENT_CREATE' => $both,
            'EVENT_VIEW' => $anyMember,
            'EVENT_UPDATE' => $both,
            'EVENT_ACTIVATE' => $both,
            'EVENT_SUSPEND' => $both,
            'EVENT_REACTIVATE' => $both,
            'EVENT_CLOSE' => $both,
            'EVENT_INVITE' => $both,
            'PARTICIPATION_VIEW' => $anyMember,
            'PARTICIPATION_VIEW_DETAILS' => $both,
            'PARTICIPATION_ACCEPT' => $admin,
            'PARTICIPATION_SUSPEND' => $both,
            'PARTICIPATION_REACTIVATE' => $both,
            'PARTICIPATION_CLOSE' => $both,
            'ZONE_CREATE' => $admin,
            'ZONE_VIEW' => $anyMember,
            'ZONE_MODIFY' => $admin,
            'ZONE_DELETE' => $admin,
            'ZONE_VALIDITY_CONFIGURE' => $admin,
            'ZONE_VALIDITY_SLOT_DELETE' => $admin,
        ];
    }

    public function testMatrixIsExactlyDm057(): void
    {
        self::assertCount(\count(self::expected()), EventPermission::cases(), 'toute permission est couverte par la table attendue');
        foreach (EventPermission::cases() as $permission) {
            $roles = $permission->roles();
            $granted = [
                'admin' => null === $roles || \in_array(Role::MunicipalityAdmin, $roles, true),
                'manager' => null === $roles || \in_array(Role::EventManager, $roles, true),
                'instructor' => null === $roles || \in_array(Role::Instructor, $roles, true),
            ];
            self::assertSame(self::expected()[$permission->value], $granted, $permission->value);
        }
    }

    public function testOnlyReadsAreOpenToEveryMember(): void
    {
        foreach (EventPermission::cases() as $permission) {
            if (EventPermission::ViewParticipationDetails === $permission) {
                continue; // U5 : lecture réservée aux rôles de gestion de l'organisatrice
            }
            self::assertSame($permission->isRead(), null === $permission->roles(), $permission->value);
        }
        self::assertTrue(EventPermission::ViewParticipationDetails->isRead());
        self::assertNotContains(Role::Instructor, EventPermission::ViewParticipationDetails->roles() ?? [], 'U5 : l\'instructeur ne voit pas les informations internes');
    }

    public function testInstructorHasNoManagementPermission(): void
    {
        foreach (EventPermission::cases() as $permission) {
            if ($permission->isRead()) {
                continue;
            }
            self::assertNotContains(Role::Instructor, $permission->roles() ?? [], $permission->value);
        }
    }

    public function testZonesAndSlotsAreForMunicipalityAdministratorsOnly(): void
    {
        foreach (EventPermission::cases() as $permission) {
            if (\in_array($permission->subject(), [PermissionSubject::Zone, PermissionSubject::ZoneValiditySlot], true) && !$permission->isRead()
                || EventPermission::CreateZone === $permission) {
                self::assertSame([Role::MunicipalityAdmin], $permission->roles(), $permission->value);
            }
        }
    }

    public function testInvitationsCanOnlyBeAcceptedByMunicipalityAdministrators(): void
    {
        self::assertSame([Role::MunicipalityAdmin], EventPermission::AcceptInvitation->roles());
    }

    public function testNoPermissionMentionsAGlobalRole(): void
    {
        foreach (EventPermission::cases() as $permission) {
            foreach ($permission->roles() ?? [] as $role) {
                self::assertContains($role, Role::cases(), 'rôles communaux uniquement : superAdmin ne donne aucun droit communal');
            }
        }
    }
}
