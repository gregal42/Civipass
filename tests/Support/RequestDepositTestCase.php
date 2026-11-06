<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Base des tests du service de dépôt : exige en plus la table d'idempotence de l'incrément 2A (migration
 * `Version20261105122117`). Tant qu'elle n'est pas migrée sur la base de TEST, ces tests sont SAUTÉS.
 */
abstract class RequestDepositTestCase extends RequestSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!$this->em->getConnection()->createSchemaManager()->tablesExist(['request_idempotency_key'])) {
            self::markTestSkipped('Table request_idempotency_key non migrée : test exécutable après `make test-db` (LOT 03, incrément 2A).');
        }
    }
}
