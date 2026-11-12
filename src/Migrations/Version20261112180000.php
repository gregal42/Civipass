<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LOT 05.6.6 (cadrage validé le 12 novembre 2026, décision D4) : M9, trois tables des droits des personnes : `rights_request` (demande reçue et traitée par UNE commune ; aucune
 * donnée du demandeur), `rights_request_dossier` (lien demande × dossier communal) et `archive_rectification_mention` (mention de rectification d'archive). Toutes les clés
 * étrangères sont `RESTRICT` (règle 8) : la purge d'un dossier supprime explicitement ses liens et ses mentions ; la ligne de la demande subsiste, sans donnée personnelle.
 * Les colonnes `conservation_checked_*` (vérification explicite des obligations de conservation avant un effacement, arbitrage D1) complètent la table approuvée.
 * Retour arrière : `down()` refusé si l'une des tables contient des lignes (on restaure alors une sauvegarde).
 */
final class Version20261112180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'LOT 05.6.6 : demandes de droits, liens avec les dossiers communaux et mentions de rectification.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE rights_request (id BINARY(16) NOT NULL, status VARCHAR(24) NOT NULL, extended_until DATETIME DEFAULT NULL, extension_reason VARCHAR(30) DEFAULT NULL, identity_verified_at DATETIME DEFAULT NULL, identity_method VARCHAR(30) DEFAULT NULL, conservation_checked_at DATETIME DEFAULT NULL, decided_at DATETIME DEFAULT NULL, decision VARCHAR(16) DEFAULT NULL, refusal_reason VARCHAR(30) DEFAULT NULL, executed_at DATETIME DEFAULT NULL, closed_at DATETIME DEFAULT NULL, export_available_until DATETIME DEFAULT NULL, export_downloads INT DEFAULT 0 NOT NULL, public_reference VARCHAR(20) NOT NULL, kind VARCHAR(24) NOT NULL, received_at DATETIME NOT NULL, due_at DATETIME NOT NULL, dossier_count INT DEFAULT 0 NOT NULL, identity_verified_by_user_id BINARY(16) DEFAULT NULL, conservation_checked_by_user_id BINARY(16) DEFAULT NULL, decided_by_user_id BINARY(16) DEFAULT NULL, municipality_id BINARY(16) NOT NULL, received_by_user_id BINARY(16) NOT NULL, INDEX idx_rights_request_municipality (municipality_id, status), INDEX idx_rights_request_due (due_at), UNIQUE INDEX uniq_rights_request_reference (public_reference), INDEX IDX_F970471AA0B303F9 (identity_verified_by_user_id), INDEX IDX_F970471A1655794A (conservation_checked_by_user_id), INDEX IDX_F970471A515F5BC8 (decided_by_user_id), INDEX IDX_F970471AAE6F181C (municipality_id), INDEX IDX_F970471A7C1D6AE1 (received_by_user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE rights_request_dossier (id BINARY(16) NOT NULL, rights_request_id BINARY(16) NOT NULL, request_municipality_id BINARY(16) NOT NULL, INDEX idx_rights_dossier_rm (request_municipality_id), UNIQUE INDEX uniq_rights_dossier (rights_request_id, request_municipality_id), INDEX IDX_6BD158B398CD4B91 (rights_request_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE archive_rectification_mention (id BINARY(16) NOT NULL, fields VARCHAR(100) NOT NULL, mention VARCHAR(500) NOT NULL, created_at DATETIME NOT NULL, request_municipality_id BINARY(16) NOT NULL, rights_request_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) NOT NULL, INDEX idx_rectification_rm (request_municipality_id), INDEX IDX_6889B38198CD4B91 (rights_request_id), INDEX IDX_6889B3817D182D95 (created_by_user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE rights_request ADD CONSTRAINT FK_F970471AA0B303F9 FOREIGN KEY (identity_verified_by_user_id) REFERENCES identity_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE rights_request ADD CONSTRAINT FK_F970471A1655794A FOREIGN KEY (conservation_checked_by_user_id) REFERENCES identity_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE rights_request ADD CONSTRAINT FK_F970471A515F5BC8 FOREIGN KEY (decided_by_user_id) REFERENCES identity_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE rights_request ADD CONSTRAINT FK_F970471AAE6F181C FOREIGN KEY (municipality_id) REFERENCES municipality (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE rights_request ADD CONSTRAINT FK_F970471A7C1D6AE1 FOREIGN KEY (received_by_user_id) REFERENCES identity_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE rights_request_dossier ADD CONSTRAINT FK_6BD158B398CD4B91 FOREIGN KEY (rights_request_id) REFERENCES rights_request (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE rights_request_dossier ADD CONSTRAINT FK_6BD158B39D375344 FOREIGN KEY (request_municipality_id) REFERENCES request_permit_request_municipality (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE archive_rectification_mention ADD CONSTRAINT FK_6889B3819D375344 FOREIGN KEY (request_municipality_id) REFERENCES request_permit_request_municipality (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE archive_rectification_mention ADD CONSTRAINT FK_6889B38198CD4B91 FOREIGN KEY (rights_request_id) REFERENCES rights_request (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE archive_rectification_mention ADD CONSTRAINT FK_6889B3817D182D95 FOREIGN KEY (created_by_user_id) REFERENCES identity_user (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        foreach (['archive_rectification_mention', 'rights_request_dossier', 'rights_request'] as $table) {
            $rows = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM '.$table);
            $this->abortIf($rows > 0, sprintf('%s contient %d ligne(s) : le retour arrière détruirait des traces de droits des personnes. Restaurez une sauvegarde à la place.', $table, $rows));
        }
        $this->addSql('DROP TABLE archive_rectification_mention');
        $this->addSql('DROP TABLE rights_request_dossier');
        $this->addSql('DROP TABLE rights_request');
    }
}
