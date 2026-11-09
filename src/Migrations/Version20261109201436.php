<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LOT 05.3.2 (DT-049 révisée, D1, D12) : table notification_secret (secrets bénéficiaires CHIFFRÉS, séparée de l'outbox) ; émetteur système des liens
 * (`permit_access_token.issuer_type`, auteur et commune émettrice facultatifs). Les liens existants deviennent `AGENT` (valeur par défaut) : aucune
 * donnée métier modifiée. `down()` échoue s'il existe des liens émis par le système (volontairement : aucune suppression physique).
 */
final class Version20261109201436 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'LOT 05.3.2 : table notification_secret et émetteur système de permit_access_token.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE notification_secret (id BINARY(16) NOT NULL, expires_at DATETIME NOT NULL, key_id VARCHAR(32) NOT NULL, nonce BINARY(24) NOT NULL, ciphertext VARBINARY(128) NOT NULL, outbox_id BINARY(16) NOT NULL, access_token_id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, INDEX idx_notification_secret_expires (expires_at), UNIQUE INDEX uniq_notification_secret_outbox (outbox_id), UNIQUE INDEX uniq_notification_secret_token (access_token_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE permit_access_token ADD issuer_type VARCHAR(10) DEFAULT \'AGENT\' NOT NULL, CHANGE issued_by_user_id issued_by_user_id BINARY(16) DEFAULT NULL, CHANGE issued_by_municipality_id issued_by_municipality_id BINARY(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE notification_secret');
        $this->addSql('ALTER TABLE permit_access_token DROP issuer_type, CHANGE issued_by_user_id issued_by_user_id BINARY(16) NOT NULL, CHANGE issued_by_municipality_id issued_by_municipality_id BINARY(16) NOT NULL');
    }
}
