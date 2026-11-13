<?php

declare(strict_types=1);

namespace App\Admin\Application;

use App\Municipality\Domain\NationalCommune;
use Doctrine\DBAL\Connection;

/**
 * Recherche, dans le référentiel national, de la commune à ouvrir (nom ou code INSEE). Seules les communes ACTIVES du référentiel
 * sont proposées ; pour chacune, on indique si elle existe déjà sur Civipass (inscrite par une invitation, par exemple). Aucune
 * donnée opérationnelle n'est lue.
 */
final readonly class OpenableCommuneSearch
{
    public const MIN_LENGTH = 2;
    public const MAX_RESULTS = 20;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return list<array{inseeCode: string, name: string, department: string, registered: bool}>
     */
    public function search(string $query): array
    {
        $term = trim(mb_substr($query, 0, 100));
        $normalized = NationalCommune::normalize($term);
        if (mb_strlen($term) < self::MIN_LENGTH || mb_strlen($normalized) < self::MIN_LENGTH) {
            return [];
        }
        $like = addcslashes($normalized, '\\%_').'%';
        $rows = $this->connection->fetchAllAssociative(
            'SELECT n.insee_code AS insee, n.name AS name, n.department_code AS dept, (m.id IS NOT NULL) AS registered'
            .' FROM municipality_national_commune n LEFT JOIN municipality m ON m.insee_code = n.insee_code'
            .' WHERE n.active = 1 AND (n.search_name LIKE :like OR n.insee_code = :insee)'
            .' ORDER BY n.population DESC, n.name ASC LIMIT '.self::MAX_RESULTS,
            ['like' => $like, 'insee' => $term],
        );

        return array_map(static fn (array $r): array => ['inseeCode' => (string) $r['insee'], 'name' => (string) $r['name'], 'department' => (string) $r['dept'], 'registered' => (bool) $r['registered']], $rows);
    }
}
