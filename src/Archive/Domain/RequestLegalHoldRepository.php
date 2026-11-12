<?php

declare(strict_types=1);

namespace App\Archive\Domain;

use Symfony\Component\Uid\Uuid;

interface RequestLegalHoldRepository
{
    public function add(RequestLegalHold $hold): void;

    public function find(Uuid $id): ?RequestLegalHold;

    /** Le gel ACTIF d'un dossier communal, ou null. `$lock` : verrou d'écriture. */
    public function findActive(Uuid $dossierId, bool $lock = false): ?RequestLegalHold;

    /** @return list<RequestLegalHold> épisodes d'un dossier, plus récents d'abord */
    public function history(Uuid $dossierId): array;

    /**
     * @param list<Uuid> $dossierIds
     *
     * @return array<string, RequestLegalHold> gel actif par identifiant de dossier (ceux qui en ont un)
     */
    public function activeAmong(array $dossierIds): array;

    /** @return list<RequestLegalHold> gels actifs dont l'échéance de revue est atteinte, plus anciennes d'abord */
    public function overdue(\DateTimeImmutable $now, int $limit): array;
}
