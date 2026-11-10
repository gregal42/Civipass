<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LOT 05.5.2 (DM-119 §4.2, modélisation B2) : `permit_authorization_period`, périodes CLOSES d'une autorisation (la période courante reste dans les colonnes
 * de `permit_authorization`). Une table neuve et deux clés étrangères `RESTRICT` (autorisation, révision qui a clos la période) ; unicité `(authorization_id, position)`.
 *
 * Données existantes : aucune n'est lue, modifiée ni déplacée (aucune autorisation n'a de période close à ce jour). Aucune suppression physique.
 * Retour arrière : voir `down()` : refusé dès qu'une période close existe (historique immuable ; on restaure alors une sauvegarde).
 */
final class Version20261110155643 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'LOT 05.5.2 : périodes closes des autorisations (permit_authorization_period).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE permit_authorization_period (
              id BINARY(16) NOT NULL,
              position SMALLINT UNSIGNED NOT NULL,
              duration_mode VARCHAR(20) NOT NULL,
              valid_from DATETIME NOT NULL,
              valid_until DATETIME NOT NULL,
              closed_at DATETIME NOT NULL,
              authorization_id BINARY(16) NOT NULL,
              closed_by_revision_id BINARY(16) NOT NULL,
              INDEX idx_permit_authorization_period_revision (closed_by_revision_id),
              UNIQUE INDEX uniq_permit_authorization_period_position (authorization_id, position),
              INDEX IDX_F20753902F8B0EB2 (authorization_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              permit_authorization_period
            ADD
              CONSTRAINT FK_F20753902F8B0EB2 FOREIGN KEY (authorization_id) REFERENCES permit_authorization (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              permit_authorization_period
            ADD
              CONSTRAINT FK_F20753907696CFB4 FOREIGN KEY (closed_by_revision_id) REFERENCES permit_revision (id) ON DELETE RESTRICT
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Retour arrière compatible avec l'immutabilité : refusé dès qu'une période close existe (la supprimer détruirait un historique).
        $periods = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM permit_authorization_period');
        $this->abortIf($periods > 0, sprintf('%d période(s) close(s) existent : le retour arrière détruirait un historique immuable. Restaurez une sauvegarde de la base à la place.', $periods));
        $this->addSql('ALTER TABLE permit_authorization_period DROP FOREIGN KEY FK_F20753902F8B0EB2');
        $this->addSql('ALTER TABLE permit_authorization_period DROP FOREIGN KEY FK_F20753907696CFB4');
        $this->addSql('DROP TABLE permit_authorization_period');
    }
}
