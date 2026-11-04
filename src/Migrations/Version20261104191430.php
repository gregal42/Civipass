<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Table `onboarding_invitation_link` : seule l'empreinte SHA-256 du jeton est conservée. Clés étrangères RESTRICT (aucune
 * suppression physique). Aucune table existante n'est modifiée.
 */
final class Version20261104191430 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'UI-02 invitations : liens sécurisés d\'invitation intercommunale (jeton haché, expiration, usage unique), rattachés à une participation.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE onboarding_invitation_link (id BINARY(16) NOT NULL, expires_at DATETIME NOT NULL, consumed_at DATETIME DEFAULT NULL, revoked_at DATETIME DEFAULT NULL, delivery_status VARCHAR(10) NOT NULL, delivery_attempted_at DATETIME DEFAULT NULL, kind VARCHAR(13) NOT NULL, recipient_email VARCHAR(180) NOT NULL, token_hash CHAR(64) NOT NULL COLLATE `utf8mb4_bin`, created_at DATETIME NOT NULL, participation_id BINARY(16) NOT NULL, created_by BINARY(16) DEFAULT NULL, parent_link_id BINARY(16) DEFAULT NULL, INDEX idx_invitation_link_participation (participation_id, kind, created_at), UNIQUE INDEX uniq_invitation_link_token_hash (token_hash), INDEX IDX_DF4EA9B76ACE3B73 (participation_id), INDEX IDX_DF4EA9B7DE12AB56 (created_by), INDEX IDX_DF4EA9B7DFEC7637 (parent_link_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE onboarding_invitation_link ADD CONSTRAINT FK_DF4EA9B76ACE3B73 FOREIGN KEY (participation_id) REFERENCES event_participation (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE onboarding_invitation_link ADD CONSTRAINT FK_DF4EA9B7DE12AB56 FOREIGN KEY (created_by) REFERENCES identity_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE onboarding_invitation_link ADD CONSTRAINT FK_DF4EA9B7DFEC7637 FOREIGN KEY (parent_link_id) REFERENCES onboarding_invitation_link (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE onboarding_invitation_link DROP FOREIGN KEY FK_DF4EA9B76ACE3B73');
        $this->addSql('ALTER TABLE onboarding_invitation_link DROP FOREIGN KEY FK_DF4EA9B7DE12AB56');
        $this->addSql('ALTER TABLE onboarding_invitation_link DROP FOREIGN KEY FK_DF4EA9B7DFEC7637');
        $this->addSql('DROP TABLE onboarding_invitation_link');
    }
}
