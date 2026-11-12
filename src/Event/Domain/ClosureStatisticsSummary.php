<?php

declare(strict_types=1);

namespace App\Event\Domain;

use App\Shared\Domain\DomainRuleViolation;

/**
 * Instantané PUBLIÉ des statistiques de clôture (version 2, LOT 05.6.4, L5-37) : il remplace les compteurs exacts (version 1) quand le DERNIER dossier de l'événement est
 * purgé. Il ne contient QUE des valeurs déjà seuillées (secret statistique appliqué avec le seuil du moment) : plus aucun effectif exact. Mêmes vues et mêmes
 * permissions que le bilan calculé à la lecture.
 *
 *   {"v": 2, "threshold": int, "global": {...}, "municipalities": {"<uuid>": {...}}}
 */
final readonly class ClosureStatisticsSummary
{
    public const VERSION = 2;

    /** @param array<string, PublishedClosureStatistics> $municipalities vue « propre à la commune », par identifiant de commune */
    public function __construct(
        public int $threshold,
        public PublishedClosureStatistics $global,
        public array $municipalities,
    ) {
    }

    public static function fromStatistics(ClosureStatistics $statistics, int $threshold): self
    {
        $threshold = ClosureStatisticsPublication::threshold($threshold);
        $own = [];
        foreach ($statistics->municipalities as $row) {
            $own[$row->municipalityId] = ClosureStatisticsPublication::forMunicipality($statistics, $row->municipalityId, $threshold);
        }

        return new self($threshold, ClosureStatisticsPublication::forOrganizer($statistics, $threshold, true), $own);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'v' => self::VERSION,
            'threshold' => $this->threshold,
            'global' => self::publishedToArray($this->global),
            'municipalities' => array_map(static fn (PublishedClosureStatistics $p): array => self::publishedToArray($p), $this->municipalities),
        ];
    }

    /**
     * @param array<mixed> $data
     *
     * @throws DomainRuleViolation
     */
    public static function fromArray(array $data): self
    {
        if (self::VERSION !== ($data['v'] ?? null) || !\is_int($data['threshold'] ?? null) || !\is_array($data['global'] ?? null) || !\is_array($data['municipalities'] ?? null)) {
            throw new DomainRuleViolation('Instantané de statistiques invalide.');
        }
        $own = [];
        foreach ($data['municipalities'] as $id => $published) {
            if (!\is_string($id) || !\is_array($published)) {
                throw new DomainRuleViolation('Instantané de statistiques invalide.');
            }
            $own[$id] = self::publishedFromArray($published, PublishedClosureStatistics::SCOPE_COMMUNE, $data['threshold']);
        }

        return new self($data['threshold'], self::publishedFromArray($data['global'], PublishedClosureStatistics::SCOPE_GLOBAL, $data['threshold']), $own);
    }

    /** @return array<string, mixed> */
    private static function publishedToArray(PublishedClosureStatistics $p): array
    {
        return ['dossiers' => $p->dossiers, 'byStatus' => $p->byStatus, 'communes' => $p->communes, 'masked' => $p->masked];
    }

    /** @param array<mixed> $a */
    private static function publishedFromArray(array $a, string $scope, int $threshold): PublishedClosureStatistics
    {
        $dossiers = $a['dossiers'] ?? null;
        $byStatus = $a['byStatus'] ?? null;
        $communes = $a['communes'] ?? null;
        $masked = $a['masked'] ?? [];
        if ((null !== $dossiers && !\is_int($dossiers)) || (null !== $byStatus && !\is_array($byStatus)) || (null !== $communes && !\is_array($communes)) || !\is_array($masked)) {
            throw new DomainRuleViolation('Instantané de statistiques invalide.');
        }

        return new PublishedClosureStatistics($scope, $threshold, $dossiers, $byStatus, $communes, array_values(array_map('strval', $masked)));
    }
}
