<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use App\Shared\Application\TransactionRunner;
use App\Shared\Domain\DomainRuleViolation;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Alternative à EntityManager::wrapInTransaction(), qui ferme l'EntityManager à la moindre exception :
 * un simple refus métier (droit insuffisant, dernier administrateur…) ne doit pas le condamner.
 *
 * Garantie : après un rollback, l'EntityManager n'est conservé que s'il est PROUVÉ sain, c'est-à-dire si
 * l'opération n'a ni flushé (la base est annulée mais l'ORM croirait les lignes écrites) ni laissé de
 * modifications en attente (l'état mémoire contredirait la base). Dans tous les autres cas il est fermé.
 * L'imbrication n'est pas prise en charge (pas de savepoints) et est refusée explicitement.
 */
#[AsAlias(TransactionRunner::class)]
final class DoctrineTransactionRunner implements TransactionRunner
{
    private bool $running = false;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function run(callable $operation): mixed
    {
        $connection = $this->entityManager->getConnection();
        if ($this->running || $connection->isTransactionActive()) {
            throw new \LogicException('Transactions imbriquées non prises en charge : une opération transactionnelle ne peut pas en appeler une autre.');
        }

        $this->running = true;
        $flushes = new class () {
            public int $count = 0;

            public function postFlush(PostFlushEventArgs $args): void
            {
                ++$this->count;
            }
        };
        $eventManager = $this->entityManager->getEventManager();
        $eventManager->addEventListener(Events::postFlush, $flushes);

        try {
            $connection->beginTransaction();
            $result = $operation();
            $this->entityManager->flush();
            $connection->commit();

            return $result;
        } catch (DomainRuleViolation $violation) {
            $this->rollBack();
            if (($flushes->count > 0) || $this->hasPendingChanges()) {
                $this->entityManager->close();
            }

            throw $violation;
        } catch (\Throwable $failure) {
            $this->rollBack();
            $this->entityManager->close();

            throw $failure;
        } finally {
            $eventManager->removeEventListener(Events::postFlush, $flushes);
            $this->running = false;
        }
    }

    private function rollBack(): void
    {
        $connection = $this->entityManager->getConnection();
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
    }

    private function hasPendingChanges(): bool
    {
        $unitOfWork = $this->entityManager->getUnitOfWork();
        $unitOfWork->computeChangeSets();

        return [] !== $unitOfWork->getScheduledEntityInsertions()
            || [] !== $unitOfWork->getScheduledEntityUpdates()
            || [] !== $unitOfWork->getScheduledEntityDeletions()
            || [] !== $unitOfWork->getScheduledCollectionUpdates()
            || [] !== $unitOfWork->getScheduledCollectionDeletions();
    }
}
