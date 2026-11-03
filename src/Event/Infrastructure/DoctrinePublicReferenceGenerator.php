<?php

declare(strict_types=1);

namespace App\Event\Infrastructure;

use App\Event\Domain\EventReferenceCounter;
use App\Event\Domain\PublicReference;
use App\Event\Domain\PublicReferenceGenerator;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Incrémente atomiquement la ligne unique du compteur global : l'UPDATE prend le verrou de ligne jusqu'à la fin de la
 * transaction de création, donc deux créations concurrentes se sérialisent et ne partagent jamais un numéro ; un
 * rollback annule l'incrément (aucun trou). SQL direct plutôt que l'entité : pas d'état ORM périmé à rafraîchir.
 * Le flush de l'événement appartient au cas d'usage appelant (étape 5).
 */
#[AsAlias(PublicReferenceGenerator::class)]
final readonly class DoctrinePublicReferenceGenerator implements PublicReferenceGenerator
{
    public function __construct(private Connection $connection)
    {
    }

    public function next(\DateTimeImmutable $now): PublicReference
    {
        $connection = $this->connection;
        if (!$connection->isTransactionActive()) {
            throw new \LogicException('La référence publique se génère dans la transaction de création de l\'événement.');
        }

        $updated = $connection->executeStatement(
            'UPDATE event_reference_counter SET last_number = last_number + 1 WHERE id = :id',
            ['id' => EventReferenceCounter::SINGLE_ROW_ID],
        );
        if (1 !== $updated) {
            throw new \LogicException('Compteur de références non initialisé (ligne unique attendue, créée par la migration).');
        }
        $number = (int) $connection->fetchOne('SELECT last_number FROM event_reference_counter WHERE id = :id', ['id' => EventReferenceCounter::SINGLE_ROW_ID]);

        return PublicReference::fromCounter($number, $now);
    }
}
