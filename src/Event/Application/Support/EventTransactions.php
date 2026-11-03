<?php

declare(strict_types=1);

namespace App\Event\Application\Support;

use App\Shared\Domain\ConflictCause;
use App\Shared\Domain\OperationConflict;
use App\Shared\Application\TransactionRunner;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Transaction métier du module Event (DT-035, D3) : TransactionRunner + délai d'attente de verrou court + traduction
 * contrôlée des conflits concurrents.
 *
 * - Isolation inchangée (REPEATABLE READ) : les règles d'ordre de EventLocker en neutralisent le risque.
 * - Délai de verrou de la SESSION fixé à 5 secondes (au lieu de 50) le temps de l'opération, puis restauré :
 *   la configuration globale du serveur n'est jamais modifiée.
 * - Traduits en OperationConflict, et seulement eux : LockWaitTimeoutException, DeadlockException, et
 *   UniqueConstraintViolationException quand la contrainte violée figure dans la liste déclarée par l'appelant.
 *   Toute autre erreur (autre contrainte, autre SQL, bug) remonte inchangée.
 * - Aucune nouvelle tentative automatique : le runner ferme l'EntityManager après un échec technique.
 */
final readonly class EventTransactions
{
    public const DEFAULT_LOCK_WAIT_SECONDS = 5;

    public function __construct(
        private TransactionRunner $runner,
        private Connection $connection,
        private int $lockWaitSeconds = self::DEFAULT_LOCK_WAIT_SECONDS,
    ) {
        if ($lockWaitSeconds < 1 || $lockWaitSeconds > 60) {
            throw new \InvalidArgumentException('Délai de verrou invalide (1 à 60 secondes).');
        }
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     * @param list<string>  $translatedUniqueConstraints noms d'index d'unicité dont la violation concurrente est un conflit attendu
     *
     * @return T
     *
     * @throws OperationConflict
     */
    public function run(callable $operation, array $translatedUniqueConstraints = []): mixed
    {
        $previousTimeout = (int) $this->connection->fetchOne('SELECT @@SESSION.innodb_lock_wait_timeout');
        $this->connection->executeStatement(sprintf('SET SESSION innodb_lock_wait_timeout = %d', $this->lockWaitSeconds));

        try {
            return $this->runner->run($operation);
        } catch (LockWaitTimeoutException $e) {
            throw new OperationConflict(ConflictCause::LockWaitTimeout, $e);
        } catch (DeadlockException $e) {
            throw new OperationConflict(ConflictCause::Deadlock, $e);
        } catch (UniqueConstraintViolationException $e) {
            foreach ($translatedUniqueConstraints as $constraint) {
                if (str_contains($e->getMessage(), $constraint)) {
                    throw new OperationConflict(ConflictCause::UniqueViolation, $e);
                }
            }

            throw $e;
        } finally {
            try {
                $this->connection->executeStatement(sprintf('SET SESSION innodb_lock_wait_timeout = %d', $previousTimeout));
            } catch (\Throwable) {
                // Connexion perdue : le délai de session disparaît avec elle.
            }
        }
    }
}
