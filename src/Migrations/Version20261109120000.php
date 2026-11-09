<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LOT 04, incrément 04.4 : compteur des corrections d'identité du bénéficiaire (DM-111, DT-045). Une seule colonne, non destructive :
 * les demandes existantes démarrent à 0.
 */
final class Version20261109120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'LOT 04.4 : colonne request_permit_request.identity_version.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE request_permit_request ADD identity_version INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE request_permit_request DROP identity_version');
    }
}
