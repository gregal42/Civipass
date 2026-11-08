<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * LOT 04, incrément 04.1 : décision municipale immuable (DM-106) et référentiel des motifs de refus (DM-110). Deux tables, aucune
 * table existante modifiée. Les CHECK portent les invariants à plusieurs colonnes d'une même ligne ; l'unicité sur la part
 * empêche deux décisions initiales (garde-fou ultime sous concurrence).
 */
final class Version20261108100000 extends AbstractMigration
{
    private const REASONS = [
        ['ineligible_beneficiary', 'Bénéficiaire non éligible', 10, 0],
        ['reason_not_justified', 'Motif de la demande non justifié ou non vérifiable', 20, 0],
        ['documents_insufficient', 'Justificatifs insuffisants ou illisibles', 30, 0],
        ['zone_not_concerned', 'Zone ou secteur non concerné par le besoin', 40, 0],
        ['duplicate', 'Demande en doublon', 50, 0],
        ['inconsistent_information', 'Informations incohérentes', 60, 0],
        ['other', 'Autre', 70, 1],
    ];

    public function getDescription(): string
    {
        return 'LOT 04.1 : tables request_refusal_reason (avec valeurs initiales) et request_permit_request_decision.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE request_refusal_reason (id BINARY(16) NOT NULL, code VARCHAR(40) NOT NULL COLLATE `utf8mb4_bin`, label VARCHAR(150) NOT NULL, position SMALLINT DEFAULT 0 NOT NULL, active TINYINT DEFAULT 1 NOT NULL, needs_detail TINYINT DEFAULT 0 NOT NULL, UNIQUE INDEX uniq_request_refusal_reason_code (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE request_permit_request_decision (id BINARY(16) NOT NULL, outcome VARCHAR(10) NOT NULL, path VARCHAR(20) NOT NULL, refusal_reason_label VARCHAR(150) DEFAULT NULL, refusal_reason_code VARCHAR(40) DEFAULT NULL COLLATE `utf8mb4_bin`, refusal_comment VARCHAR(500) DEFAULT NULL, duration_mode VARCHAR(20) DEFAULT NULL, valid_from DATETIME DEFAULT NULL, valid_until DATETIME DEFAULT NULL, decided_at DATETIME NOT NULL, municipality_id BINARY(16) NOT NULL, refusal_reason_id BINARY(16) DEFAULT NULL, request_municipality_id BINARY(16) NOT NULL, decided_by_user_id BINARY(16) NOT NULL, INDEX idx_request_decision_municipality_decided (municipality_id, decided_at), UNIQUE INDEX uniq_request_decision_part (request_municipality_id), INDEX IDX_88C2639BAE6F181C (municipality_id), INDEX IDX_88C2639B2DDD5B8A (refusal_reason_id), INDEX IDX_88C2639B515F5BC8 (decided_by_user_id), PRIMARY KEY (id), CONSTRAINT chk_request_decision_outcome CHECK ((outcome = \'APPROVED\' AND duration_mode IS NOT NULL AND valid_from IS NOT NULL AND refusal_reason_id IS NULL AND refusal_reason_code IS NULL AND refusal_reason_label IS NULL AND refusal_comment IS NULL AND ((duration_mode = \'UNTIL_CLOSURE\' AND valid_until IS NULL) OR (duration_mode <> \'UNTIL_CLOSURE\' AND valid_until IS NOT NULL AND valid_until > valid_from))) OR (outcome = \'REFUSED\' AND refusal_reason_id IS NOT NULL AND refusal_reason_code IS NOT NULL AND refusal_reason_label IS NOT NULL AND duration_mode IS NULL AND valid_from IS NULL AND valid_until IS NULL))) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE request_permit_request_decision ADD CONSTRAINT FK_88C2639BAE6F181C FOREIGN KEY (municipality_id) REFERENCES municipality (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE request_permit_request_decision ADD CONSTRAINT FK_88C2639B2DDD5B8A FOREIGN KEY (refusal_reason_id) REFERENCES request_refusal_reason (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE request_permit_request_decision ADD CONSTRAINT FK_88C2639B9D375344 FOREIGN KEY (request_municipality_id) REFERENCES request_permit_request_municipality (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE request_permit_request_decision ADD CONSTRAINT FK_88C2639B515F5BC8 FOREIGN KEY (decided_by_user_id) REFERENCES identity_user (id) ON DELETE RESTRICT');
        foreach (self::REASONS as [$code, $label, $position, $needsDetail]) {
            $this->addSql(
                'INSERT INTO request_refusal_reason (id, code, label, position, active, needs_detail) SELECT :id, :code, :label, :position, 1, :needsDetail FROM DUAL'
                .' WHERE NOT EXISTS (SELECT 1 FROM request_refusal_reason WHERE code = :existingCode)',
                ['id' => Uuid::v7()->toBinary(), 'code' => $code, 'label' => $label, 'position' => $position, 'needsDetail' => $needsDetail, 'existingCode' => $code],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE request_permit_request_decision DROP FOREIGN KEY FK_88C2639BAE6F181C');
        $this->addSql('ALTER TABLE request_permit_request_decision DROP FOREIGN KEY FK_88C2639B2DDD5B8A');
        $this->addSql('ALTER TABLE request_permit_request_decision DROP FOREIGN KEY FK_88C2639B9D375344');
        $this->addSql('ALTER TABLE request_permit_request_decision DROP FOREIGN KEY FK_88C2639B515F5BC8');
        $this->addSql('DROP TABLE request_permit_request_decision');
        $this->addSql('DROP TABLE request_refusal_reason');
    }
}
