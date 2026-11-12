<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LOT 05.6.4 (cadrage validé le 12 novembre 2026) : M6 `retention_purge_record` (registre minimal des opérations de rétention, SANS clé étrangère par conception : il survit
 * aux lignes supprimées et ne porte aucune donnée personnelle) et M7 `permit_permit.first_decision_id` NULLABLE (arbitrage R1, option 2) : la clé étrangère `RESTRICT` est
 * conservée, aucune donnée historique n'est modifiée, la première décision reste obligatoire à l'émission (règle de l'entité) ; `NULL` n'apparaît que lorsque la décision
 * est effectivement purgée, dans la même transaction que sa suppression.
 * Retour arrière : `down()` refusé si le registre contient des lignes ou si une valeur `NULL` existe (on restaure alors une sauvegarde).
 */
final class Version20261112103402 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'LOT 05.6.4 : registre de purge (retention_purge_record) et première décision du laissez-passer nullable.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE retention_purge_record (id BINARY(16) NOT NULL, kind VARCHAR(20) NOT NULL, event_id BINARY(16) NOT NULL, event_reference VARCHAR(30) NOT NULL, municipality_id BINARY(16) DEFAULT NULL, dossier_id BINARY(16) DEFAULT NULL, request_id BINARY(16) DEFAULT NULL, occurred_at DATETIME NOT NULL, due_at DATETIME DEFAULT NULL, actor_user_id BINARY(16) DEFAULT NULL, counts JSON DEFAULT NULL, files_deleted INT DEFAULT 0 NOT NULL, recipients INT DEFAULT NULL, batch_id BINARY(16) DEFAULT NULL, INDEX idx_retention_record_event (event_id, kind), INDEX idx_retention_record_municipality (municipality_id, occurred_at), INDEX idx_retention_record_kind (kind, occurred_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE permit_permit CHANGE first_decision_id first_decision_id BINARY(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $records = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM retention_purge_record');
        $this->abortIf($records > 0, sprintf('retention_purge_record contient %d ligne(s) : le retour arrière détruirait la trace des purges. Restaurez une sauvegarde de la base à la place.', $records));
        $nulls = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM permit_permit WHERE first_decision_id IS NULL');
        $this->abortIf($nulls > 0, sprintf('%d laissez-passer ont une première décision purgée (NULL) : le retour à NOT NULL est impossible. Restaurez une sauvegarde de la base à la place.', $nulls));
        $this->addSql('DROP TABLE retention_purge_record');
        $this->addSql('ALTER TABLE permit_permit CHANGE first_decision_id first_decision_id BINARY(16) NOT NULL');
    }
}
