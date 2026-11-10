<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LOT 05.5.1 (DM-119 §10) : schéma des révisions immuables des droits d'un laissez-passer.
 *
 *  - `permit_revision` : qui, quand, quoi, motif codé et commentaire (200 caractères, anonymisable), états avant et après (JSON sans donnée personnelle) ;
 *  - `permit_revision_vehicle` : plaques concernées (la seule donnée personnelle, nullable pour l'anonymisation) ; table PRÉPARÉE, utilisée au LOT 05.5.4 ;
 *  - `notification_outbox.revision_id` et `covered_revisions` : couverture des RÉVISIONS, distincte de celle des nouvelles autorisations.
 *
 * Données existantes : aucune n'est lue ni modifiée (tables neuves, deux colonnes facultatives). Aucune suppression physique.
 * Retour arrière : voir `down()` : refusé dès qu'une révision existe (l'historique est immuable ; on restaure alors une sauvegarde).
 */
final class Version20261110150151 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'LOT 05.5.1 : révisions immuables des droits (permit_revision, permit_revision_vehicle) et couverture des révisions dans l\'outbox.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE permit_revision (
              id BINARY(16) NOT NULL,
              anonymized_at DATETIME DEFAULT NULL,
              type VARCHAR(32) NOT NULL,
              occurred_at DATETIME NOT NULL,
              batch_id BINARY(16) NOT NULL,
              reason_code VARCHAR(30) DEFAULT NULL,
              reason_text VARCHAR(200) DEFAULT NULL,
              before_state JSON DEFAULT NULL,
              after_state JSON DEFAULT NULL,
              permit_id BINARY(16) NOT NULL,
              authorization_id BINARY(16) DEFAULT NULL,
              municipality_id BINARY(16) NOT NULL,
              actor_user_id BINARY(16) NOT NULL,
              INDEX idx_permit_revision_permit (permit_id, occurred_at, id),
              INDEX idx_permit_revision_authorization (authorization_id, occurred_at),
              INDEX IDX_C435557BA8439AF7 (permit_id),
              INDEX IDX_C435557B2F8B0EB2 (authorization_id),
              INDEX IDX_C435557BAE6F181C (municipality_id),
              INDEX IDX_C435557B859B83FF (actor_user_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE permit_revision_vehicle (
              id BINARY(16) NOT NULL,
              registration VARCHAR(12) DEFAULT NULL COLLATE `utf8mb4_bin`,
              format VARCHAR(3) NOT NULL,
              position SMALLINT NOT NULL,
              action VARCHAR(12) NOT NULL,
              revision_id BINARY(16) NOT NULL,
              vehicle_id BINARY(16) NOT NULL,
              INDEX idx_permit_revision_vehicle_revision (revision_id),
              INDEX IDX_A73D9628545317D1 (vehicle_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              permit_revision
            ADD
              CONSTRAINT FK_C435557BA8439AF7 FOREIGN KEY (permit_id) REFERENCES permit_permit (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              permit_revision
            ADD
              CONSTRAINT FK_C435557B2F8B0EB2 FOREIGN KEY (authorization_id) REFERENCES permit_authorization (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              permit_revision
            ADD
              CONSTRAINT FK_C435557BAE6F181C FOREIGN KEY (municipality_id) REFERENCES municipality (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              permit_revision
            ADD
              CONSTRAINT FK_C435557B859B83FF FOREIGN KEY (actor_user_id) REFERENCES identity_user (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              permit_revision_vehicle
            ADD
              CONSTRAINT FK_A73D96281DFA7C8F FOREIGN KEY (revision_id) REFERENCES permit_revision (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              permit_revision_vehicle
            ADD
              CONSTRAINT FK_A73D9628545317D1 FOREIGN KEY (vehicle_id) REFERENCES permit_vehicle (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              notification_outbox
            ADD
              revision_id BINARY(16) DEFAULT NULL,
            ADD
              covered_revisions JSON DEFAULT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Retour arrière compatible avec l'immutabilité : tant qu'aucune révision n'est écrite, les tables sont vides et peuvent être retirées ; dès la première
        // révision, supprimer la table détruirait l'historique, ce que les règles du projet interdisent. On refuse alors (restaurer une sauvegarde).
        $revisions = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM permit_revision');
        $this->abortIf($revisions > 0, sprintf('%d révision(s) existent : le retour arrière détruirait un historique immuable. Restaurez une sauvegarde de la base à la place.', $revisions));
        $this->addSql('ALTER TABLE permit_revision DROP FOREIGN KEY FK_C435557BA8439AF7');
        $this->addSql('ALTER TABLE permit_revision DROP FOREIGN KEY FK_C435557B2F8B0EB2');
        $this->addSql('ALTER TABLE permit_revision DROP FOREIGN KEY FK_C435557BAE6F181C');
        $this->addSql('ALTER TABLE permit_revision DROP FOREIGN KEY FK_C435557B859B83FF');
        $this->addSql('ALTER TABLE permit_revision_vehicle DROP FOREIGN KEY FK_A73D96281DFA7C8F');
        $this->addSql('ALTER TABLE permit_revision_vehicle DROP FOREIGN KEY FK_A73D9628545317D1');
        $this->addSql('DROP TABLE permit_revision');
        $this->addSql('DROP TABLE permit_revision_vehicle');
        $this->addSql('ALTER TABLE notification_outbox DROP revision_id, DROP covered_revisions');
    }
}
