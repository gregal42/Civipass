<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Identity\Application\UserManager;
use App\Municipality\Application\MembershipManager;
use App\Municipality\Application\MunicipalityManager;
use App\Municipality\Domain\MunicipalityRole;
use App\Shared\Application\TransactionRunner;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\MunicipalityBuilder;
use App\Tests\Support\UserBuilder;

/**
 * Un refus métier ne doit pas fermer l'EntityManager : la requête doit pouvoir continuer.
 */
final class EntityManagerResilienceTest extends DatabaseTestCase
{
    public function testRefusedMembershipOperationsKeepTheEntityManagerUsable(): void
    {
        $municipality = MunicipalityBuilder::aMunicipality()->build();
        $admin = UserBuilder::aUser()->build();
        $outsider = UserBuilder::aUser()->build();
        $agent = UserBuilder::aUser()->build();
        $adminMembership = MembershipBuilder::aMembership($admin, $municipality)->withRoles(MunicipalityRole::MunicipalityAdmin)->build();
        $this->save($municipality, $admin, $outsider, $agent, $adminMembership);
        $manager = $this->service(MembershipManager::class);

        $refusals = [
            'droit insuffisant' => fn () => $manager->grant($agent, $municipality, [MunicipalityRole::Instructor], $outsider),
            'dernier administrateur (désactivation)' => fn () => $manager->deactivate($adminMembership, $admin),
            'dernier administrateur (rôles)' => fn () => $manager->changeRoles($adminMembership, [MunicipalityRole::Instructor], null),
            'auto-élévation' => fn () => $manager->changeRoles($adminMembership, [MunicipalityRole::MunicipalityAdmin, MunicipalityRole::EventManager], $admin),
            'rôles vides' => fn () => $manager->grant($agent, $municipality, [], $admin),
        ];
        foreach ($refusals as $label => $refused) {
            try {
                $refused();
                self::fail($label.' devait être refusé.');
            } catch (DomainRuleViolation) {
                self::assertTrue($this->em->isOpen(), 'EntityManager fermé après : '.$label);
            }
        }

        // La même requête peut poursuivre normalement, avec les mêmes objets.
        $granted = $manager->grant($agent, $municipality, [MunicipalityRole::Instructor], $admin);
        self::assertTrue($granted->isActive());
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM municipality_membership'));
    }

    public function testRefusedUserAndMunicipalityOperationsKeepTheEntityManagerUsable(): void
    {
        $root = UserBuilder::aUser()->superAdmin()->build();
        $plain = UserBuilder::aUser()->build();
        $this->save($root, $plain);
        $users = $this->service(UserManager::class);
        $municipalities = $this->service(MunicipalityManager::class);

        $refusals = [
            'dernier superadmin' => fn () => $users->revokeSuperAdmin($root, null),
            'dernier superadmin (désactivation)' => fn () => $users->deactivate($root, null),
            'acteur non superadmin' => fn () => $users->grantSuperAdmin($plain, $plain),
            'mot de passe faible' => fn () => $users->createUser('weak@example.test', 'short', 'A', 'B'),
            'commune par un non-superadmin' => fn () => $municipalities->create('Pirate', '75001', $plain),
        ];
        foreach ($refusals as $label => $refused) {
            try {
                $refused();
                self::fail($label.' devait être refusé.');
            } catch (DomainRuleViolation) {
                self::assertTrue($this->em->isOpen(), 'EntityManager fermé après : '.$label);
            }
        }

        $created = $municipalities->create('Paris 1er', '75101', $root);
        self::assertSame('75101', $created->inseeCode()->value);
        $users->grantSuperAdmin($plain, $root);
        self::assertTrue($plain->isSuperAdmin());
    }

    public function testDuplicateEmailRefusalKeepsTheEntityManagerOpen(): void
    {
        $users = $this->service(UserManager::class);
        $users->createUser('dup@example.test', 'correct horse battery', 'A', 'B');

        try {
            $users->createUser('dup@example.test', 'another long password', 'C', 'D');
            self::fail('Doublon attendu.');
        } catch (DomainRuleViolation) {
        }

        self::assertTrue($this->em->isOpen());
        $users->createUser('other@example.test', 'correct horse battery', 'E', 'F');
    }

