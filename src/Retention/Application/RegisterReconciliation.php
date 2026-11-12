<?php

declare(strict_types=1);

namespace App\Retention\Application;

use App\Identity\Domain\User;
use App\Retention\Domain\PurgeRecord;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Application\TransactionRunner;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditActor;
use App\Shared\Domain\AuditSubjectType;
use App\Shared\Domain\OperationNotAllowed;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Détection et réparation LIMITÉE des divergences entre le registre SQL et le registre hors base (LOT 05.6.4, 05.6.7). `check()` est en LECTURE SEULE : il qualifie (lignes SQL sans
 * fichier, lignes de fichier sans SQL, chaîne rompue, ancrage incohérent). `repair()` n'est possible que pour un super-administrateur CWS avec une RÉFÉRENCE D'APPROBATION, sur une chaîne
 * INTACTE, et ne fait que deux choses : AJOUTER au fichier une ligne SQL manquante ; RÉINSCRIRE en base une ligne du fichier dont les données sont absentes. Jamais de suppression ni de
 * réécriture d'une ligne ; une chaîne rompue ou un ancrage incohérent est un incident, jamais « réparé ». Une ligne de fichier dont les données sont présentes relève du rejeu.
 */
final class RegisterReconciliation
{
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $entityManager,
        private readonly PurgeRegisterFile $file,
        private readonly RegisterAnchors $anchors,
        private readonly RegisterAdopter $adopter,
        private readonly TransactionRunner $transaction,
        private readonly AuditTrail $audit,
        private readonly ClockInterface $clock,
    ) {
    }

    public function check(): RegisterReconciliationReport
    {
        $report = new RegisterReconciliationReport();
        $sql = [];
        foreach ($this->connection->fetchFirstColumn('SELECT id FROM retention_purge_record ORDER BY id') as $binary) {
            $sql[Uuid::fromBinary((string) $binary)->toRfc4122()] = true;
        }
        $read = $this->file->read();
        $report->invalidLines = $read['invalidLines'];
        $report->missingInFile = array_keys(array_diff_key($sql, $read['ids']));
        $report->fileOnly = array_keys(array_diff_key($read['ids'], $sql));
        $chain = $this->file->verify();
        $report->chainBreaks = array_map(static fn (array $b): string => $b['code'].'@'.$b['line'], $chain->breaks);
        $anchors = $this->anchors->verify($chain);
        // Un registre vide sans point d'ancrage n'est pas une anomalie (rien à ancrer encore).
        $report->anchorProblems = $chain->lines > 0 || $anchors->anchors > 0 ? $anchors->problems : [];

        return $report;
    }

    /**
     * @throws OperationNotAllowed opérateur non habilité
     * @throws ReplayRefused       approbation manquante ou chaîne rompue
     */
    public function repair(User $operator, string $approvalRef, bool $adopt): RegisterReconciliationReport
    {
        if (!$operator->isActive() || !$operator->isSuperAdmin()) {
            throw new OperationNotAllowed('Ressource inaccessible.');
        }
        if (1 !== preg_match(RetentionReplayService::APPROVAL_PATTERN, $approvalRef)) {
            throw new ReplayRefused('APPROVAL_REFERENCE_REQUIRED', 'Une référence d\'approbation écrite est obligatoire pour toute réparation du registre.');
        }
        $chain = $this->file->verify();
        if (!$chain->intact()) {
            throw new ReplayRefused('CHAIN_BROKEN', 'Chaîne du registre rompue : incident de sécurité, aucune réparation automatique.');
        }
        $report = $this->check();
        foreach ($report->missingInFile as $id) {
            $record = $this->entityManager->find(PurgeRecord::class, Uuid::fromString($id));
            if (null !== $record) {
                $this->file->append($record);
                ++$report->repaired;
            }
        }
        if ($adopt) {
            $lines = [];
            foreach ($this->file->lines() as $line) {
                if (null !== $line && \is_string($line['id'] ?? null)) {
                    $lines[$line['id']] = $line;
                }
            }
            foreach ($report->fileOnly as $id) {
                if (isset($lines[$id]) && $this->adopter->adopt($lines[$id])) {
                    ++$report->adopted;
                }
            }
        }
        $this->transaction->run(function () use ($operator, $approvalRef, $report): void {
            $this->audit->record(new AuditEvent($this->clock->now(), AuditAction::RetentionRegisterRepaired, AuditSubjectType::Retention, Uuid::v7(), null, AuditActor::user($operator->id()), null, ['approval' => $approvalRef, 'appended' => $report->repaired, 'adopted' => $report->adopted]));
        });

        return $report;
    }
}
