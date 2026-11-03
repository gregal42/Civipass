<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * LOT TECH-02.1, étape 3 : module Event (docs/lot-02-schema.md).
 *
 * - 7 nouvelles tables : event_type, event_event, event_participation, event_zone, event_zone_validity,
 *   event_reference_counter, municipality_commercial_access (clés étrangères RESTRICT, aucune suppression physique).
 * - Seule modification d'une table existante : audit_log reçoit operator_id et batch_id (nullables, indexés ;
 *   les lignes existantes gardent NULL).
 * - Données de référence : ligne unique du compteur de références (0) et neuf types d'événement validés
 *   (insertion idempotente par code : jamais d'écrasement ni de doublon).
 */
final class Version20261103070731 extends AbstractMigration
{
    /** Liste validée (DT-023), figée dans cette migration ; EventTypeInitializer rejoue la même initialisation. */
    private const EVENT_TYPES = [
        ['fire', 'Incendie'],
        ['flood', 'Inondation'],
        ['storm', 'Tempête / événement climatique'],
        ['accident', 'Accident / incident majeur'],
        ['security', 'Sécurité publique'],
        ['sport', 'Manifestation sportive'],
        ['cultural', 'Manifestation culturelle'],
        ['works', 'Travaux / intervention technique'],
        ['other', 'Autre événement'],
    ];

    public function getDescription(): string
    {
        return 'LOT TECH-02.1 : module Event (7 tables), audit_log.operator_id/batch_id, compteur de références et types d\'événement.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE event_event (
              id BINARY(16) NOT NULL,
              public_reference VARCHAR(20) NOT NULL COLLATE `utf8mb4_bin`,
              name VARCHAR(200) NOT NULL,
              description LONGTEXT DEFAULT NULL,
              operational_instructions LONGTEXT DEFAULT NULL,
              timezone VARCHAR(40) NOT NULL,
              starts_at DATETIME NOT NULL,
              ends_at DATETIME DEFAULT NULL,
              status VARCHAR(20) NOT NULL,
              suspension_reason LONGTEXT DEFAULT NULL,
              activated_at DATETIME DEFAULT NULL,
              closed_at DATETIME DEFAULT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              organizer_municipality_id BINARY(16) NOT NULL,
              type_id BINARY(16) NOT NULL,
              created_by BINARY(16) NOT NULL,
              INDEX idx_event_organizer_status (
                organizer_municipality_id, status
              ),
              INDEX idx_event_status_starts_at (status, starts_at),
              UNIQUE INDEX uniq_event_public_reference (public_reference),
              INDEX IDX_7AB5BB8BB491DB1E (organizer_municipality_id),
              INDEX IDX_7AB5BB8BC54C8C93 (type_id),
              INDEX IDX_7AB5BB8BDE12AB56 (created_by),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE event_participation (
              id BINARY(16) NOT NULL,
              status VARCHAR(20) NOT NULL,
              invitation_message LONGTEXT DEFAULT NULL,
              invited_at DATETIME DEFAULT NULL,
              last_invitation_sent_at DATETIME DEFAULT NULL,
              activated_at DATETIME DEFAULT NULL,
              suspended_at DATETIME DEFAULT NULL,
              closed_at DATETIME DEFAULT NULL,
              suspension_reason LONGTEXT DEFAULT NULL,
              updated_at DATETIME NOT NULL,
              created_at DATETIME NOT NULL,
              invited_by BINARY(16) DEFAULT NULL,
              event_id BINARY(16) NOT NULL,
              municipality_id BINARY(16) NOT NULL,
              INDEX idx_event_participation_municipality_status (municipality_id, status),
              UNIQUE INDEX uniq_event_participation_event_municipality (event_id, municipality_id),
              INDEX IDX_8F0C52E3421FF255 (invited_by),
              INDEX IDX_8F0C52E371F7E88B (event_id),
              INDEX IDX_8F0C52E3AE6F181C (municipality_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE event_reference_counter (
              id SMALLINT UNSIGNED NOT NULL,
              last_number BIGINT UNSIGNED DEFAULT 0 NOT NULL,
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE event_type (
              id BINARY(16) NOT NULL,
              code VARCHAR(40) NOT NULL COLLATE `utf8mb4_bin`,
              label VARCHAR(100) NOT NULL,
              sort_order SMALLINT DEFAULT 0 NOT NULL,
              active TINYINT DEFAULT 1 NOT NULL,
              UNIQUE INDEX uniq_event_type_code (code),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE event_zone (
              id BINARY(16) NOT NULL,
              label VARCHAR(150) NOT NULL,
              description LONGTEXT DEFAULT NULL,
              status VARCHAR(20) NOT NULL,
              confirmed_at DATETIME DEFAULT NULL,
              validity_mode VARCHAR(20) NOT NULL,
              deleted_at DATETIME DEFAULT NULL,
              updated_at DATETIME NOT NULL,
              type VARCHAR(30) NOT NULL,
              created_at DATETIME NOT NULL,
              participation_id BINARY(16) NOT NULL,
              created_by BINARY(16) NOT NULL,
              INDEX idx_event_zone_participation_status (
                participation_id, status, deleted_at
              ),
              INDEX IDX_BF208CAC6ACE3B73 (participation_id),
              INDEX IDX_BF208CACDE12AB56 (created_by),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE event_zone_validity (
              id BINARY(16) NOT NULL,
              kind VARCHAR(20) NOT NULL,
              start_date DATE NOT NULL,
              start_time TIME NOT NULL,
              end_date DATE DEFAULT NULL,
              end_time TIME NOT NULL,
              weekdays SMALLINT DEFAULT NULL,
              dst_choices JSON DEFAULT NULL,
              deleted_at DATETIME DEFAULT NULL,
              updated_at DATETIME NOT NULL,
              created_at DATETIME NOT NULL,
              zone_id BINARY(16) NOT NULL,
              INDEX idx_event_zone_validity_zone (zone_id, deleted_at),
              INDEX IDX_54E503929F2C3FAB (zone_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE municipality_commercial_access (
              id BINARY(16) NOT NULL,
              revoked_at DATETIME DEFAULT NULL,
              reason LONGTEXT NOT NULL,
              granted_at DATETIME NOT NULL,
              expires_at DATETIME DEFAULT NULL,
              created_at DATETIME NOT NULL,
              revoked_by BINARY(16) DEFAULT NULL,
              municipality_id BINARY(16) NOT NULL,
              granted_by BINARY(16) NOT NULL,
              INDEX idx_commercial_access_municipality (municipality_id, granted_at),
              INDEX IDX_4708D91D8E5493E3 (revoked_by),
              INDEX IDX_4708D91DAE6F181C (municipality_id),
              INDEX IDX_4708D91DA5FB753F (granted_by),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              event_event
            ADD
              CONSTRAINT FK_7AB5BB8BB491DB1E FOREIGN KEY (organizer_municipality_id) REFERENCES municipality (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              event_event
            ADD
              CONSTRAINT FK_7AB5BB8BC54C8C93 FOREIGN KEY (type_id) REFERENCES event_type (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              event_event
            ADD
              CONSTRAINT FK_7AB5BB8BDE12AB56 FOREIGN KEY (created_by) REFERENCES identity_user (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              event_participation
            ADD
              CONSTRAINT FK_8F0C52E3421FF255 FOREIGN KEY (invited_by) REFERENCES identity_user (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              event_participation
            ADD
              CONSTRAINT FK_8F0C52E371F7E88B FOREIGN KEY (event_id) REFERENCES event_event (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              event_participation
            ADD
              CONSTRAINT FK_8F0C52E3AE6F181C FOREIGN KEY (municipality_id) REFERENCES municipality (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              event_zone
            ADD
              CONSTRAINT FK_BF208CAC6ACE3B73 FOREIGN KEY (participation_id) REFERENCES event_participation (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              event_zone
            ADD
              CONSTRAINT FK_BF208CACDE12AB56 FOREIGN KEY (created_by) REFERENCES identity_user (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              event_zone_validity
            ADD
              CONSTRAINT FK_54E503929F2C3FAB FOREIGN KEY (zone_id) REFERENCES event_zone (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              municipality_commercial_access
            ADD
              CONSTRAINT FK_4708D91D8E5493E3 FOREIGN KEY (revoked_by) REFERENCES identity_user (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              municipality_commercial_access
            ADD
              CONSTRAINT FK_4708D91DAE6F181C FOREIGN KEY (municipality_id) REFERENCES municipality (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              municipality_commercial_access
            ADD
              CONSTRAINT FK_4708D91DA5FB753F FOREIGN KEY (granted_by) REFERENCES identity_user (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              audit_log
            ADD
              operator_id BINARY(16) DEFAULT NULL,
            ADD
              batch_id BINARY(16) DEFAULT NULL
        SQL);
        $this->addSql('CREATE INDEX idx_audit_operator ON audit_log (operator_id)');
        $this->addSql('CREATE INDEX idx_audit_batch ON audit_log (batch_id)');

        // Ligne unique du compteur global permanent des références publiques.
        $this->addSql('INSERT INTO event_reference_counter (id, last_number) VALUES (1, 0)');

        foreach (self::EVENT_TYPES as $position => [$code, $label]) {
            $this->addSql(
                'INSERT INTO event_type (id, code, label, sort_order, active) SELECT :id, :code, :label, :sortOrder, 1 FROM DUAL'
                .' WHERE NOT EXISTS (SELECT 1 FROM event_type WHERE code = :existingCode)',
                ['id' => Uuid::v7()->toBinary(), 'code' => $code, 'label' => $label, 'sortOrder' => ($position + 1) * 10, 'existingCode' => $code],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event_event DROP FOREIGN KEY FK_7AB5BB8BB491DB1E');
        $this->addSql('ALTER TABLE event_event DROP FOREIGN KEY FK_7AB5BB8BC54C8C93');
        $this->addSql('ALTER TABLE event_event DROP FOREIGN KEY FK_7AB5BB8BDE12AB56');
        $this->addSql('ALTER TABLE event_participation DROP FOREIGN KEY FK_8F0C52E3421FF255');
        $this->addSql('ALTER TABLE event_participation DROP FOREIGN KEY FK_8F0C52E371F7E88B');
        $this->addSql('ALTER TABLE event_participation DROP FOREIGN KEY FK_8F0C52E3AE6F181C');
        $this->addSql('ALTER TABLE event_zone DROP FOREIGN KEY FK_BF208CAC6ACE3B73');
        $this->addSql('ALTER TABLE event_zone DROP FOREIGN KEY FK_BF208CACDE12AB56');
        $this->addSql('ALTER TABLE event_zone_validity DROP FOREIGN KEY FK_54E503929F2C3FAB');
        $this->addSql('ALTER TABLE municipality_commercial_access DROP FOREIGN KEY FK_4708D91D8E5493E3');
        $this->addSql('ALTER TABLE municipality_commercial_access DROP FOREIGN KEY FK_4708D91DAE6F181C');
        $this->addSql('ALTER TABLE municipality_commercial_access DROP FOREIGN KEY FK_4708D91DA5FB753F');
        $this->addSql('DROP TABLE event_event');
        $this->addSql('DROP TABLE event_participation');
        $this->addSql('DROP TABLE event_reference_counter');
        $this->addSql('DROP TABLE event_type');
        $this->addSql('DROP TABLE event_zone');
        $this->addSql('DROP TABLE event_zone_validity');
        $this->addSql('DROP TABLE municipality_commercial_access');
        $this->addSql('DROP INDEX idx_audit_operator ON audit_log');
        $this->addSql('DROP INDEX idx_audit_batch ON audit_log');
        $this->addSql('ALTER TABLE audit_log DROP operator_id, DROP batch_id');
    }
}
