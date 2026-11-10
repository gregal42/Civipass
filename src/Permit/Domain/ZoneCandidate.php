<?php

declare(strict_types=1);

namespace App\Permit\Domain;

use Symfony\Component\Uid\Uuid;

/** Zone de l'événement visée par un ajout, une réintégration ou une correction, relue SOUS VERROU : libellé courant, appartient à la participation, proposable (DM-104). */
final readonly class ZoneCandidate
{
    public function __construct(public Uuid $zoneId, public string $currentLabel, public bool $ownParticipation, public bool $proposable)
    {
    }
}
