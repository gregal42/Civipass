<?php

declare(strict_types=1);

namespace App\Event\Application\Read;

/** Type d'événement proposé par le filtre de la liste (actif ou non : un événement peut garder un type désactivé). */
final readonly class EventTypeOption
{
    public function __construct(public string $code, public string $label)
    {
    }
}
