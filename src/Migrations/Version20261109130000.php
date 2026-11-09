<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LOT 05, incrément 05.1 : module Permit (DM-112, DM-113, DT-046). Six tables créées, aucune table existante modifiée, aucune
 * donnée touchée. Unicités : un laissez-passer par demande, une référence `LP-` unique, un jeton de vérification unique et UN SEUL actif
 * par laissez-passer (`active_permit_id`, NULL une fois retiré), une autorisation par participation et par décision.
 */
final class Version20261109130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'LOT 05.1 : tables permit_permit, permit_verification_token, permit_authorization, permit_authorization_zone, permit_vehicle, permit_reference_counter.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE permit_authorization (
              id BINARY(16) NOT NULL,
              duration_mode VARCHAR(20) NOT NULL,
              valid_from DATETIME NOT NULL,
              valid_until DATETIME DEFAULT NULL,
              revoked_at DATETIME DEFAULT NULL,
              participation_id BINARY(16) NOT NULL,
              municipality_id BINARY(16) NOT NULL,
              decision_id BINARY(16) NOT NULL,
              permit_id BINARY(16) NOT NULL,
              UNIQUE INDEX uniq_permit_authorization_participation (permit_id, participation_id),
              UNIQUE INDEX uniq_permit_authorization_decision (decision_id),
              INDEX IDX_546A52746ACE3B73 (participation_id),
              INDEX IDX_546A5274AE6F181C (municipality_id),
              INDEX IDX_546A5274A8439AF7 (permit_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE permit_authorization_zone (
              id BINARY(16) NOT NULL,
              removed_at DATETIME DEFAULT NULL,
              zone_label VARCHAR(150) NOT NULL,
              authorization_id BINARY(16) NOT NULL,
              zone_id BINARY(16) NOT NULL,
              UNIQUE INDEX uniq_permit_authorization_zone (authorization_id, zone_id),
              INDEX IDX_B0FE79C2F8B0EB2 (authorization_id),
              INDEX IDX_B0FE79C9F2C3FAB (zone_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE permit_permit (
              id BINARY(16) NOT NULL,
              reference VARCHAR(20) NOT NULL COLLATE `utf8mb4_bin`,
              created_at DATETIME NOT NULL,
              request_id BINARY(16) NOT NULL,
              event_id BINARY(16) NOT NULL,
              first_decision_id BINARY(16) NOT NULL,
              UNIQUE INDEX uniq_permit_request (request_id),
              UNIQUE INDEX uniq_permit_reference (reference),
              INDEX IDX_B2FFD47471F7E88B (event_id),
              INDEX IDX_B2FFD474231B78FB (first_decision_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE permit_reference_counter (
              year SMALLINT UNSIGNED NOT NULL,
              last_number INT UNSIGNED DEFAULT 0 NOT NULL,
              PRIMARY KEY (year)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE permit_vehicle (
              id BINARY(16) NOT NULL,
              registration VARCHAR(12) NOT NULL COLLATE `utf8mb4_bin`,
              format VARCHAR(3) NOT NULL,
              position SMALLINT NOT NULL,
              removed_at DATETIME DEFAULT NULL,
              permit_id BINARY(16) NOT NULL,
              UNIQUE INDEX uniq_permit_vehicle_registration (permit_id, registration),
              INDEX IDX_F16E006AA8439AF7 (permit_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE permit_verification_token (
              id BINARY(16) NOT NULL,
              token CHAR(32) NOT NULL COLLATE `utf8mb4_bin`,
              active_permit_id BINARY(16) DEFAULT NULL,
              retired_at DATETIME DEFAULT NULL,
              created_at DATETIME NOT NULL,
              permit_id BINARY(16) NOT NULL,
              UNIQUE INDEX uniq_permit_token_value (token),
              UNIQUE INDEX uniq_permit_token_active (active_permit_id),
              INDEX IDX_320EFFD0A8439AF7 (permit_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              permit_authorization
            ADD
              CONSTRAINT FK_546A52746ACE3B73 FOREIGN KEY (participation_id) REFERENCES event_participation (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              permit_authorization
            ADD
              CONSTRAINT FK_546A5274AE6F181C FOREIGN KEY (municipality_id) REFERENCES municipality (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              permit_authorization
            ADD
              CONSTRAINT FK_546A5274BDEE7539 FOREIGN KEY (decision_id) REFERENCES request_permit_request_decision (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              permit_authorization
            ADD
              CONSTRAINT FK_546A5274A8439AF7 FOREIGN KEY (permit_id) REFERENCES permit_permit (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              permit_authorization_zone
            ADD
              CONSTRAINT FK_B0FE79C2F8B0EB2 FOREIGN KEY (authorization_id) REFERENCES permit_authorization (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              permit_authorization_zone
            ADD
              CONSTRAINT FK_B0FE79C9F2C3FAB FOREIGN KEY (zone_id) REFERENCES event_zone (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              permit_permit
            ADD
              CONSTRAINT FK_B2FFD474427EB8A5 FOREIGN KEY (request_id) REFERENCES request_permit_request (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              permit_permit
            ADD
              CONSTRAINT FK_B2FFD47471F7E88B FOREIGN KEY (event_id) REFERENCES event_event (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              permit_permit
            ADD
              CONSTRAINT FK_B2FFD474231B78FB FOREIGN KEY (first_decision_id) REFERENCES request_permit_request_decision (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              permit_vehicle
            ADD
              CONSTRAINT FK_F16E006AA8439AF7 FOREIGN KEY (permit_id) REFERENCES permit_permit (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              permit_verification_token
            ADD
              CONSTRAINT FK_320EFFD0A8439AF7 FOREIGN KEY (permit_id) REFERENCES permit_permit (id) ON DELETE RESTRICT
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE permit_authorization DROP FOREIGN KEY FK_546A52746ACE3B73');
        $this->addSql('ALTER TABLE permit_authorization DROP FOREIGN KEY FK_546A5274AE6F181C');
        $this->addSql('ALTER TABLE permit_authorization DROP FOREIGN KEY FK_546A5274BDEE7539');
        $this->addSql('ALTER TABLE permit_authorization DROP FOREIGN KEY FK_546A5274A8439AF7');
        $this->addSql('ALTER TABLE permit_authorization_zone DROP FOREIGN KEY FK_B0FE79C2F8B0EB2');
        $this->addSql('ALTER TABLE permit_authorization_zone DROP FOREIGN KEY FK_B0FE79C9F2C3FAB');
        $this->addSql('ALTER TABLE permit_permit DROP FOREIGN KEY FK_B2FFD474427EB8A5');
        $this->addSql('ALTER TABLE permit_permit DROP FOREIGN KEY FK_B2FFD47471F7E88B');
        $this->addSql('ALTER TABLE permit_permit DROP FOREIGN KEY FK_B2FFD474231B78FB');
        $this->addSql('ALTER TABLE permit_vehicle DROP FOREIGN KEY FK_F16E006AA8439AF7');
        $this->addSql('ALTER TABLE permit_verification_token DROP FOREIGN KEY FK_320EFFD0A8439AF7');
        $this->addSql('DROP TABLE permit_authorization');
        $this->addSql('DROP TABLE permit_authorization_zone');
        $this->addSql('DROP TABLE permit_permit');
        $this->addSql('DROP TABLE permit_reference_counter');
        $this->addSql('DROP TABLE permit_vehicle');
        $this->addSql('DROP TABLE permit_verification_token');
    }
}
