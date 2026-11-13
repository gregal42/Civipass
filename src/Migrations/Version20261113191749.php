<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LOT 07.2 — M2 : colonne roles de municipality_admin_invitation (ajout seul, existant renseigné).
 */
final class Version20261113191749 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'LOT 07.2 (M2) : rôles portés par une invitation à rejoindre une commune.';
    }

    public function up(Schema $schema): void
    {
        // Trois temps : les invitations déjà émises (LOT 07.1) portent toutes le rôle administrateur.
        $this->addSql('ALTER TABLE municipality_admin_invitation ADD roles JSON DEFAULT NULL');
        $this->addSql("UPDATE municipality_admin_invitation SET roles = JSON_ARRAY('MUNICIPALITY_ADMIN') WHERE roles IS NULL");
        $this->addSql('ALTER TABLE municipality_admin_invitation MODIFY roles JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE municipality_admin_invitation DROP roles');
    }
}
