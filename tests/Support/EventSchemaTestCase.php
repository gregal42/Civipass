<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Base des tests d'intégration du module Event. Ils exigent le schéma de l'étape 3 (tables event_*,
 * municipality_commercial_access, ligne unique du compteur) : tant qu'il n'est pas migré sur la base de TEST
 * (`make test-db`), ils sont SAUTÉS, jamais en échec, et aucune migration n'est jouée par les tests.
 */
abstract class EventSchemaTestCase extends DatabaseTestCase
{
    private const REQUIRED_TABLES = [
        'event_type', 'event_event', 'event_participation', 'event_zone', 'event_zone_validity',
        'event_reference_counter', 'municipality_commercial_access',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        if (!$this->em->getConnection()->createSchemaManager()->tablesExist(self::REQUIRED_TABLES)) {
            self::markTestSkipped('Schéma du module Event non migré : test exécutable après la migration de l\'étape 3 (make test-db).');
        }
    }

    protected function transactional(callable $callback): mixed
    {
        return $this->em->wrapInTransaction($callback);
    }
}
