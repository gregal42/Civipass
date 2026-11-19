<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * SEC-006 (LOT 11, correctif B) : second facteur TOTP des comptes CWS : `identity_two_factor` (secret chiffré, pas de rejeu) et `identity_two_factor_backup_code`
 * (codes de secours à usage unique, empreintes seulement). Migration ADDITIVE : aucune table ni donnée existante n'est modifiée ; retour arrière : `down()`
 * (supprime les deux tables : les comptes CWS devront se réinscrire).
 */
final class Version20261119104516 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SEC-006 : second facteur TOTP des comptes CWS (identity_two_factor, identity_two_factor_backup_code).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE identity_two_factor (id BINARY(16) NOT NULL, confirmed_at DATETIME DEFAULT NULL, last_used_step BIGINT DEFAULT NULL, encrypted_secret VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, user_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_two_factor_user (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE identity_two_factor_backup_code (id BINARY(16) NOT NULL, used_at DATETIME DEFAULT NULL, code_hash CHAR(64) NOT NULL COLLATE `utf8mb4_bin`, created_at DATETIME NOT NULL, credential_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_two_factor_backup_hash (code_hash), INDEX IDX_D10A089E2558A7A5 (credential_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE identity_two_factor ADD CONSTRAINT FK_70A3B751A76ED395 FOREIGN KEY (user_id) REFERENCES identity_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE identity_two_factor_backup_code ADD CONSTRAINT FK_D10A089E2558A7A5 FOREIGN KEY (credential_id) REFERENCES identity_two_factor (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE identity_two_factor DROP FOREIGN KEY FK_70A3B751A76ED395');
        $this->addSql('ALTER TABLE identity_two_factor_backup_code DROP FOREIGN KEY FK_D10A089E2558A7A5');
        $this->addSql('DROP TABLE identity_two_factor');
        $this->addSql('DROP TABLE identity_two_factor_backup_code');
    }
}
