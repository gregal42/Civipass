<?php

declare(strict_types=1);

namespace App\Archive\Application;

/** Lien de consultation périmé (durée limitée) POUR SON PROPRIÉTAIRE : on lui propose de rouvrir le dossier (nouveau motif, nouvelle ligne de journal). */
final class ArchiveConsultationExpired extends \RuntimeException
{
    public function __construct(public readonly string $dossierId)
    {
        parent::__construct('Consultation expirée.');
    }
}
