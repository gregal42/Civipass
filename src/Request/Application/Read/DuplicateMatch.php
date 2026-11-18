<?php

declare(strict_types=1);

namespace App\Request\Application\Read;

use App\Request\Domain\MunicipalRequestStatus;

/**
 * Autre demande « possiblement liée » (LOT 08.4, DM-138), visible pour la commune consultante UNIQUEMENT (elle y a une part non « invitée »). Informatif : jamais
 * un refus, une fusion ni une suppression. `criteria` : parmi `email`, `phone`, `name`, `plate`.
 */
final readonly class DuplicateMatch
{
    public const CRITERIA_LABELS = ['name' => 'même nom et prénom', 'email' => 'même adresse e-mail', 'phone' => 'même téléphone', 'plate' => 'même plaque'];

    /** @param list<string> $criteria */
    public function __construct(
        public string $requestId,
        public string $reference,
        public \DateTimeImmutable $submittedAt,
        public MunicipalRequestStatus $status,
        public array $criteria,
    ) {
    }

    /** @return list<string> libellés lisibles, dans l'ordre stable nom, e-mail, téléphone, plaque */
    public function labels(): array
    {
        $labels = [];
        foreach (self::CRITERIA_LABELS as $key => $label) {
            if (\in_array($key, $this->criteria, true)) {
                $labels[] = $label;
            }
        }

        return $labels;
    }
}
