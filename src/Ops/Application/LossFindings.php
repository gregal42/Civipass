<?php

declare(strict_types=1);

namespace App\Ops\Application;

use App\Retention\Domain\RestoreItem;
use Psr\Clock\ClockInterface;

/**
 * Constats de PERTE possible consignés (LOT 05.6.7) : un constat de perte (repère d'audit en régression, comptes manquants, événement ou commune cités par le registre mais absents)
 * peut DISPARAÎTRE du contrôle après un rejeu (qui écrit de nouvelles lignes d'audit). Il est donc consigné à sa première constatation et ne s'efface qu'à la remise en service
 * avec une reconnaissance écrite : la maintenance ne peut pas être levée « sans avoir vu » la perte.
 */
final class LossFindings
{
    private const CODES = [RestoreItem::POSSIBLE_DATA_LOSS, RestoreItem::EVENT_MISSING, RestoreItem::MUNICIPALITY_MISSING];

    public function __construct(private readonly OpsSettings $settings, private readonly ClockInterface $clock)
    {
    }

    /** @param list<RestoreItem> $items */
    public function record(array $items): void
    {
        $known = $this->all();
        $keys = array_map(static fn (RestoreItem $i): string => $i->code.'|'.$i->detail.'|'.$i->eventReference.'|'.$i->municipalityId, $known);
        $added = false;
        foreach ($items as $item) {
            if (!\in_array($item->code, self::CODES, true) || \in_array($item->code.'|'.$item->detail.'|'.$item->eventReference.'|'.$item->municipalityId, $keys, true)) {
                continue;
            }
            $known[] = $item;
            $added = true;
        }
        if (!$added) {
            return;
        }
        $directory = $this->settings->dir();
        if (!is_dir($directory) && !@mkdir($directory, 0o750, true) && !is_dir($directory)) {
            throw new \RuntimeException('Répertoire d\'exploitation inaccessible.');
        }
        file_put_contents($this->path(), json_encode(['at' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'), 'items' => array_map(static fn (RestoreItem $i): array => $i->toArray(), $known)], \JSON_THROW_ON_ERROR));
    }

    /** @return list<RestoreItem> */
    public function all(): array
    {
        if (!is_file($this->path())) {
            return [];
        }
        $data = json_decode((string) file_get_contents($this->path()), true);
        $items = [];
        foreach (\is_array($data) && \is_array($data['items'] ?? null) ? $data['items'] : [] as $row) {
            if (\is_array($row) && \in_array($row['code'] ?? null, self::CODES, true)) {
                $items[] = new RestoreItem((string) $row['code'], RestoreItem::SEVERITY_BLOCKING, RestoreItem::ACKNOWLEDGE_REQUIRED, eventReference: (string) ($row['eventReference'] ?? ''), municipalityId: (string) ($row['municipalityId'] ?? ''), detail: (string) ($row['detail'] ?? ''));
            }
        }

        return $items;
    }

    public function clear(): void
    {
        @unlink($this->path());
    }

    private function path(): string
    {
        return $this->settings->dir().'/loss-findings.json';
    }
}
