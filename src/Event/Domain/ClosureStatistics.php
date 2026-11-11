<?php

declare(strict_types=1);

namespace App\Event\Domain;

use App\Shared\Domain\DomainRuleViolation;

/**
 * Compteurs INTERNES de clôture d'un événement (LOT 05.6.2, DM-120, P4) : calculés UNE fois, dans la transaction de clôture, jamais recalculés, jamais exposés
 * directement. Schéma JSON minimal et strictement validé (version 1) :
 *
 *   {"v": 1, "internal": {"requests": int, "municipalities": [{"id": uuid, "approved": int, "refused": int, "pending": int, "authorizations": int}]}}
 *
 * Aucune identité, plaque, texte libre, référence ou identifiant de demande, de dossier, de laissez-passer ou d'utilisateur : seuls des entiers et des
 * identifiants de COMMUNES. Les totaux ne sont pas stockés (une seule source : les lignes communales). Le nombre de demandes distinctes reste interne :
 * il est déductible du nombre de dossiers dans un événement à une commune et trompeur dans un événement multicommunal.
 */
final readonly class ClosureStatistics
{
    public const VERSION = 1;
    public const MAX_MUNICIPALITIES = 64;
    public const MAX_JSON_BYTES = 8192;

    /** @param list<MunicipalityCounters> $municipalities */
    public function __construct(
        public int $requests,
        public array $municipalities,
    ) {
        if ($requests < 0) {
            throw new DomainRuleViolation('Compteurs de clôture : un compteur ne peut pas être négatif.');
        }
        if (\count($municipalities) > self::MAX_MUNICIPALITIES) {
            throw new DomainRuleViolation('Compteurs de clôture : trop de communes.');
        }
        $ids = [];
        $total = 0;
        $max = 0;
        foreach ($municipalities as $row) {
            if (isset($ids[$row->municipalityId])) {
                throw new DomainRuleViolation('Compteurs de clôture : commune en double.');
            }
            $ids[$row->municipalityId] = true;
            $total += $row->dossiers();
            $max = max($max, $row->dossiers());
        }
        // Un dossier communal appartient à UNE demande : le nombre de demandes borne par le haut le plus gros effectif communal et par le bas la somme.
        if ($requests > $total || $requests < $max) {
            throw new DomainRuleViolation('Compteurs de clôture : nombre de demandes incohérent avec les dossiers communaux.');
        }
    }

    public function dossiers(): int
    {
        return array_sum(array_map(static fn (MunicipalityCounters $m): int => $m->dossiers(), $this->municipalities));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'v' => self::VERSION,
            'internal' => [
                'requests' => $this->requests,
                'municipalities' => array_map(static fn (MunicipalityCounters $m): array => [
                    'id' => $m->municipalityId,
                    'approved' => $m->approved,
                    'refused' => $m->refused,
                    'pending' => $m->pending,
                    'authorizations' => $m->authorizations,
                ], $this->municipalities),
            ],
        ];
    }

    /**
     * Relecture STRICTE : clés exactement celles du schéma, entiers uniquement (ni chaîne, ni booléen, ni flottant), aucune clé inconnue.
     *
     * @param array<mixed> $data
     *
     * @throws DomainRuleViolation
     */
    public static function fromArray(array $data): self
    {
        if (\strlen((string) json_encode($data)) > self::MAX_JSON_BYTES) {
            throw new DomainRuleViolation('Compteurs de clôture : document trop volumineux.');
        }
        self::exactKeys($data, ['v', 'internal']);
        if (self::VERSION !== $data['v']) {
            throw new DomainRuleViolation('Compteurs de clôture : version inconnue.');
        }
        $internal = $data['internal'];
        if (!\is_array($internal)) {
            throw new DomainRuleViolation('Compteurs de clôture : bloc interne invalide.');
        }
        self::exactKeys($internal, ['requests', 'municipalities']);
        if (!\is_int($internal['requests']) || !\is_array($internal['municipalities']) || !array_is_list($internal['municipalities'])) {
            throw new DomainRuleViolation('Compteurs de clôture : structure invalide.');
        }
        $rows = [];
        foreach ($internal['municipalities'] as $row) {
            if (!\is_array($row)) {
                throw new DomainRuleViolation('Compteurs de clôture : ligne communale invalide.');
            }
            self::exactKeys($row, ['id', 'approved', 'refused', 'pending', 'authorizations']);
            if (!\is_string($row['id']) || !\is_int($row['approved']) || !\is_int($row['refused']) || !\is_int($row['pending']) || !\is_int($row['authorizations'])) {
                throw new DomainRuleViolation('Compteurs de clôture : type de valeur invalide.');
            }
            $rows[] = new MunicipalityCounters($row['id'], $row['approved'], $row['refused'], $row['pending'], $row['authorizations']);
        }

        return new self($internal['requests'], $rows);
    }

    /**
     * @param array<mixed> $data
     * @param list<string> $keys
     */
    private static function exactKeys(array $data, array $keys): void
    {
        $actual = array_keys($data);
        sort($actual);
        $expected = $keys;
        sort($expected);
        if ($actual !== $expected) {
            throw new DomainRuleViolation('Compteurs de clôture : clés inattendues ou absentes.');
        }
    }
}
