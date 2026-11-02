<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261102164047 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'LOT 01.2 : identity_password_reset_request (demandes de réinitialisation du mot de passe).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE identity_password_reset_request (
              id BINARY(16) NOT NULL,
              expires_at DATETIME NOT NULL,
              used_at DATETIME DEFAULT NULL,
              token_hash CHAR(64) NOT NULL COLLATE `utf8mb4_bin`,
              created_at DATETIME NOT NULL,
              user_id BINARY(16) NOT NULL,
              INDEX idx_password_reset_user_created (user_id, created_at),
              UNIQUE INDEX uniq_password_reset_token_hash (token_hash),
              INDEX IDX_4CD1188BA76ED395 (user_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              identity_password_reset_request
            ADD
              CONSTRAINT FK_4CD1188BA76ED395 FOREIGN KEY (user_id) REFERENCES identity_user (id) ON DELETE RESTRICT
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE identity_password_reset_request DROP FOREIGN KEY FK_4CD1188BA76ED395');
        $this->addSql('DROP TABLE identity_password_reset_request');
    }
}
