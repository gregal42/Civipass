<?php

declare(strict_types=1);

namespace App\Notification\Application;

use App\Notification\Domain\ChangeCategory;
use App\Notification\Domain\NotificationOutbox;
use App\Notification\Domain\NotificationOutboxRepository;
use App\Notification\Domain\NotificationSecretRepository;
use App\Notification\Domain\OutboxStatus;
use App\Permit\Application\SystemAccessLinks;
use App\Shared\Application\TransactionRunner;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Purge des métadonnées de l'outbox (LOT 05.3.5, D13) : suppression PHYSIQUE des messages en état DÉFINITIF dont le traitement date de plus de
 * N jours (90 par défaut, durée PROVISOIRE soumise à la validation RGPD). Une transaction par message, sous le verrou de sa ligne (le même que
 * celui du distributeur, de la purge des secrets et de l'ordonnancement), états relus sous verrou :
 *
 *  - jamais un message en attente ou réservé, ni un message dont le secret chiffré existe encore (la purge des secrets passe d'abord) ;
 *  - jamais un lien bénéficiaire : cette purge ne touche ni `permit_access_token`, ni sa révocation ;
 *  - idempotence : elle ne repose pas sur la ligne d'outbox. Le rejeu d'une approbation s'arrête avant l'enregistrement (l'autorisation existe,
 *    `UNIQUE(permit_id, participation_id)`) et le rattrapage n'enregistre rien : une ligne purgée n'est jamais recréée (testé) ;
 *  - ordonnancement : un message de mise à disposition ENVOYÉ prouve qu'une extension ultérieure n'est pas « sans premier message remis » ; il est donc
 *    CONSERVÉ tant que son laissez-passer est utilisable, et purgé ensuite (durée effective plus longue que N jours pour ces seules lignes,
 *    identifiants uniquement : arbitrage à confirmer).
 */
final readonly class OutboxPurger
{
    public const DEFAULT_RETENTION_DAYS = 90;

    public function __construct(
        private TransactionRunner $transactions,
        private NotificationOutboxRepository $outbox,
        private NotificationSecretRepository $secrets,
        private SystemAccessLinks $links,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{purged: int, retained: int, skipped: int} messages supprimés, messages de mise à disposition conservés (laissez-passer encore utilisable), lignes écartées à la relecture
     */
    public function purge(int $retentionDays = self::DEFAULT_RETENTION_DAYS, int $limit = 500): array
    {
        $before = $this->clock->now()->sub(new \DateInterval('P'.max(1, $retentionDays).'D'));
        $report = ['purged' => 0, 'retained' => 0, 'skipped' => 0];
        foreach ([false, true] as $firstMessagesSent) {
            foreach ($this->outbox->findPurgeableBefore($before, $firstMessagesSent, $limit) as $id) {
                ++$report[$this->purgeOne($id, $before)];
            }
        }

        return $report;
    }

    /** @return 'purged'|'retained'|'skipped' */
    private function purgeOne(Uuid $id, \DateTimeImmutable $before): string
    {
        return $this->transactions->run(function () use ($id, $before): string {
            $row = $this->outbox->lock($id);
            if (null === $row || !$row->status()->isFinal() || null === $row->processedAt() || $row->processedAt() >= $before || null !== $this->secrets->findByOutbox($id)) {
                return 'skipped'; // état relu sous verrou : plus à purger, ou secret pas encore supprimé
            }
            if ($this->isEvidenceOfAFirstDelivery($row) && $this->links->isUsable($row->permitId(), $this->clock->now())) {
                return 'retained';
            }
            $this->outbox->remove($row);

            return 'purged';
        });
    }

    private function isEvidenceOfAFirstDelivery(NotificationOutbox $row): bool
    {
        return ChangeCategory::FirstIssuance === $row->category() && OutboxStatus::Sent === $row->status();
    }
}
