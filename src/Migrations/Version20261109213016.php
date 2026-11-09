<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LOT 05.3.4 (DT-049) : `notification_outbox.covered_authorizations`, identifiants des autorisations listées dans un message de mise à disposition
 * envoyé (aucune donnée personnelle) : preuve qu'une extension est redondante. Colonne facultative : aucune donnée existante modifiée.
 */
final class Version20261109213016 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'LOT 05.3.4 : colonne notification_outbox.covered_authorizations.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification_outbox ADD covered_authorizations JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification_outbox DROP covered_authorizations');
    }
}
