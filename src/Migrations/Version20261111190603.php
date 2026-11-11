<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LOT 05.6.2 (DM-120 D4, M1) : `event_event.closure_statistics`, compteurs internes de clôture (JSON nullable, écriture unique).
 *
 * Données existantes : aucune ligne n'est lue, modifiée ni déplacée ; NULL signifie « non calculées » (événements déjà clôturés : aucun rattrapage).
 * Aucun index, aucune clé étrangère.
 * Retour arrière : voir `down()` : refusé dès qu'une statistique existe (donnée non reconstituable après une purge) ; on restaure alors une sauvegarde.
 */
final class Version20261111190603 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'LOT 05.6.2 : compteurs internes de clôture (event_event.closure_statistics).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event_event ADD closure_statistics JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $recorded = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM event_event WHERE closure_statistics IS NOT NULL');
        $this->abortIf($recorded > 0, sprintf('%d événement(s) portent des compteurs de clôture : le retour arrière les détruirait. Restaurez une sauvegarde de la base à la place.', $recorded));
        $this->addSql('ALTER TABLE event_event DROP closure_statistics');
    }
}
