<?php

declare(strict_types=1);

namespace App\Municipality\Infrastructure;

use App\Municipality\Application\NationalCommuneSource;
use App\Municipality\Application\NationalCommuneSourceUnavailable;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Source officielle : API Géo (https://geo.api.gouv.fr/communes), qui diffuse le code officiel géographique de l'INSEE. UN appel
 * renvoie les quelque 35 000 communes (métropole, départements et collectivités d'outre-mer, Corse comprise). L'API ne sait pas
 * chercher par préfixe de code INSEE : c'est la base locale qui assure la recherche.
 *
 * Appel natif (flux PHP) : aucune dépendance ajoutée. Délai court, certificat TLS vérifié, aucune donnée personnelle émise.
 */
#[AsAlias(NationalCommuneSource::class)]
final readonly class GeoApiGouvSource implements NationalCommuneSource
{
    private const FIELDS = 'nom,code,codeDepartement,codeRegion,codesPostaux,population';

    public function __construct(
        #[Autowire('%env(GEO_API_URL)%')]
        private string $url,
        private int $timeoutSeconds = 60,
    ) {
    }

    public function name(): string
    {
        return 'geo.api.gouv.fr';
    }

    public function fetchAll(): array
    {
        $context = stream_context_create([
            'http' => ['method' => 'GET', 'timeout' => $this->timeoutSeconds, 'ignore_errors' => true, 'header' => "Accept: application/json\r\nUser-Agent: Civipass/referentiel-communes\r\n"],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $url = rtrim($this->url, '?').'?fields='.self::FIELDS.'&format=json';
        $body = @file_get_contents($url, false, $context);
        if (false === $body || 1 !== preg_match('#^HTTP/\S+ 200#', (string) ($http_response_header[0] ?? ''))) {
            throw new NationalCommuneSourceUnavailable('La source officielle des communes est injoignable ou a refusé la demande.');
        }

        return JsonCommuneDecoder::decode($body);
    }
}
