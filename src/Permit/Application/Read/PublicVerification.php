<?php

declare(strict_types=1);

namespace App\Permit\Application\Read;

use App\Permit\Domain\PublicVerdict;

/**
 * Laissez-passer tel que le voit toute personne qui scanne son QR (DM-115) : UNIQUEMENT ce qui sert au contrôle. Aucune adresse, e-mail,
 * téléphone, motif, justificatif, catégorie, mode de déplacement, jeton, lien bénéficiaire ni identifiant technique ; aucune entité Doctrine.
 * `activeZoneCount` / `zoneCount` : « N zones actives sur M », M hors zones retirées. Instants en UTC, présentation dans `timezone`.
 */
final readonly class PublicVerification
{
    /**
     * @param list<string>              $plates
     * @param list<PublicAuthorization> $authorizations
     */
    public function __construct(
        public string $reference,
        public string $eventName,
        public string $timezone,
        public string $lastName,
        public string $firstName,
        public array $plates,
        public PublicVerdict $verdict,
        public int $activeZoneCount,
        public int $zoneCount,
        public array $authorizations,
        public \DateTimeImmutable $checkedAt,
    ) {
    }
}
