<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Municipality\Application\MunicipalityVoter;
use App\Municipality\Domain\MunicipalityRole;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\MunicipalityBuilder;
use App\Tests\Support\UserBuilder;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class VoterTest extends DatabaseTestCase
{
    public function testVoterGrantsOnlyInsideTheUsersOwnMunicipality(): void
    {
        $a = MunicipalityBuilder::aMunicipality()->build();
        $b = MunicipalityBuilder::aMunicipality()->build();
        $admin = UserBuilder::aUser()->build();
        $this->save($a, $b, $admin, MembershipBuilder::aMembership($admin, $a)->withRoles(MunicipalityRole::MunicipalityAdmin)->build());
        $voter = new MunicipalityVoter($this->service(\App\Municipality\Application\MembershipAccessPolicy::class));
        $token = new UsernamePasswordToken($admin, 'main', $admin->getRoles());

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $a, [MunicipalityVoter::MANAGE_MEMBERS]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $b, [MunicipalityVoter::MANAGE_MEMBERS]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, new \stdClass(), [MunicipalityVoter::MANAGE_MEMBERS]));
    }
}
