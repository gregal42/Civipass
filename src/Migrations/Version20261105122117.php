<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LOT 03, incrément 2A : empreinte d'idempotence du dépôt (docs/lot-03-schema.md §4.8). Une table, aucune table existante
 * modifiée, aucune donnée.
 */
final class Version20261105122117 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'LOT 03 : table request_idempotency_key (idempotence du dépôt des demandes).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE request_idempotency_key (id BINARY(16) NOT NULL, key_hash CHAR(64) NOT NULL COLLATE `utf8mb4_bin`, created_at DATETIME NOT NULL, event_id BINARY(16) NOT NULL, request_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_request_idempotency_event_key (event_id, key_hash), INDEX IDX_4C898A1F71F7E88B (event_id), INDEX IDX_4C898A1F427EB8A5 (request_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE request_idempotency_key ADD CONSTRAINT FK_4C898A1F71F7E88B FOREIGN KEY (event_id) REFERENCES event_event (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE request_idempotency_key ADD CONSTRAINT FK_4C898A1F427EB8A5 FOREIGN KEY (request_id) REFERENCES request_permit_request (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE request_idempotency_key DROP FOREIGN KEY FK_4C898A1F71F7E88B');
        $this->addSql('ALTER TABLE request_idempotency_key DROP FOREIGN KEY FK_4C898A1F427EB8A5');
        $this->addSql('DROP TABLE request_idempotency_key');
    }
}
