<?php

declare(strict_types=1);

namespace App\Retention\Application;

use App\Retention\Domain\PurgeRecord;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Détection et RÉCONCILIATION des divergences entre le registre SQL et le registre hors base (LOT 05.6.4). L'écriture du fichier n'est PAS atomique avec la transaction
 * SQL : une ligne SQL sans ligne de fichier est réparable (la ligne est ajoutée au fichier). Une ligne de fichier SANS ligne SQL (restauration de la base d'avant la purge,
 * ou altération) est SIGNALÉE, jamais « réparée » automatiquement : le rejeu des purges après restauration est l'objet du LOT 05.6.7.
 */
final class RegisterReconciliation
{
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $entityManager,
        private readonly PurgeRegisterFile $file,
    ) {
    }

    public function check(bool $repair): RegisterReconciliationReport
    {
        $report = new RegisterReconciliationReport();
        $sql = [];
        foreach ($this->connection->fetchFirstColumn('SELECT id FROM retention_purge_record ORDER BY id') as $binary) {
            $sql[Uuid::fromBinary((string) $binary)->toRfc4122()] = true;
        }
        $read = $this->file->read();
        $report->invalidLines = $read['invalidLines'];
        foreach (array_keys(array_diff_key($sql, $read['ids'])) as $id) {
            $report->missingInFile[] = $id;
        }
        foreach (array_keys(array_diff_key($read['ids'], $sql)) as $id) {
            $report->fileOnly[] = $id;
        }
        if ($repair) {
            foreach ($report->missingInFile as $id) {
                $record = $this->entityManager->find(PurgeRecord::class, Uuid::fromString($id));
                if (null !== $record) {
                    $this->file->append($record);
                    ++$report->repaired;
                }
            }
        }

        return $report;
    }
}
