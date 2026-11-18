<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LOT 08.6 (DM-139) : table `identity_cws_permission` (habilitations CWS persistées ; schéma approuvé le 18 novembre 2026, docs/lot-08-6-schema.md).
 * Ajout seul : aucune donnée existante n'est touchée et AUCUNE permission n'est attribuée ; retour arrière = suppression de la table.
 */
final class Version20261118072805 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'LOT 08.6 : habilitations CWS persistées (identity_cws_permission).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE identity_cws_permission (id BINARY(16) NOT NULL, active_key BINARY(16) DEFAULT NULL, revoked_at DATETIME DEFAULT NULL, permission VARCHAR(30) NOT NULL, granted_at DATETIME NOT NULL, reason VARCHAR(200) NOT NULL, revoked_by BINARY(16) DEFAULT NULL, user_id BINARY(16) NOT NULL, granted_by BINARY(16) NOT NULL, INDEX idx_cws_permission_user (user_id), UNIQUE INDEX uniq_cws_permission_active (active_key, permission), INDEX IDX_E577B1C58E5493E3 (revoked_by), INDEX IDX_E577B1C5A5FB753F (granted_by), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE identity_cws_permission ADD CONSTRAINT FK_E577B1C58E5493E3 FOREIGN KEY (revoked_by) REFERENCES identity_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE identity_cws_permission ADD CONSTRAINT FK_E577B1C5A76ED395 FOREIGN KEY (user_id) REFERENCES identity_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE identity_cws_permission ADD CONSTRAINT FK_E577B1C5A5FB753F FOREIGN KEY (granted_by) REFERENCES identity_user (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE identity_cws_permission DROP FOREIGN KEY FK_E577B1C58E5493E3');
        $this->addSql('ALTER TABLE identity_cws_permission DROP FOREIGN KEY FK_E577B1C5A76ED395');
        $this->addSql('ALTER TABLE identity_cws_permission DROP FOREIGN KEY FK_E577B1C5A5FB753F');
        $this->addSql('DROP TABLE identity_cws_permission');
    }
}
