<?php

declare(strict_types=1);

namespace App\Request\Infrastructure;

use App\Event\Domain\Time\EventTimezone;
use App\Request\Domain\RequestReference;
use App\Request\Domain\RequestReferenceGenerator;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Incrémente atomiquement le compteur de l'année en UNE instruction : la ligne de l'année est créée au premier dépôt
 * (numéro 1) puis incrémentée ; l'instruction prend le verrou de ligne jusqu'à la fin de la transaction du dépôt, donc
 * deux dépôts concurrents se sérialisent et ne partagent jamais un numéro ; un rollback annule l'incrément (aucun trou).
 * `LAST_INSERT_ID(expr)` renvoie le numéro attribué sur la même connexion, sans second accès à la table.
 */
#[AsAlias(RequestReferenceGenerator::class)]
final readonly class DoctrineRequestReferenceGenerator implements RequestReferenceGenerator
{
    public function __construct(private Connection $connection)
    {
    }

    public function lock(\DateTimeImmutable $now, EventTimezone $eventTimezone): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Le compteur des références se verrouille dans la transaction du dépôt.');
        }
        $this->connection->executeStatement(
            'INSERT INTO request_reference_counter (year, last_number) VALUES (:year, 0) ON DUPLICATE KEY UPDATE last_number = last_number',
            ['year' => RequestReference::yearOf($now, $eventTimezone)],
        );
    }

    public function next(\DateTimeImmutable $now, EventTimezone $eventTimezone): RequestReference
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('La référence d\'une demande se génère dans la transaction du dépôt.');
        }
        $year = RequestReference::yearOf($now, $eventTimezone);
        $this->connection->executeStatement(
            'INSERT INTO request_reference_counter (year, last_number) VALUES (:year, LAST_INSERT_ID(1)) '
            .'ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1)',
            ['year' => $year],
        );
        $number = (int) $this->connection->fetchOne('SELECT LAST_INSERT_ID()');

        return RequestReference::of($year, $number);
    }
}
