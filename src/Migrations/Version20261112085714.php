<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LOT 05.6.3 (cadrage validé le 12 novembre 2026) : M2 `archive_manager_designation` (désignation d'archive, option B), M3 `archive_legal_hold` (gel de conservation,
 * épisodes conservés), M4 `archive_consultation` (journal des consultations), M5 `archive_statistics_coordination` (coordination statistique, L5-36, arbitrage A1).
 * Tables NOUVELLES, clés étrangères `RESTRICT` ; aucune table existante modifiée ; aucune donnée existante lue ni modifiée.
 * Retour arrière : voir `down()` : refusé dès qu'une de ces tables contient des lignes (preuves et habilitations non reconstituables) ; on restaure alors une sauvegarde.
 */
final class Version20261112085714 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'LOT 05.6.3 : archives sécurisées (désignations, gels de conservation, journal des consultations, coordination statistique).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE archive_consultation (id BINARY(16) NOT NULL, action VARCHAR(24) NOT NULL, reason VARCHAR(30) NOT NULL, reason_comment VARCHAR(200) DEFAULT NULL, attachment_id BINARY(16) DEFAULT NULL, occurred_at DATETIME NOT NULL, request_municipality_id BINARY(16) NOT NULL, user_id BINARY(16) NOT NULL, INDEX idx_archive_consultation_dossier (request_municipality_id, occurred_at), INDEX idx_archive_consultation_user (user_id, occurred_at), INDEX IDX_DAFF377D9D375344 (request_municipality_id), INDEX IDX_DAFF377DA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE archive_legal_hold (id BINARY(16) NOT NULL, active_marker SMALLINT DEFAULT NULL, last_reviewed_at DATETIME DEFAULT NULL, review_count SMALLINT DEFAULT 0 NOT NULL, lifted_at DATETIME DEFAULT NULL, lift_comment VARCHAR(200) DEFAULT NULL, reason VARCHAR(30) NOT NULL, comment VARCHAR(200) DEFAULT NULL, placed_at DATETIME NOT NULL, review_due_at DATETIME NOT NULL, last_reviewed_by_user_id BINARY(16) DEFAULT NULL, lifted_by_user_id BINARY(16) DEFAULT NULL, request_municipality_id BINARY(16) NOT NULL, placed_by_user_id BINARY(16) NOT NULL, INDEX idx_archive_hold_review (active_marker, review_due_at), UNIQUE INDEX uniq_archive_hold_active (request_municipality_id, active_marker), INDEX IDX_F022355527658D3E (last_reviewed_by_user_id), INDEX IDX_F0223555B4F7BB6E (lifted_by_user_id), INDEX IDX_F02235559D375344 (request_municipality_id), INDEX IDX_F0223555D4990EE2 (placed_by_user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE archive_manager_designation (id BINARY(16) NOT NULL, active_marker SMALLINT DEFAULT NULL, revoked_at DATETIME DEFAULT NULL, revocation_cause VARCHAR(24) DEFAULT NULL, designated_at DATETIME NOT NULL, revoked_by_user_id BINARY(16) DEFAULT NULL, participation_id BINARY(16) NOT NULL, user_id BINARY(16) NOT NULL, designated_by_user_id BINARY(16) NOT NULL, INDEX idx_archive_designation_user (user_id, active_marker), UNIQUE INDEX uniq_archive_designation_active (participation_id, user_id, active_marker), INDEX IDX_BECB119C999038D9 (revoked_by_user_id), INDEX IDX_BECB119C6ACE3B73 (participation_id), INDEX IDX_BECB119CA76ED395 (user_id), INDEX IDX_BECB119C5E60D79F (designated_by_user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE archive_statistics_coordination (id BINARY(16) NOT NULL, active_marker SMALLINT DEFAULT NULL, revoked_at DATETIME DEFAULT NULL, revocation_cause VARCHAR(24) DEFAULT NULL, granted_at DATETIME NOT NULL, revoked_by_user_id BINARY(16) DEFAULT NULL, event_id BINARY(16) NOT NULL, user_id BINARY(16) NOT NULL, granted_by_user_id BINARY(16) NOT NULL, INDEX idx_archive_stats_coordination_user (user_id, active_marker), UNIQUE INDEX uniq_archive_stats_coordination_active (event_id, user_id, active_marker), INDEX IDX_2F9554A4999038D9 (revoked_by_user_id), INDEX IDX_2F9554A471F7E88B (event_id), INDEX IDX_2F9554A4A76ED395 (user_id), INDEX IDX_2F9554A4F6097589 (granted_by_user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE archive_consultation ADD CONSTRAINT FK_DAFF377D9D375344 FOREIGN KEY (request_municipality_id) REFERENCES request_permit_request_municipality (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE archive_consultation ADD CONSTRAINT FK_DAFF377DA76ED395 FOREIGN KEY (user_id) REFERENCES identity_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE archive_legal_hold ADD CONSTRAINT FK_F022355527658D3E FOREIGN KEY (last_reviewed_by_user_id) REFERENCES identity_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE archive_legal_hold ADD CONSTRAINT FK_F0223555B4F7BB6E FOREIGN KEY (lifted_by_user_id) REFERENCES identity_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE archive_legal_hold ADD CONSTRAINT FK_F02235559D375344 FOREIGN KEY (request_municipality_id) REFERENCES request_permit_request_municipality (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE archive_legal_hold ADD CONSTRAINT FK_F0223555D4990EE2 FOREIGN KEY (placed_by_user_id) REFERENCES identity_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE archive_manager_designation ADD CONSTRAINT FK_BECB119C999038D9 FOREIGN KEY (revoked_by_user_id) REFERENCES identity_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE archive_manager_designation ADD CONSTRAINT FK_BECB119C6ACE3B73 FOREIGN KEY (participation_id) REFERENCES event_participation (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE archive_manager_designation ADD CONSTRAINT FK_BECB119CA76ED395 FOREIGN KEY (user_id) REFERENCES identity_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE archive_manager_designation ADD CONSTRAINT FK_BECB119C5E60D79F FOREIGN KEY (designated_by_user_id) REFERENCES identity_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE archive_statistics_coordination ADD CONSTRAINT FK_2F9554A4999038D9 FOREIGN KEY (revoked_by_user_id) REFERENCES identity_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE archive_statistics_coordination ADD CONSTRAINT FK_2F9554A471F7E88B FOREIGN KEY (event_id) REFERENCES event_event (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE archive_statistics_coordination ADD CONSTRAINT FK_2F9554A4A76ED395 FOREIGN KEY (user_id) REFERENCES identity_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE archive_statistics_coordination ADD CONSTRAINT FK_2F9554A4F6097589 FOREIGN KEY (granted_by_user_id) REFERENCES identity_user (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        foreach (['archive_consultation', 'archive_legal_hold', 'archive_manager_designation', 'archive_statistics_coordination'] as $table) {
            $rows = (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $table));
            $this->abortIf($rows > 0, sprintf('%s contient %d ligne(s) : le retour arrière détruirait des preuves ou des habilitations. Restaurez une sauvegarde de la base à la place.', $table, $rows));
        }
        $this->addSql('DROP TABLE archive_consultation');
        $this->addSql('DROP TABLE archive_legal_hold');
        $this->addSql('DROP TABLE archive_manager_designation');
        $this->addSql('DROP TABLE archive_statistics_coordination');
    }
}
