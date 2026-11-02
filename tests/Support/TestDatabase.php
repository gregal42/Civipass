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
    private const TABLES = ['audit_log', 'municipality_membership', 'municipality', 'identity_user'];

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

        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::TABLES as $table) {
            $connection->executeStatement('DELETE FROM '.$table);
        }
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        $em->clear();
    }
}
