<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Identity\Domain\EmailAddress;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use App\Municipality\Domain\MunicipalityMembership;
use App\Municipality\Domain\MunicipalityRole;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\MunicipalityBuilder;
use App\Tests\Support\UserBuilder;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class PersistenceTest extends DatabaseTestCase
{
    public function testIdentifiersAreBinary16UuidV7(): void
    {
        $user = UserBuilder::aUser()->build();
        $this->save($user);

        $raw = (string) $this->em->getConnection()->fetchOne('SELECT id FROM identity_user');
        self::assertSame(16, \strlen($raw));
        self::assertSame('7', bin2hex($raw)[12], 'La version UUID stockée doit être 7.');

        $this->em->clear();
        $reloaded = $this->em->find(User::class, $user->id());
        self::assertNotNull($reloaded);
        self::assertTrue($user->id()->equals($reloaded->id()));
    }

    public function testDatesAreStoredAndReadInUtc(): void
    {
        $parisNoon = new \DateTimeImmutable('2026-06-01 12:00:00', new \DateTimeZone('Europe/Paris'));
        $user = new User(EmailAddress::fromString('utc@example.test'), 'hash', 'A', 'B', $parisNoon);
        $this->save($user);

        self::assertSame('2026-06-01 10:00:00', $this->em->getConnection()->fetchOne('SELECT created_at FROM identity_user'));

        $this->em->clear();
        $reloaded = $this->em->find(User::class, $user->id());
        self::assertNotNull($reloaded);
        self::assertSame('UTC', $reloaded->createdAt()->getTimezone()->getName());
        self::assertSame('2026-06-01 10:00:00', $reloaded->createdAt()->format('Y-m-d H:i:s'));
    }

    public function testDuplicateEmailIsRejectedByTheDatabase(): void
    {
        $this->save(UserBuilder::aUser()->withEmail('dup@example.test')->build());
        $this->expectException(UniqueConstraintViolationException::class);
        $this->save(UserBuilder::aUser()->withEmail('DUP@example.test')->build());
    }

    public function testAccentedEmailsAreDistinctUnderBinaryCollation(): void
    {
        $this->save(UserBuilder::aUser()->withEmail('jose@example.test')->build());
        $this->save(UserBuilder::aUser()->withEmail("jos\u{00e9}@example.test")->build());

        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM identity_user'));
    }

    public function testLookupRequiresNormalizedEmail(): void
    {
        $this->save(UserBuilder::aUser()->withEmail('Lookup@Example.test')->build());
        $users = $this->service(UserRepository::class);

        self::assertNotNull($users->findByEmail(EmailAddress::fromString('LOOKUP@example.TEST')));
        // Comparaison binaire : une valeur non normalisée injectée directement ne trouve rien.
        self::assertNull($this->em->getRepository(User::class)->findOneBy(['email' => 'Lookup@Example.test']));
    }

    public function testInseeCodeIsUnique(): void
    {
        $this->save(MunicipalityBuilder::aMunicipality()->withInseeCode('75056')->build());
        $this->expectException(UniqueConstraintViolationException::class);
        $this->save(MunicipalityBuilder::aMunicipality()->withInseeCode('75056')->build());
    }

    public function testMembershipIsUniquePerUserAndMunicipality(): void
    {
        $user = UserBuilder::aUser()->build();
        $municipality = MunicipalityBuilder::aMunicipality()->build();
        $this->save($user, $municipality, MembershipBuilder::aMembership($user, $municipality)->build());

        $this->expectException(UniqueConstraintViolationException::class);
        $this->save(MembershipBuilder::aMembership($user, $municipality)->withRoles(MunicipalityRole::EventManager)->build());
    }

    public function testRolesRoundTripThroughJson(): void
    {
        $user = UserBuilder::aUser()->build();
        $municipality = MunicipalityBuilder::aMunicipality()->build();
        $membership = MembershipBuilder::aMembership($user, $municipality)
            ->withRoles(MunicipalityRole::Instructor, MunicipalityRole::EventManager)->build();
        $this->save($user, $municipality, $membership);

        $this->em->clear();
        $reloaded = $this->em->find(MunicipalityMembership::class, $membership->id());
        self::assertNotNull($reloaded);
        self::assertSame([MunicipalityRole::EventManager, MunicipalityRole::Instructor], $reloaded->roles());
        self::assertSame('["EVENT_MANAGER", "INSTRUCTOR"]', $this->em->getConnection()->fetchOne('SELECT roles FROM municipality_membership'));
    }

    public function testPhysicalDeletionOfReferencedUserOrMunicipalityIsForbidden(): void
    {
        $user = UserBuilder::aUser()->build();
        $municipality = MunicipalityBuilder::aMunicipality()->build();
        $this->save($user, $municipality, MembershipBuilder::aMembership($user, $municipality)->build());

        $this->expectException(ForeignKeyConstraintViolationException::class);
        $this->em->getConnection()->executeStatement('DELETE FROM identity_user');
    }
}
