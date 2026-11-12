<?php

declare(strict_types=1);

namespace App\Tests\Functional\Archive;

use App\Tests\Support\ArchiveScenarioTestCase;
use App\Tests\Support\ParallelPhp;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Concurrence RÉELLE (processus PHP sur MySQL) : désignation contre retrait du rôle, deux désignations simultanées, deux gels simultanés (LOT 05.6.3). Un verrou tenu
 * par le test sur la ligne de la commune met les deux opérations en file ; elles sont ensuite relâchées ensemble. Invariants : aucune désignation ACTIVE pour un
 * utilisateur qui n'a plus le rôle, un seul gel ou une seule désignation actifs, aucun interblocage, refus métier (jamais une erreur technique).
 */
final class ArchiveRaceTest extends ArchiveScenarioTestCase
{
    private ?Connection $hold = null;

    protected function tearDown(): void
    {
        if (null !== $this->hold) {
            try {
                if ($this->hold->isTransactionActive()) {
                    $this->hold->rollBack();
                }
            } finally {
                $this->hold->close();
            }
        }
        parent::tearDown();
    }

    /**
     * @param list<array{string, list<string>}> $commands scripts dans l'ordre de DÉMARRAGE
     *
     * @return list<array<string, mixed>> résultats décodés dans le même ordre
     */
    private function raceUnderMunicipalityLock(string $municipalityId, array $commands): array
    {
        $this->hold = DriverManager::getConnection($this->em()->getConnection()->getParams());
        $this->hold->beginTransaction();
        $this->hold->executeQuery('SELECT id FROM municipality WHERE id = UNHEX(\''.str_replace('-', '', $municipalityId).'\') FOR UPDATE');
        $processes = [];
        foreach ($commands as [$script, $arguments]) {
            $processes[] = ParallelPhp::start($script, $arguments);
            usleep(900000); // laisse le processus atteindre le verrou avant le suivant : l'ordre de file est celui du démarrage
        }
        $this->hold->rollBack();
        $results = [];
        foreach ($processes as $process) {
            $raw = ParallelPhp::finish($process);
            $decoded = json_decode($raw['stdout'], true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded, $raw['stderr']);
            $results[] = $decoded;
        }

        return $results;
    }

    /** @return iterable<string, array{bool}> vrai : la désignation démarre en premier */
    public static function orders(): iterable
    {
        yield 'désignation puis retrait du rôle' => [true];
        yield 'retrait du rôle puis désignation' => [false];
    }

    #[DataProvider('orders')]
    public function testADesignationRacingAGroupRoleRemovalNeverLeavesAnActiveDesignationWithoutTheRole(bool $designationFirst): void
    {
        $admin = $this->admin->id()->toRfc4122();
        $target = $this->manager->id()->toRfc4122();
        $designate = ['archive-race.php', ['designate', $admin, $this->organizerId, $this->eventId(), $target]];
        $remove = ['archive-race.php', ['remove-role', $admin, $this->organizerId, $target]];

        $results = $this->raceUnderMunicipalityLock($this->organizerId, $designationFirst ? [$designate, $remove] : [$remove, $designate]);

        $connection = $this->em()->getConnection();
        $roles = (string) $connection->fetchOne('SELECT roles FROM municipality_membership WHERE user_id = UNHEX(:u) AND municipality_id = UNHEX(:m)', ['u' => str_replace('-', '', $target), 'm' => str_replace('-', '', $this->organizerId)]);
        $active = (int) $connection->fetchOne('SELECT COUNT(*) FROM archive_manager_designation WHERE active_marker = 1');
        self::assertStringNotContainsString('EVENT_MANAGER', $roles, 'le retrait du rôle a abouti');
        self::assertSame(0, $active, 'aucune désignation active pour un utilisateur qui n\'a plus le rôle');
        $removed = $results[$designationFirst ? 1 : 0];
        self::assertTrue($removed['ok'], 'le retrait du rôle aboutit toujours : '.json_encode($removed));
        $designated = $results[$designationFirst ? 0 : 1];
        if ($designationFirst) {
            self::assertTrue($designated['ok'], json_encode($designated));
            self::assertSame('ROLE_LOST', $connection->fetchOne('SELECT revocation_cause FROM archive_manager_designation'), 'désignée puis révoquée par le retrait du rôle');
        } else {
            self::assertFalse($designated['ok']);
            self::assertSame(\App\Shared\Domain\DomainRuleViolation::class, $designated['error'], 'refus métier : la cible n\'est plus gestionnaire');
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM archive_manager_designation'));
        }
    }

    public function testTwoSimultaneousDesignationsOfTheSamePersonLeaveExactlyOneActiveDesignation(): void
    {
        $admin = $this->admin->id()->toRfc4122();
        $command = ['archive-race.php', ['designate', $admin, $this->organizerId, $this->eventId(), $this->manager->id()->toRfc4122()]];

        $results = $this->raceUnderMunicipalityLock($this->organizerId, [$command, $command]);

        self::assertSame(1, \count(array_filter($results, static fn (array $r): bool => true === $r['ok'])), json_encode($results));
        $failed = array_values(array_filter($results, static fn (array $r): bool => true !== $r['ok']))[0];
        self::assertSame(\App\Shared\Domain\DomainRuleViolation::class, $failed['error']);
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM archive_manager_designation WHERE active_marker = 1'));
    }

    public function testTwoSimultaneousHoldsOnTheSameDossierLeaveExactlyOneActiveHold(): void
    {
        $this->close();
        $dossier = $this->dossierOf($this->organizerId);
        $first = ['archive-race.php', ['place-hold', $this->admin->id()->toRfc4122(), $this->organizerId, $dossier]];
        $second = ['archive-race.php', ['place-hold', $this->organizerAdmin2->id()->toRfc4122(), $this->organizerId, $dossier]];

        $results = $this->raceUnderMunicipalityLock($this->organizerId, [$first, $second]);

        // Les gels ne prennent pas le verrou de la commune : les deux processus s'exécutent en parallèle ; un seul gel actif subsiste, l'autre est refusé (métier ou conflit).
        self::assertSame(1, \count(array_filter($results, static fn (array $r): bool => true === $r['ok'])), json_encode($results));
        $failed = array_values(array_filter($results, static fn (array $r): bool => true !== $r['ok']))[0];
        self::assertContains($failed['error'], [\App\Shared\Domain\DomainRuleViolation::class, \App\Shared\Domain\OperationConflict::class], 'jamais une erreur technique : '.json_encode($failed));
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM archive_legal_hold WHERE active_marker = 1'));
    }
}
