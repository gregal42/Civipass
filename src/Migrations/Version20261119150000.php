<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * SEC-016 (LOT 11, correctif B) : `onboarding_administrator_validation`, demande de premier administrateur communal issue d'un lien personnel, validée par un
 * super-administrateur CWS (valideur, date, motif). Migration ADDITIVE : aucune table ni donnée existante n'est modifiée. Retour arrière : `down()` supprime la
 * table (les demandes en attente seraient perdues ; les liens déjà consommés ne se rouvrent pas : réémettre l'invitation).
 */
final class Version20261119150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SEC-016 : onboarding_administrator_validation (demande de premier administrateur, validation CWS).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE onboarding_administrator_validation (id BINARY(16) NOT NULL, status VARCHAR(8) NOT NULL, decided_at DATETIME DEFAULT NULL, decision_reason VARCHAR(500) DEFAULT NULL, requested_at DATETIME NOT NULL, decided_by BINARY(16) DEFAULT NULL, municipality_id BINARY(16) NOT NULL, user_id BINARY(16) NOT NULL, link_id BINARY(16) NOT NULL, INDEX idx_administrator_validation_status (status, requested_at), UNIQUE INDEX uniq_administrator_validation_link (link_id), INDEX IDX_8332F76239DAEC (decided_by), INDEX IDX_8332F76AE6F181C (municipality_id), INDEX IDX_8332F76A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE onboarding_administrator_validation ADD CONSTRAINT FK_8332F76239DAEC FOREIGN KEY (decided_by) REFERENCES identity_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE onboarding_administrator_validation ADD CONSTRAINT FK_8332F76AE6F181C FOREIGN KEY (municipality_id) REFERENCES municipality (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE onboarding_administrator_validation ADD CONSTRAINT FK_8332F76A76ED395 FOREIGN KEY (user_id) REFERENCES identity_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE onboarding_administrator_validation ADD CONSTRAINT FK_8332F76ADA40271 FOREIGN KEY (link_id) REFERENCES onboarding_invitation_link (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE onboarding_administrator_validation');
    }
}
