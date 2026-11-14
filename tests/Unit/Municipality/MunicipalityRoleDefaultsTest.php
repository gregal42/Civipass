<?php

declare(strict_types=1);

namespace App\Tests\Unit\Municipality;

use App\Municipality\Domain\MunicipalityRole;
use PHPUnit\Framework\TestCase;

/** Cumul par défaut des rôles d'un administrateur communal (DM-124). */
final class MunicipalityRoleDefaultsTest extends TestCase
{
    public function testAnAdministratorGetsTheThreeRolesByDefault(): void
    {
        self::assertSame(
            [MunicipalityRole::MunicipalityAdmin, MunicipalityRole::EventManager, MunicipalityRole::Instructor],
            MunicipalityRole::withAdministratorDefaults([MunicipalityRole::MunicipalityAdmin]),
        );
    }

    public function testASetWithoutAdministrationIsLeftUntouched(): void
    {
        self::assertSame([MunicipalityRole::Instructor], MunicipalityRole::withAdministratorDefaults([MunicipalityRole::Instructor]));
        self::assertSame(
            [MunicipalityRole::EventManager, MunicipalityRole::Instructor],
            MunicipalityRole::withAdministratorDefaults([MunicipalityRole::EventManager, MunicipalityRole::Instructor]),
        );
    }
}
