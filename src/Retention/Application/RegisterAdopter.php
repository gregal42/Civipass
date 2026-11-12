<?php

declare(strict_types=1);

namespace App\Retention\Application;

use App\Retention\Domain\PurgeRecord;
use App\Shared\Application\TransactionRunner;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Réinscription en base, AVEC son identifiant d'origine, d'une ligne du registre externe absente du SQL (LOT 05.6.7). Jamais une suppression, jamais une réécriture du fichier.
 * Une ligne de purge n'est adoptée que si les données visées sont effectivement ABSENTES (sinon l'état réel contredirait le registre : c'est un écart à rejouer ou un incident).
 */
final class RegisterAdopter
{
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $entityManager,
        private readonly TransactionRunner $transaction,
    ) {
    }

    /**
     * @param array<string, mixed> $line
     *
     * @return bool vrai si la ligne a été réinscrite ; faux si elle existait déjà ou si les données visées sont encore présentes
     */
    public function adopt(array $line): bool
    {
        if (!\is_string($line['id'] ?? null) || !Uuid::isValid($line['id']) || !$this->dataAbsent($line)) {
            return false;
        }
        if (false !== $this->connection->fetchOne('SELECT 1 FROM retention_purge_record WHERE id = :i', ['i' => Uuid::fromString($line['id'])->toBinary()])) {
            return false;
        }
        $record = PurgeRecord::fromLine($line);
        $this->transaction->run(function () use ($record): void {
            $this->entityManager->persist($record);
        });

        return true;
    }

    /** @param array<string, mixed> $line */
    private function dataAbsent(array $line): bool
    {
        $present = static fn (Connection $c, string $sql, mixed $id): bool => \is_string($id) && Uuid::isValid($id) && false !== $c->fetchOne($sql, ['i' => Uuid::fromString($id)->toBinary()]);

        return match ($line['kind'] ?? null) {
            'DOSSIER' => !$present($this->connection, 'SELECT 1 FROM request_permit_request_municipality WHERE id = :i', $line['dossier'] ?? null),
            'SHARED' => !$present($this->connection, 'SELECT 1 FROM request_permit_request WHERE id = :i', $line['request'] ?? null),
            'EVENT_DELETED' => !$present($this->connection, 'SELECT 1 FROM event_event WHERE id = :i', $line['event'] ?? null),
            'NOTICE' => true,
            default => false,
        };
    }
}
