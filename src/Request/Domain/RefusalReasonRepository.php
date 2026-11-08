<?php

declare(strict_types=1);

namespace App\Request\Domain;

interface RefusalReasonRepository
{
    public function add(RefusalReason $reason): void;

    public function findByCode(string $code): ?RefusalReason;

    /**
     * Entrées actives, dans l'ordre d'affichage (jamais les désactivées).
     *
     * @return list<RefusalReason>
     */
    public function active(): array;
}