    public function testRefusalAfterEntityModificationClosesTheEntityManager(): void
    {
        $user = UserBuilder::aUser()->build();
        $this->save($user);

        $caught = $this->runCapturingFailure(fn () => $this->service(TransactionRunner::class)->run(function () use ($user): void {
            $user->deactivate(new \DateTimeImmutable());
            throw new OperationNotAllowed('refus après modification');
        }));

        self::assertInstanceOf(OperationNotAllowed::class, $caught);
        self::assertFalse($this->em->isOpen(), 'Un état modifié puis refusé ne doit pas être réutilisé.');
        self::assertSame('1', (string) self::getContainer()->get('doctrine.dbal.default_connection')->fetchOne('SELECT active FROM identity_user'));
    }

    public function testTechnicalFailureRollsBackAndClosesTheEntityManager(): void
    {
        $runner = $this->service(TransactionRunner::class);

        $caught = $this->runCapturingFailure(static fn () => $runner->run(static function (): void {
            throw new \RuntimeException('panne');
        }));

        self::assertInstanceOf(\RuntimeException::class, $caught);
        self::assertFalse($this->em->isOpen());
    }

    public function testSuccessfulRunCommitsAndReturnsTheResult(): void
    {
        $user = UserBuilder::aUser()->build();

        $result = $this->service(TransactionRunner::class)->run(function () use ($user): string {
            $this->em->persist($user);

            return 'ok';
        });

        self::assertSame('ok', $result);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM identity_user'));
    }

    private function runCapturingFailure(callable $operation): ?\Throwable
    {
        try {
            $operation();
        } catch (\Throwable $throwable) {
            return $throwable;
        }

        return null;
    }

    public function testRefusalAfterAFlushClosesTheEntityManagerAndLeavesNothingInTheDatabase(): void
    {
        $user = UserBuilder::aUser()->build();

        $caught = $this->runCapturingFailure(fn () => $this->service(TransactionRunner::class)->run(function () use ($user): void {
            $this->em->persist($user);
            $this->em->flush(); // la ligne est écrite (dans la transaction), puis l'opération est refusée
            throw new OperationNotAllowed('refus après flush');
        }));

        self::assertInstanceOf(OperationNotAllowed::class, $caught);
        self::assertFalse($this->em->isOpen(), 'L\'ORM croirait la ligne écrite alors que la base est annulée.');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM identity_user'));
    }

    public function testNestedTransactionsAreExplicitlyRefused(): void
    {
        $runner = $this->service(TransactionRunner::class);
        $user = UserBuilder::aUser()->build();

        $caught = $this->runCapturingFailure(fn () => $runner->run(function () use ($runner, $user): void {
            $this->em->persist($user);
            $runner->run(static fn (): int => 1);
        }));

        self::assertInstanceOf(\LogicException::class, $caught);
        self::assertStringContainsString('imbriquées', $caught->getMessage());
        self::assertFalse($this->em->isOpen());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM identity_user'));
    }

    public function testRunnerRefusesToStartInsideAnExternalTransaction(): void
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $caught = $this->runCapturingFailure(fn () => $this->service(TransactionRunner::class)->run(static fn (): int => 1));
        } finally {
            $connection->rollBack();
        }

        self::assertInstanceOf(\LogicException::class, $caught);
        self::assertTrue($this->em->isOpen(), 'Refuser de démarrer ne touche pas à l\'état Doctrine.');
    }

    public function testRunnerIsReusableAfterARefusal(): void
    {
        $runner = $this->service(TransactionRunner::class);
        $this->runCapturingFailure(static fn () => $runner->run(static function (): void {
            throw new OperationNotAllowed('refus');
        }));

        self::assertSame('ok', $runner->run(static fn (): string => 'ok'));
    }
}
