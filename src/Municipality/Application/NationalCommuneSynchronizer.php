<?php

declare(strict_types=1);

namespace App\Municipality\Application;

use App\Municipality\Domain\InseeCode;
use App\Municipality\Domain\NationalCommune;
use App\Municipality\Domain\NationalCommuneRecord;
use App\Municipality\Domain\NationalCommuneRepository;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Application\TransactionRunner;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditActor;
use App\Shared\Domain\AuditSubjectType;
use App\Shared\Domain\DomainRuleViolation;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Synchronise le référentiel national depuis la source officielle : import COMPLET, idempotent (clé : code INSEE), en une seule
 * transaction (tout ou rien). N'écrit QUE dans le référentiel : aucune commune inscrite (`Municipality`), aucun droit commercial,
 * aucune participation n'est jamais touché.
 *
 * - Nouvelle commune : créée. Commune connue : mise à jour seulement si un champ a changé (jamais de doublon).
 * - Commune absente de la source : DÉSACTIVÉE (plus proposée à l'invitation), jamais supprimée ; réactivée si elle revient.
 * - Garde-fou : une source qui renvoie moins de communes que le minimum attendu est refusée en bloc (réponse partielle ou vide),
 *   sauf import partiel explicite (`allowPartial`), qui n'écrit que ce qu'il reçoit et ne désactive jamais rien.
 * - Les lignes invalides de la source sont ignorées et comptées ; elles ne bloquent pas l'import.
 */
final readonly class NationalCommuneSynchronizer
{
    public const CHUNK_SIZE = 500;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TransactionRunner $transaction,
        private NationalCommuneRepository $communes,
        private AuditTrail $audit,
        private ClockInterface $clock,
        #[Autowire('%env(int:NATIONAL_COMMUNES_MIN_COUNT)%')]
        private int $minimumExpected = 30000,
    ) {
    }

    /**
     * @param list<NationalCommuneRecord> $records
     *
     * @throws DomainRuleViolation source manifestement incomplète
     */
    public function synchronize(array $records, string $source, bool $dryRun = false, bool $allowPartial = false): SynchronizationReport
    {
        if (!$allowPartial && \count($records) < $this->minimumExpected) {
            throw new DomainRuleViolation(sprintf('La source n\'a renvoyé que %d communes (minimum attendu : %d) : import refusé, rien n\'est modifié.', \count($records), $this->minimumExpected));
        }

        $report = null;
        try {
            $report = $this->transaction->run(function () use ($records, $source, $dryRun, $allowPartial): SynchronizationReport {
                $report = $this->apply($records, $source, $dryRun, $allowPartial);
                if ($dryRun) {
                    throw new DryRunCompleted($report); // annule la transaction : un essai n'écrit rien
                }

                return $report;
            });
        } catch (DryRunCompleted $e) {
            return $e->report;
        }

        return $report;
    }

    /**
     * @param list<NationalCommuneRecord> $records
     */
    private function apply(array $records, string $source, bool $dryRun, bool $allowPartial): SynchronizationReport
    {
        $start = $this->clock->now();
        $syncId = Uuid::v7()->toRfc4122();
        $created = $updated = $unchanged = $ignored = 0;
        $valid = [];
        foreach ($records as $record) {
            try {
                $code = InseeCode::fromString($record->inseeCode)->value;
            } catch (DomainRuleViolation) {
                ++$ignored;
                continue;
            }
            if ('' === trim($record->name) || mb_strlen(trim($record->name)) > NationalCommune::NAME_MAX_LENGTH || isset($valid[$code])) {
                ++$ignored;
                continue;
            }
            $valid[$code] = $record;
        }

        foreach (array_chunk($valid, self::CHUNK_SIZE, preserve_keys: true) as $chunk) {
            $codes = array_map('strval', array_keys($chunk));
            $existing = $this->communes->findByInseeCodes($codes);
            foreach ($chunk as $code => $record) {
                $commune = $existing[(string) $code] ?? null;
                if (null === $commune) {
                    $this->communes->add(new NationalCommune($record, $source, $start, $syncId));
                    ++$created;
                } elseif ($commune->refresh($record, $source, $start)) {
                    ++$updated;
                } else {
                    ++$unchanged;
                }
            }
            $this->entityManager->flush();
            $this->entityManager->clear();
            $this->communes->markSeen($codes, $syncId, $start);
        }

        $deactivated = $allowPartial ? 0 : $this->communes->deactivateNotSeenIn($syncId, $start);
        if (!$dryRun) {
            $this->audit->record(new AuditEvent(
                $this->clock->now(),
                AuditAction::NationalCommunesSynchronized,
                AuditSubjectType::NationalCommuneReferential,
                Uuid::v5(Uuid::fromString(Uuid::NAMESPACE_URL), 'civipass:national-commune-referential'),
                null,
                AuditActor::system(),
                null,
                ['source' => $source, 'received' => \count($records), 'created' => $created, 'updated' => $updated, 'unchanged' => $unchanged, 'deactivated' => $deactivated, 'ignored' => $ignored, 'partial' => $allowPartial],
            ));
        }

        return new SynchronizationReport(\count($records), $created, $updated, $unchanged, $deactivated, $ignored, $dryRun, $allowPartial);
    }
}
