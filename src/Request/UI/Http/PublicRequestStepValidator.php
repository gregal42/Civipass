<?php

declare(strict_types=1);

namespace App\Request\UI\Http;

use App\Identity\Domain\EmailAddress;
use App\Municipality\Domain\PhoneNumberNormalizer;
use App\Request\Application\RequestSubmissionService;
use App\Request\Domain\LicensePlate;
use App\Request\Domain\PermitRequest;
use App\Request\Domain\RequestReferentialRepository;
use App\Shared\Domain\DomainRuleViolation;

/**
 * Validation du formulaire public, étape par étape, avec les messages destinés au citoyen. Réutilise les objets du domaine
 * (adresse, téléphone, plaque, référentiels actifs) : les règles de fond ne sont pas redéfinies ici. C'est une aide à la
 * saisie : le dépôt (incrément suivant) revalide l'ensemble côté serveur.
 */
final readonly class PublicRequestStepValidator
{
    public function __construct(
        private RequestReferentialRepository $referentials,
        private PhoneNumberNormalizer $phones,
    ) {
    }

    /**
     * @param array<string, string|list<string>> $f                 valeurs du brouillon
     * @param list<string>                       $proposableZoneIds identifiants des zones actuellement proposées
     * @param bool                               $agent             saisie par un agent : seul le message d'attestation change
     *
     * @return array<string, string> messages d'erreur par champ ou bloc (vide si l'étape est valide)
     */
    public function validate(PublicRequestStep $step, array $f, array $proposableZoneIds, bool $agent = false): array
    {
        return match ($step) {
            PublicRequestStep::Identity => $this->identity($f),
            PublicRequestStep::Situation => $this->situation($f),
            PublicRequestStep::Zones => $this->zones($f, $proposableZoneIds),
            PublicRequestStep::Travel => $this->travel($f),
            PublicRequestStep::Documents => [],
            PublicRequestStep::Duration => [], // validée par le formulaire (durée, fenêtre, zones) : elle exige l'événement et la commune de l'agent
            PublicRequestStep::Recap => '1' === ($f['privacy'] ?? '') ? [] : ['privacy' => $agent
                ? 'Cochez la case pour attester que vous avez informé le bénéficiaire.'
                : 'Cochez la case pour confirmer que vous avez pris connaissance de la politique de confidentialité.'],
        };
    }

    /**
     * @param array<string, string|list<string>> $f
     *
     * @return array<string, string>
     */
    private function identity(array $f): array
    {
        $errors = [];
        foreach (['last' => 'votre nom', 'first' => 'votre prénom'] as $field => $label) {
            $value = trim((string) ($f[$field] ?? ''));
            if ('' === $value) {
                $errors[$field] = sprintf('Indiquez %s.', $label);
            } elseif (mb_strlen($value) > PermitRequest::NAME_MAX_LENGTH) {
                $errors[$field] = sprintf('%d caractères au maximum.', PermitRequest::NAME_MAX_LENGTH);
            }
        }
        if (mb_strlen(trim((string) ($f['address'] ?? ''))) > PermitRequest::ADDRESS_MAX_LENGTH) {
            $errors['address'] = sprintf('%d caractères au maximum.', PermitRequest::ADDRESS_MAX_LENGTH);
        }
        $email = trim((string) ($f['email'] ?? ''));
        $phone = trim((string) ($f['phone'] ?? ''));
        if ('' === $email && '' === $phone) {
            $errors['contact'] = 'Indiquez au moins une adresse e-mail ou un numéro de téléphone.';
        }
        if ('' !== $email) {
            try {
                EmailAddress::fromString($email);
            } catch (DomainRuleViolation) {
                $errors['email'] = 'Cette adresse e-mail ne semble pas valide.';
            }
        }
        if ('' !== $phone) {
            try {
                $this->phones->normalize($phone);
            } catch (DomainRuleViolation) {
                $errors['phone'] = 'Ce numéro de téléphone ne semble pas valide.';
            }
        }

        return $errors;
    }

    /**
     * @param array<string, string|list<string>> $f
     *
     * @return array<string, string>
     */
    private function situation(array $f): array
    {
        $errors = [];
        $category = $this->referentials->findCategoryByCode((string) ($f['category'] ?? ''));
        if (null === $category || !$category->isActive()) {
            $errors['category'] = 'Choisissez une catégorie.';
        }
        $motif = $this->referentials->findMotifByCode((string) ($f['motif'] ?? ''));
        $detail = trim((string) ($f['detail'] ?? ''));
        if (null === $motif || !$motif->isActive()) {
            $errors['motif'] = 'Choisissez un motif.';
        } elseif ($motif->needsDetail() && '' === $detail) {
            $errors['detail'] = 'Précisez le motif : la précision est obligatoire pour « Autre ».';
        }
        if (mb_strlen($detail) > PermitRequest::DETAIL_MAX_LENGTH) {
            $errors['detail'] = sprintf('%d caractères au maximum.', PermitRequest::DETAIL_MAX_LENGTH);
        }

        return $errors;
    }

    /**
     * @param array<string, string|list<string>> $f
     * @param list<string>                       $proposable
     *
     * @return array<string, string>
     */
    private function zones(array $f, array $proposable): array
    {
        $chosen = \is_array($f['zones'] ?? null) ? $f['zones'] : [];
        if ([] === $chosen) {
            return ['zones' => 'Choisissez au moins une zone.'];
        }
        if (\count($chosen) > RequestSubmissionService::MAX_ZONES) {
            return ['zones' => sprintf('Une demande comporte au plus %d zones.', RequestSubmissionService::MAX_ZONES)];
        }
        if ([] !== array_diff($chosen, $proposable)) {
            return ['zones' => 'Une zone choisie n\'est plus proposée : vérifiez votre sélection.'];
        }

        return [];
    }

    /**
     * @param array<string, string|list<string>> $f
     *
     * @return array<string, string>
     */
    private function travel(array $f): array
    {
        $mode = (string) ($f['mode'] ?? '');
        if (!\in_array($mode, ['foot_bike', 'vehicle'], true)) {
            return ['mode' => 'Choisissez comment vous allez vous déplacer.'];
        }
        if ('foot_bike' === $mode) {
            return [];
        }
        $plates = array_values(array_filter(array_map('trim', \is_array($f['plates'] ?? null) ? $f['plates'] : []), static fn (string $p): bool => '' !== $p));
        if ([] === $plates) {
            return ['plates' => 'Indiquez au moins une immatriculation.'];
        }
        if (\count($plates) > PermitRequest::MAX_VEHICLES) {
            return ['plates' => sprintf('%d immatriculations au maximum.', PermitRequest::MAX_VEHICLES)];
        }
        $seen = [];
        foreach ($plates as $raw) {
            try {
                $plate = LicensePlate::fromInput($raw);
            } catch (DomainRuleViolation) {
                return ['plates' => sprintf('L\'immatriculation « %s » n\'est pas valide : seules les plaques françaises (AB-123-CD ou 1234 AB 56) sont acceptées.', $raw)];
            }
            if (isset($seen[$plate->value])) {
                return ['plates' => sprintf('L\'immatriculation « %s » est saisie plusieurs fois.', $raw)];
            }
            $seen[$plate->value] = true;
        }

        return [];
    }
}
