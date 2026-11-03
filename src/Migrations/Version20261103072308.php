<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LOT TECH-02.1 : Event.suspendedAt (DM-081) : date de la dernière suspension connue. Colonne nullable : les
 * événements existants gardent NULL. Seule modification : event_event.
 */
final class Version20261103072308 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'LOT TECH-02.1 : event_event.suspended_at (dernière suspension connue).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event_event ADD suspended_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event_event DROP suspended_at');
    }
}
