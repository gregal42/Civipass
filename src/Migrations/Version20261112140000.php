<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LOT 05.6.5 (cadrage validé le 12 novembre 2026, décision D2) : M8, deux colonnes NULLABLES sur `event_event` pour la dormance : prise de l'alerte (garde atomique contre
 * les doublons entre exécutions concurrentes) et fin du report motivé. Aucune donnée existante modifiée (NULL), aucun index, aucun effet sur le statut ni les droits.
 * Retour arrière : suppression des deux colonnes (seul l'état d'alerte est perdu ; l'audit subsiste).
 */
final class Version20261112140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'LOT 05.6.5 : état d\'alerte et report de dormance des événements (event_event).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event_event ADD dormancy_alerted_at DATETIME DEFAULT NULL, ADD dormancy_snoozed_until DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event_event DROP dormancy_alerted_at, DROP dormancy_snoozed_until');
    }
}
