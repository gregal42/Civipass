<?php

declare(strict_types=1);

namespace App\Municipality\Infrastructure;

use App\Municipality\Domain\PhoneNumber;
use App\Municipality\Domain\PhoneNumberNormalizer;
use App\Shared\Domain\DomainRuleViolation;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Accepte les formats français usuels (métropole et outre-mer), les formats internationaux
 * (+ ou 00) et E.164 ; produit toujours du E.164.
 */
#[AsAlias(PhoneNumberNormalizer::class)]
final readonly class LibPhoneNumberNormalizer implements PhoneNumberNormalizer
{
    /** Régions d'outre-mer dont les numéros nationaux s'écrivent avec un 0 initial (0262, 0590, 0594, 0596…). */
    private const OVERSEAS_REGIONS = ['RE', 'YT', 'GP', 'MQ', 'GF', 'PM', 'BL', 'MF'];

    public function normalize(string $raw): PhoneNumber
    {
        $raw = trim($raw);
        if ('' === $raw || 1 === preg_match('/[^\d\s.\-()+\/]/', $raw)) {
            throw new DomainRuleViolation('Numéro de téléphone invalide.');
        }

        $util = PhoneNumberUtil::getInstance();
        $international = str_starts_with($raw, '+') || str_starts_with($raw, '00');

        foreach ($international ? [null] : ['FR', ...self::OVERSEAS_REGIONS] as $region) {
            try {
                $number = $util->parse($international ? preg_replace('/^00/', '+', $raw) ?? $raw : $raw, $region);
            } catch (NumberParseException) {
                continue;
            }
            if ($util->isValidNumber($number)) {
                return PhoneNumber::fromE164($util->format($number, PhoneNumberFormat::E164));
            }
        }

        throw new DomainRuleViolation('Numéro de téléphone invalide.');
    }
}
