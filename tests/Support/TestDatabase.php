<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Assert;

/**
 * Seul point de purge des tables : refuse toute base dont le nom ne contient pas « _test »
 * (la base de développement ne doit jamais être touchée).
 */
final class TestDatabase
{
    private const TABLES = ['audit_log', 'municipality_membership', 'municipality', 'identity_password_reset_request', 'identity_user'];

    /**
     * Tables du LOT TECH-02.1, purgées dès qu'elles existent (schéma migré à l'étape 3). `event_reference_counter`
     * est exclue : sa ligne unique est créée par la migration et ne doit pas être supprimée.
     */
    private const EVENT_TABLES = ['event_zone_validity', 'event_zone', 'event_participation', 'event_event', 'event_type', 'municipality_commercial_access'];

    public static function reset(EntityManagerInterface $em): void
    {
        $connection = $em->getConnection();
        try {
            $database = (string) $connection->getDatabase();
            $connection->executeQuery('SELECT 1 FROM identity_user LIMIT 1');
        } catch (\Throwable $e) {
            Assert::fail("Base de test indisponible ou non migrée : lancez `make test-db` (voir docs/testing.md).\n".$e->getMessage());
        }
        if (!str_contains($database, '_test')) {
            Assert::fail(sprintf('Base "%s" refusée : les tests destructifs exigent une base de test (_test).', $database));
        }

        $existing = array_filter(self::EVENT_TABLES, static fn (string $table): bool => $connection->createSchemaManager()->tablesExist([$table]));
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach ([...$existing, ...self::TABLES] as $table) {
            $connection->executeStatement('DELETE FROM '.$table);
        }
        if (in_array('event_event', $existing, true) && $connection->createSchemaManager()->tablesExist(['event_reference_counter'])) {
            $connection->executeStatement('UPDATE event_reference_counter SET last_number = 0');
        }
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        $em->clear();
    }
}
