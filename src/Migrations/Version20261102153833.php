<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261102153833 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'LOT 01.1 : identity_user, municipality, municipality_membership, audit_log.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE audit_log (
              id BINARY(16) NOT NULL,
              occurred_at DATETIME NOT NULL,
              action VARCHAR(60) NOT NULL,
              subject_type VARCHAR(40) NOT NULL,
              subject_id BINARY(16) NOT NULL,
              municipality_id BINARY(16) DEFAULT NULL,
              actor_id BINARY(16) DEFAULT NULL,
              origin VARCHAR(16) NOT NULL,
              previous_value JSON DEFAULT NULL,
              new_value JSON NOT NULL,
              INDEX idx_audit_subject (
                subject_type, subject_id, occurred_at
              ),
              INDEX idx_audit_municipality (municipality_id, occurred_at),
              INDEX idx_audit_actor (actor_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE identity_user (
              id BINARY(16) NOT NULL,
              email VARCHAR(180) NOT NULL COLLATE `utf8mb4_bin`,
              active TINYINT DEFAULT 1 NOT NULL,
              super_admin TINYINT DEFAULT 0 NOT NULL,
              last_login_at DATETIME DEFAULT NULL,
              updated_at DATETIME NOT NULL,
              password VARCHAR(255) NOT NULL,
              first_name VARCHAR(100) NOT NULL,
              last_name VARCHAR(100) NOT NULL,
              created_at DATETIME NOT NULL,
              UNIQUE INDEX uniq_identity_user_email (email),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE municipality (
              id BINARY(16) NOT NULL,
              insee_code CHAR(5) NOT NULL COLLATE `utf8mb4_bin`,
              status VARCHAR(20) NOT NULL,
              contact_name VARCHAR(150) DEFAULT NULL,
              contact_email VARCHAR(180) DEFAULT NULL,
              contact_phone VARCHAR(16) DEFAULT NULL,
              address_line VARCHAR(255) DEFAULT NULL,
              postal_code CHAR(5) DEFAULT NULL,
              city VARCHAR(100) DEFAULT NULL,
              updated_at DATETIME NOT NULL,
              name VARCHAR(150) NOT NULL,
              created_at DATETIME NOT NULL,
              UNIQUE INDEX uniq_municipality_insee_code (insee_code),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE municipality_membership (
              id BINARY(16) NOT NULL,
              roles JSON NOT NULL,
              active TINYINT DEFAULT 1 NOT NULL,
              updated_at DATETIME NOT NULL,
              created_at DATETIME NOT NULL,
              user_id BINARY(16) NOT NULL,
              municipality_id BINARY(16) NOT NULL,
              UNIQUE INDEX uniq_membership_user_municipality (user_id, municipality_id),
              INDEX IDX_65BAF03DA76ED395 (user_id),
              INDEX IDX_65BAF03DAE6F181C (municipality_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              municipality_membership
            ADD
              CONSTRAINT FK_65BAF03DA76ED395 FOREIGN KEY (user_id) REFERENCES identity_user (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              municipality_membership
            ADD
              CONSTRAINT FK_65BAF03DAE6F181C FOREIGN KEY (municipality_id) REFERENCES municipality (id) ON DELETE RESTRICT
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE municipality_membership DROP FOREIGN KEY FK_65BAF03DA76ED395');
        $this->addSql('ALTER TABLE municipality_membership DROP FOREIGN KEY FK_65BAF03DAE6F181C');
        $this->addSql('DROP TABLE audit_log');
        $this->addSql('DROP TABLE identity_user');
        $this->addSql('DROP TABLE municipality');
        $this->addSql('DROP TABLE municipality_membership');
    }
}
