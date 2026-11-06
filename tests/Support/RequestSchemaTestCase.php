<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Base des tests d'intégration du module Request. Ils exigent le schéma de l'incrément 1 du LOT 03 (tables request_*) :
 * tant qu'il n'est pas migré sur la base de TEST (`make test-db`), ils sont SAUTÉS, jamais en échec, et aucune
 * migration n'est jouée par les tests.
 */
abstract class RequestSchemaTestCase extends EventSchemaTestCase
{
    private const REQUEST_TABLES = [
        'request_motif', 'request_beneficiary_category', 'request_permit_request', 'request_permit_request_municipality',
        'request_permit_request_zone', 'request_permit_request_vehicle', 'request_permit_request_attachment', 'request_reference_counter',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        if (!$this->em->getConnection()->createSchemaManager()->tablesExist(self::REQUEST_TABLES)) {
            self::markTestSkipped('Schéma du module Request non migré : test exécutable après `make test-db` (LOT 03, incrément 1).');
        }
    }
}
