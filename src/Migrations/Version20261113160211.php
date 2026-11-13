<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LOT 07.1 — M1 : table municipality_admin_invitation (ajout seul, aucune donnée existante touchée).
 */
final class Version20261113160211 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'LOT 07.1 (M1) : invitations d\'administrateur communal émises par CWS (ajout seul).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE municipality_admin_invitation (id BINARY(16) NOT NULL, expires_at DATETIME NOT NULL, consumed_at DATETIME DEFAULT NULL, revoked_at DATETIME DEFAULT NULL, delivery_status VARCHAR(10) NOT NULL, delivery_attempted_at DATETIME DEFAULT NULL, recipient_email VARCHAR(180) NOT NULL, token_hash CHAR(64) NOT NULL COLLATE `utf8mb4_bin`, created_at DATETIME NOT NULL, consumed_by BINARY(16) DEFAULT NULL, revoked_by BINARY(16) DEFAULT NULL, municipality_id BINARY(16) NOT NULL, created_by BINARY(16) NOT NULL, parent_id BINARY(16) DEFAULT NULL, INDEX idx_admin_invitation_municipality (municipality_id, created_at), UNIQUE INDEX uniq_admin_invitation_token_hash (token_hash), INDEX IDX_73D5ECADC27D003C (consumed_by), INDEX IDX_73D5ECAD8E5493E3 (revoked_by), INDEX IDX_73D5ECADAE6F181C (municipality_id), INDEX IDX_73D5ECADDE12AB56 (created_by), INDEX IDX_73D5ECAD727ACA70 (parent_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE municipality_admin_invitation ADD CONSTRAINT FK_73D5ECADC27D003C FOREIGN KEY (consumed_by) REFERENCES identity_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE municipality_admin_invitation ADD CONSTRAINT FK_73D5ECAD8E5493E3 FOREIGN KEY (revoked_by) REFERENCES identity_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE municipality_admin_invitation ADD CONSTRAINT FK_73D5ECADAE6F181C FOREIGN KEY (municipality_id) REFERENCES municipality (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE municipality_admin_invitation ADD CONSTRAINT FK_73D5ECADDE12AB56 FOREIGN KEY (created_by) REFERENCES identity_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE municipality_admin_invitation ADD CONSTRAINT FK_73D5ECAD727ACA70 FOREIGN KEY (parent_id) REFERENCES municipality_admin_invitation (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE municipality_admin_invitation DROP FOREIGN KEY FK_73D5ECADC27D003C');
        $this->addSql('ALTER TABLE municipality_admin_invitation DROP FOREIGN KEY FK_73D5ECAD8E5493E3');
        $this->addSql('ALTER TABLE municipality_admin_invitation DROP FOREIGN KEY FK_73D5ECADAE6F181C');
        $this->addSql('ALTER TABLE municipality_admin_invitation DROP FOREIGN KEY FK_73D5ECADDE12AB56');
        $this->addSql('ALTER TABLE municipality_admin_invitation DROP FOREIGN KEY FK_73D5ECAD727ACA70');
        $this->addSql('DROP TABLE municipality_admin_invitation');
    }
}
