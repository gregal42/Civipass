<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LOT 05.2 (DM-114, DT-048) : un lien d'accès bénéficiaire par ligne ; seule l'empreinte SHA-256 du secret est stockée. Création seule, aucune donnée touchée.
 */
final class Version20261109150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'LOT 05.2 : table permit_access_token (liens d\'accès bénéficiaire).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE permit_access_token (
              id BINARY(16) NOT NULL,
              secret_hash CHAR(64) NOT NULL COLLATE `utf8mb4_bin`,
              revoked_at DATETIME DEFAULT NULL,
              revoked_by_user_id BINARY(16) DEFAULT NULL,
              created_at DATETIME NOT NULL,
              expires_at DATETIME DEFAULT NULL,
              issued_by_user_id BINARY(16) NOT NULL,
              issued_by_municipality_id BINARY(16) NOT NULL,
              permit_id BINARY(16) NOT NULL,
              INDEX idx_permit_access_permit (permit_id),
              UNIQUE INDEX uniq_permit_access_hash (secret_hash),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              permit_access_token
            ADD
              CONSTRAINT FK_576C715A8439AF7 FOREIGN KEY (permit_id) REFERENCES permit_permit (id) ON DELETE RESTRICT
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE permit_access_token DROP FOREIGN KEY FK_576C715A8439AF7');
        $this->addSql('DROP TABLE permit_access_token');
    }
}
