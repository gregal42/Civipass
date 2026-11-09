<?php

declare(strict_types=1);

namespace App\Permit\Application;

/** Rendu d'un QR code : un contenu texte, une image. Aucune donnée n'est conservée ; le contenu est fourni par l'appelant (URL de vérification). */
interface QrCodeRenderer
{
    /** SVG autonome, sans déclaration XML (intégrable dans une page). */
    public function svg(string $content): string;

    /** PNG binaire (documents imprimables : rendu fiable dans le moteur PDF). */
    public function png(string $content, int $size = 480): string;
}
