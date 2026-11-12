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
    private const EVENT_TABLES = ['onboarding_invitation_link', 'event_zone_validity', 'event_zone', 'event_participation', 'event_event', 'event_type', 'municipality_commercial_access', 'municipality_national_commune'];

    /**
     * Tables du LOT 03 (module Request), purgées dès qu'elles existent. Le compteur de références n'a pas de ligne
     * d'amorçage (une ligne par année, créée au premier dépôt) : il est entièrement vidé.
     */
    private const REQUEST_TABLES = ['request_idempotency_key', 'request_permit_request_decision', 'request_permit_request_zone', 'request_permit_request_attachment', 'request_permit_request_vehicle', 'request_permit_request_municipality', 'request_permit_request', 'request_motif', 'request_beneficiary_category', 'request_refusal_reason', 'request_reference_counter'];

    /**
     * Tables du LOT 05 (module Permit), purgées dès qu'elles existent (révisions en premier : elles référencent les autorisations et les plaques). Le compteur de références `LP-` est entièrement vidé (une ligne par
     * année, créée à la première émission).
     */
    private const PERMIT_TABLES = ['permit_authorization_period', 'permit_revision_vehicle', 'permit_revision', 'permit_access_token', 'permit_authorization_zone', 'permit_authorization', 'permit_vehicle', 'permit_verification_token', 'permit_permit', 'permit_reference_counter'];

    /** Tables du LOT 05.3 (module Notification), purgées dès qu'elles existent. */
    private const NOTIFICATION_TABLES = ['notification_secret', 'notification_outbox'];

    /** Tables du LOT 05.6.3 (module Archive), purgées dès qu'elles existent (elles référencent des dossiers, des participations, des événements et des utilisateurs). */
    private const ARCHIVE_TABLES = ['archive_rectification_mention', 'rights_request_dossier', 'rights_request', 'retention_purge_record', 'archive_consultation', 'archive_legal_hold', 'archive_manager_designation', 'archive_statistics_coordination'];

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

        $existing = array_filter([...self::ARCHIVE_TABLES, ...self::NOTIFICATION_TABLES, ...self::PERMIT_TABLES, ...self::REQUEST_TABLES, ...self::EVENT_TABLES], static fn (string $table): bool => $connection->createSchemaManager()->tablesExist([$table]));
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
