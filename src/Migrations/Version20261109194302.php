<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LOT 05.3.1 (DT-049) : table notification_outbox (intentions de notification, identifiants et dates seulement). Création seule, aucune donnée
 * touchée, aucune clé étrangère (module isolé ; aucune suppression physique des laissez-passer).
 */
final class Version20261109194302 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'LOT 05.3.1 : table notification_outbox.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE notification_outbox (id BINARY(16) NOT NULL, status VARCHAR(20) NOT NULL, attempts SMALLINT UNSIGNED DEFAULT 0 NOT NULL, leased_until DATETIME DEFAULT NULL, processed_at DATETIME DEFAULT NULL, last_error_code VARCHAR(40) DEFAULT NULL, deduplication_key VARCHAR(100) NOT NULL COLLATE `utf8mb4_bin`, type VARCHAR(30) NOT NULL, permit_id BINARY(16) NOT NULL, authorization_id BINARY(16) DEFAULT NULL, occurred_at DATETIME NOT NULL, available_at DATETIME NOT NULL, INDEX idx_notification_outbox_dispatch (status, available_at), INDEX idx_notification_outbox_processed (processed_at), INDEX idx_notification_outbox_permit (permit_id), UNIQUE INDEX uniq_notification_outbox_key (deduplication_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE notification_outbox');
    }
}
