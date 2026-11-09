<?php

declare(strict_types=1);

namespace App\Permit\Application;

use App\Permit\Application\Read\BeneficiaryPass;

/** Génère le PDF A4 d'un laissez-passer À LA DEMANDE : le document n'est jamais stocké (DT-050). Photographie des droits à `$generatedAt`. */
interface PassPdfRenderer
{
    public function render(BeneficiaryPass $pass, string $qrPng, \DateTimeImmutable $generatedAt): string;
}
