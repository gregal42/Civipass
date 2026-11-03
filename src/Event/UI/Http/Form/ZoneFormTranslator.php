<?php

declare(strict_types=1);

namespace App\Event\UI\Http\Form;

use App\Event\Application\Command\CreateZoneCommand;
use App\Event\Application\Command\UpdateZoneCommand;
use App\Event\Domain\EventZone;
use App\Event\Domain\ZoneType;

/**
 * Traduit les saisies du formulaire de zone en commandes immuables. Il ne contrôle que la FORME (libellé présent et borné,
 * type connu, confirmation explicite du périmètre d'une « Commune entière ») ; l'unicité de « Commune entière », la
 * modifiabilité et les droits restent dans le domaine et les services. Le type n'est jamais modifiable (DM-080) : la
 * modification ne transmet que le libellé et la description. Aucun champ « État » : la suspension est une action distincte.
 */
final readonly class ZoneFormTranslator
{
    /**
     * @return array{CreateZoneCommand|null, array<string, string>}
     */
    public function forCreate(ZoneFormData $data): array
    {
        $errors = [];
        $label = $this->label($data, $errors);
        $type = ZoneType::tryFrom($data->type);
        if (null === $type) {
            $errors['type'] = '' === $data->type ? 'Choisissez le type de la zone.' : 'Ce type de zone n’est pas proposé.';
        } elseif (ZoneType::WholeMunicipality === $type && !$data->wholeConfirmed) {
            $errors['whole_confirmed'] = 'Confirmez explicitement que cette zone couvre toute la commune.';
        }
        if ([] !== $errors || null === $type) {
            return [null, $errors];
        }

        return [new CreateZoneCommand($label, $type->value, $data->description, $data->wholeConfirmed), []];
    }

    /**
     * @return array{UpdateZoneCommand|null, array<string, string>}
     */
    public function forUpdate(ZoneFormData $data): array
    {
        $errors = [];
        $label = $this->label($data, $errors);

        return [[] === $errors ? new UpdateZoneCommand($label, $data->description) : null, $errors];
    }

    /**
     * @param array<string, string> $errors
     */
    private function label(ZoneFormData $data, array &$errors): string
    {
        $label = trim($data->label);
        if ('' === $label) {
            $errors['label'] = 'Indiquez le libellé de la zone.';
        } elseif (mb_strlen($label) > EventZone::LABEL_MAX_LENGTH) {
            $errors['label'] = sprintf('Le libellé ne peut pas dépasser %d caractères.', EventZone::LABEL_MAX_LENGTH);
        }

        return $label;
    }
}
