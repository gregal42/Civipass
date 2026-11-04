<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Table `municipality_national_commune` : référentiel géographique local, synchronisé par `app:communes:sync`. Aucune clé étrangère
 * vers `municipality` (le lien est le code INSEE) ; aucune table existante n'est modifiée.
 */
final class Version20261104154924 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'UI-02 invitations : référentiel national des communes (API Géo / COG INSEE), séparé des communes inscrites (aucune modification de table existante).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE municipality_national_commune (id BINARY(16) NOT NULL, insee_code CHAR(5) NOT NULL COLLATE `utf8mb4_bin`, name VARCHAR(150) NOT NULL, search_name VARCHAR(150) NOT NULL, department_code VARCHAR(3) NOT NULL, region_code VARCHAR(3) DEFAULT NULL, postal_codes JSON NOT NULL, population INT DEFAULT NULL, active TINYINT NOT NULL, deactivated_at DATETIME DEFAULT NULL, last_seen_at DATETIME NOT NULL, last_sync_id CHAR(36) NOT NULL, updated_at DATETIME NOT NULL, source VARCHAR(30) NOT NULL, first_seen_at DATETIME NOT NULL, INDEX idx_national_commune_search (active, search_name), UNIQUE INDEX uniq_national_commune_insee_code (insee_code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE municipality_national_commune');
    }
}
