<?php

declare(strict_types=1);

namespace App\Permit\Application;

use App\Event\Application\Support\EventLocker;
use App\Event\Application\Support\EventTransactions;
use App\Permit\Application\Result\BackfillReport;
use App\Permit\Domain\PermitRepository;
use App\Shared\Domain\OperationNotAllowed;
use Symfony\Component\Uid\Uuid;

/**
 * Rattrapage idempotent des approbations antérieures au module Permit (LOT 05.1, DM-112) : crée les laissez-passer et autorisations
 * manquants avec le MÊME service que la création normale (`PermitIssuer`), approbation la plus ancienne d'abord (elle porte la première
 * décision du laissez-passer), chaque approbation dans sa propre transaction et sous les verrous habituels (événement → participation →
 * ligne du dossier → compteur). Aucune décision n'est modifiée, aucune notification n'est enregistrée ni envoyée (`notify: false`). Une seconde exécution ne crée rien.
 * Le garde « développement seulement » est porté par la commande, pas par ce service.
 */
final readonly class PermitBackfill
{
    public function __construct(
        private PermitRepository $permits,
        private EventTransactions $transactions,
        private EventLocker $locker,
        private PermitIssuer $issuer,
    ) {
    }

    public function run(bool $dryRun): BackfillReport
    {
        $missing = $this->permits->findApprovalsWithoutAuthorization();
        $requests = [];
        foreach ($missing as $row) {
            if (null === $this->permits->findByRequest($row['requestId'])) {
                $requests[$row['requestId']->toRfc4122()] = true;
            }
        }
        if ($dryRun) {
            return new BackfillReport(true, \count($missing), \count($requests), 0, 0, []);
        }

        $created = 0;
        $added = 0;
        $errors = [];
        foreach ($missing as $row) {
            try {
                $isNew = $this->transactions->run(function () use ($row): bool {
                    $event = $this->locker->sharedEvent($row['eventId']);
                    $this->locker->participation($row['participationId'], $event);
                    $decision = $this->permits->findDecision($row['decisionId']) ?? throw new OperationNotAllowed('Décision introuvable.');
                    $isNew = null === $this->permits->findByRequest($decision->requestMunicipality()->request()->id());
                    $this->issuer->issue($decision, notify: false);

                    return $isNew;
                });
                $isNew ? ++$created : ++$added;
            } catch (\Throwable $e) {
                // L'EntityManager est fermé après un échec technique : on s'arrête, la prochaine exécution reprend.
                $errors[] = sprintf('Approbation %s : %s', Uuid::fromBinary($row['decisionId']->toBinary())->toRfc4122(), $e::class);
                break;
            }
        }

        return new BackfillReport(false, \count($missing), \count($requests), $created, $added, $errors);
    }
}
