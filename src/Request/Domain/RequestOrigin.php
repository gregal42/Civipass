<?php

declare(strict_types=1);

namespace App\Request\Domain;

/** Origine d'un dossier (DM-099) : dépôt public pour soi-même, ou saisie par un agent pour un tiers. */
enum RequestOrigin: string
{
    case Public = 'PUBLIC';
    case Agent = 'AGENT';
}
