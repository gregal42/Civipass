<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LOT 09 (DM-131, DT-056) : tables du contrôle hors connexion `control_activation` et `control_device` (schéma approuvé le 17 novembre 2026, cadrage §28).
 * Ajout seul : aucune donnée existante n'est touchée ; retour arrière = suppression des deux tables.
 */
final class Version20261117202913 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'LOT 09 : codes d\'activation et appareils du contrôle hors connexion (control_activation, control_device).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE control_activation (
              id BINARY(16) NOT NULL,
              uses_count SMALLINT UNSIGNED DEFAULT 0 NOT NULL,
              revoked_at DATETIME DEFAULT NULL,
              code_hash CHAR(64) NOT NULL COLLATE `utf8mb4_bin`,
              expires_at DATETIME NOT NULL,
              max_devices SMALLINT UNSIGNED NOT NULL,
              trust_warn_seconds INT UNSIGNED NOT NULL,
              trust_hard_seconds INT UNSIGNED NOT NULL,
              assurance_mode VARCHAR(10) NOT NULL,
              created_at DATETIME NOT NULL,
              revoked_by BINARY(16) DEFAULT NULL,
              municipality_id BINARY(16) NOT NULL,
              event_id BINARY(16) NOT NULL,
              created_by BINARY(16) NOT NULL,
              INDEX idx_control_activation_scope (
                municipality_id, event_id, created_at
              ),
              UNIQUE INDEX uniq_control_activation_code_hash (code_hash),
              INDEX IDX_51AC02AE8E5493E3 (revoked_by),
              INDEX IDX_51AC02AEAE6F181C (municipality_id),
              INDEX IDX_51AC02AE71F7E88B (event_id),
              INDEX IDX_51AC02AEDE12AB56 (created_by),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE control_device (
              id BINARY(16) NOT NULL,
              status VARCHAR(12) NOT NULL,
              pairing_code CHAR(6) DEFAULT NULL,
              approved_at DATETIME DEFAULT NULL,
              last_sync_at DATETIME DEFAULT NULL,
              last_snapshot_hash CHAR(64) DEFAULT NULL COLLATE `utf8mb4_bin`,
              sync_count INT UNSIGNED DEFAULT 0 NOT NULL,
              deactivated_at DATETIME DEFAULT NULL,
              deactivation_reason VARCHAR(12) DEFAULT NULL,
              label VARCHAR(60) NOT NULL,
              platform VARCHAR(10) NOT NULL,
              secret_hash CHAR(64) NOT NULL COLLATE `utf8mb4_bin`,
              registered_at DATETIME NOT NULL,
              expires_at DATETIME NOT NULL,
              approved_by BINARY(16) DEFAULT NULL,
              deactivated_by BINARY(16) DEFAULT NULL,
              activation_id BINARY(16) NOT NULL,
              INDEX idx_control_device_activation (activation_id, registered_at),
              UNIQUE INDEX uniq_control_device_secret_hash (secret_hash),
              INDEX IDX_1C4E626E4EA3CB3D (approved_by),
              INDEX IDX_1C4E626E99C7333 (deactivated_by),
              INDEX IDX_1C4E626E116B3934 (activation_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              control_activation
            ADD
              CONSTRAINT FK_51AC02AE8E5493E3 FOREIGN KEY (revoked_by) REFERENCES identity_user (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              control_activation
            ADD
              CONSTRAINT FK_51AC02AEAE6F181C FOREIGN KEY (municipality_id) REFERENCES municipality (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              control_activation
            ADD
              CONSTRAINT FK_51AC02AE71F7E88B FOREIGN KEY (event_id) REFERENCES event_event (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              control_activation
            ADD
              CONSTRAINT FK_51AC02AEDE12AB56 FOREIGN KEY (created_by) REFERENCES identity_user (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              control_device
            ADD
              CONSTRAINT FK_1C4E626E4EA3CB3D FOREIGN KEY (approved_by) REFERENCES identity_user (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              control_device
            ADD
              CONSTRAINT FK_1C4E626E99C7333 FOREIGN KEY (deactivated_by) REFERENCES identity_user (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              control_device
            ADD
              CONSTRAINT FK_1C4E626E116B3934 FOREIGN KEY (activation_id) REFERENCES control_activation (id) ON DELETE RESTRICT
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE control_activation DROP FOREIGN KEY FK_51AC02AE8E5493E3');
        $this->addSql('ALTER TABLE control_activation DROP FOREIGN KEY FK_51AC02AEAE6F181C');
        $this->addSql('ALTER TABLE control_activation DROP FOREIGN KEY FK_51AC02AE71F7E88B');
        $this->addSql('ALTER TABLE control_activation DROP FOREIGN KEY FK_51AC02AEDE12AB56');
        $this->addSql('ALTER TABLE control_device DROP FOREIGN KEY FK_1C4E626E4EA3CB3D');
        $this->addSql('ALTER TABLE control_device DROP FOREIGN KEY FK_1C4E626E99C7333');
        $this->addSql('ALTER TABLE control_device DROP FOREIGN KEY FK_1C4E626E116B3934');
        $this->addSql('DROP TABLE control_activation');
        $this->addSql('DROP TABLE control_device');
    }
}
