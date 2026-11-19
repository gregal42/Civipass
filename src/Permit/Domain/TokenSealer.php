<?php

declare(strict_types=1);

namespace App\Permit\Domain;

use Symfony\Component\Uid\Uuid;

/**
 * Port de chiffrement du jeton de contrôle au repos (SEC-020). Le domaine ne connaît ni l'algorithme ni les clés : il demande de « sceller » le jeton
 * (lié à l'identifiant de sa ligne) et de le « rouvrir » pour réafficher ou réimprimer le QR (DM-113, DM-118).
 */
interface TokenSealer
{
    public function seal(#[\SensitiveParameter] string $plain, Uuid $tokenId): string;

    /** @throws \RuntimeException si la valeur scellée est illisible (altérée, autre ligne, clé absente du trousseau) */
    public function open(string $sealed, Uuid $tokenId): string;
}
