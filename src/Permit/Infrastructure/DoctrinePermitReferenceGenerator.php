<?php

declare(strict_types=1);

namespace App\Permit\Infrastructure;

use App\Event\Domain\Time\EventTimezone;
use App\Permit\Domain\PermitReference;
use App\Permit\Domain\PermitReferenceGenerator;
use App\Request\Domain\RequestReference;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Compteur annuel des références de laissez-passer, sur le modèle de DoctrineRequestReferenceGenerator : incrément SQL atomique, verrou de
 * la ligne de l'année jusqu'à la fin de la transaction, aucun trou en cas de rollback. `lock()` crée la ligne si besoin ET la verrouille en une
 * seule instruction (pas de montée de verrou partagé vers exclusif).
 */
#[AsAlias(PermitReferenceGenerator::class)]
final readonly class DoctrinePermitReferenceGenerator implements PermitReferenceGenerator
{
    public function __construct(private Connection $connection)
    {
    }

    public function lock(\DateTimeImmutable $now, EventTimezone $eventTimezone): void
    {
        $this->assertTransaction();
        $this->connection->executeStatement(
            'INSERT INTO permit_reference_counter (year, last_number) VALUES (:year, 0) ON DUPLICATE KEY UPDATE last_number = last_number',
            ['year' => RequestReference::yearOf($now, $eventTimezone)],
        );
    }

    public function next(\DateTimeImmutable $now, EventTimezone $eventTimezone): PermitReference
    {
        $this->assertTransaction();
        $year = RequestReference::yearOf($now, $eventTimezone);
        $this->connection->executeStatement(
            'INSERT INTO permit_reference_counter (year, last_number) VALUES (:year, LAST_INSERT_ID(1)) '
            .'ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1)',
            ['year' => $year],
        );

        return PermitReference::of($year, (int) $this->connection->fetchOne('SELECT LAST_INSERT_ID()'));
    }

    private function assertTransaction(): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('La référence d\'un laissez-passer se génère dans la transaction de décision.');
        }
    }
}
