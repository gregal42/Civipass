<?php

declare(strict_types=1);

namespace App\Event\Domain;

use Symfony\Component\Uid\Uuid;

interface EventTypeRepository
{
    public function add(EventType $type): void;

    public function find(Uuid $id): ?EventType;

    public function findByCode(string $code): ?EventType;

    /**
     * Types actifs, dans l'ordre d'affichage.
     *
     * @return list<EventType>
     */
    public function findActive(): array;
}
