<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LOT 08.5 (DM-136) : table `request_refusal_notice` (information des demandeurs refusés ; schéma approuvé le 18 novembre 2026, docs/lot-08-5-schema.md).
 * Ajout seul : aucune donnée existante n'est touchée et AUCUN envoi rétroactif n'est créé pour les refus historiques ; retour arrière = suppression de la table.
 */
final class Version20261118073710 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'LOT 08.5 : information des demandeurs refusés (request_refusal_notice).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE request_refusal_notice (id BINARY(16) NOT NULL, status VARCHAR(16) NOT NULL, attempts SMALLINT UNSIGNED DEFAULT 0 NOT NULL, leased_until DATETIME DEFAULT NULL, first_attempt_at DATETIME DEFAULT NULL, processed_at DATETIME DEFAULT NULL, last_error_code VARCHAR(40) DEFAULT NULL, manual_channel VARCHAR(12) DEFAULT NULL, manual_at DATETIME DEFAULT NULL, available_at DATETIME NOT NULL, created_at DATETIME NOT NULL, manual_by BINARY(16) DEFAULT NULL, decision_id BINARY(16) NOT NULL, municipality_id BINARY(16) NOT NULL, INDEX idx_refusal_notice_dispatch (status, available_at), INDEX idx_refusal_notice_municipality (municipality_id, status), UNIQUE INDEX uniq_refusal_notice_decision (decision_id), INDEX IDX_275A8EE11B52C6C4 (manual_by), INDEX IDX_275A8EE1AE6F181C (municipality_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE request_refusal_notice ADD CONSTRAINT FK_275A8EE11B52C6C4 FOREIGN KEY (manual_by) REFERENCES identity_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE request_refusal_notice ADD CONSTRAINT FK_275A8EE1BDEE7539 FOREIGN KEY (decision_id) REFERENCES request_permit_request_decision (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE request_refusal_notice ADD CONSTRAINT FK_275A8EE1AE6F181C FOREIGN KEY (municipality_id) REFERENCES municipality (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE request_refusal_notice DROP FOREIGN KEY FK_275A8EE11B52C6C4');
        $this->addSql('ALTER TABLE request_refusal_notice DROP FOREIGN KEY FK_275A8EE1BDEE7539');
        $this->addSql('ALTER TABLE request_refusal_notice DROP FOREIGN KEY FK_275A8EE1AE6F181C');
        $this->addSql('DROP TABLE request_refusal_notice');
    }
}
