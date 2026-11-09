<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LOT 05.3.3 (DT-049) : `notification_outbox.first_attempt_at`, début du plafond de 24 heures des erreurs SMTP (premier essai réellement tenté,
 * jamais une attente de clé). Colonne facultative : aucune donnée existante modifiée.
 */
final class Version20261109203506 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'LOT 05.3.3 : colonne notification_outbox.first_attempt_at.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification_outbox ADD first_attempt_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification_outbox DROP first_attempt_at');
    }
}
