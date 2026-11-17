<?php

declare(strict_types=1);

namespace App\Control\Application;

use App\Control\Application\Dataset\ControlDataset;
use App\Control\Application\Dataset\DatasetBuilder;
use App\Control\Application\Dataset\DatasetCodec;
use App\Control\Domain\ControlDevice;
use App\Control\Domain\ControlDeviceRepository;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Application\TransactionRunner;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditActor;
use App\Shared\Domain\AuditSubjectType;
use Psr\Clock\ClockInterface;

/**
 * Synchronisation d'un appareil de contrôle (LOT 09.3, DM-131, DT-056) : AUCUN jeu de données n'est construit ni transmis à un appareil qui n'est pas approuvé, non
 * désactivé et non expiré. Trois modes de réponse, toujours accompagnés de l'enveloppe complète et du condensat racine : `full` (instantané complet), `delta` (seuls les
 * compartiments qui diffèrent de ceux de l'appareil) et `unchanged` (condensat identique : seul l'instant de synchronisation avance). L'appareil VÉRIFIE le condensat racine
 * après application et redemande l'instantané complet au moindre écart. Aucun état par appareil n'est conservé sur le serveur, hors l'instant et le condensat de la dernière
 * synchronisation (assistance). Un refus d'un appareil désactivé ou expiré est audité au plus une fois par heure.
 */
final readonly class DeviceSyncService
{
    /** Au-delà de ce nombre de compartiments différents, un instantané complet est plus simple et à peine plus gros. */
    private const DELTA_MAX_BUCKETS = 128;

    public function __construct(
        private DatasetBuilder $builder,
        private ControlDeviceRepository $devices,
        private ControlThrottle $throttle,
        private TransactionRunner $transaction,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<string>|null $clientBuckets condensats de compartiments de l'appareil (256), nul pour un instantané complet
     *
     * @return array<string, mixed> réponse JSON
     *
     * @throws DeviceNotOperational|ControlStorageUnavailable
     */
    public function sync(ControlDevice $device, ?array $clientBuckets): array
    {
        $now = $this->clock->now();
        if (!$device->isOperational($now)) {
            $this->auditRefusal($device, $now);
            throw DeviceNotOperational::of($device, $now);
        }
        $dataset = $this->builder->build($device, $now);
        $root = $dataset->root();
        $bucketDigests = $dataset->bucketDigests();
        $envelope = $dataset->envelope + ['root' => $root, 'bk' => $bucketDigests];
        $this->devices->recordSync($device->id(), $now, $root);

        return $envelope + $this->body($dataset, $clientBuckets, $bucketDigests);
    }

    /**
     * @param list<string>|null $clientBuckets
     * @param list<string>      $serverBuckets
     *
     * @return array<string, mixed>
     */
    private function body(ControlDataset $dataset, ?array $clientBuckets, array $serverBuckets): array
    {
        if (null === $clientBuckets) {
            return ['mode' => 'full', 'lines' => $dataset->sortedLines()];
        }
        $changed = [];
        foreach ($serverBuckets as $index => $digest) {
            if ($digest !== $clientBuckets[$index]) {
                $changed[] = $index;
            }
        }
        if ([] === $changed) {
            return ['mode' => 'unchanged'];
        }
        if (\count($changed) > self::DELTA_MAX_BUCKETS) {
            return ['mode' => 'full', 'lines' => $dataset->sortedLines()];
        }
        $chg = [];
        foreach ($changed as $index) {
            $chg[(string) $index] = DatasetCodec::bucketLines($dataset->linesByKey, $index);
        }

        return ['mode' => 'delta', 'chg' => $chg];
    }

    private function auditRefusal(ControlDevice $device, \DateTimeImmutable $now): void
    {
        if (\App\Control\Domain\DeviceStatus::Pending === $device->status() && $device->expiresAt() > $now) {
            return; // attente normale d'approbation : pas un refus à tracer
        }
        if (!$this->throttle->mayAuditRefusal($device->id()->toRfc4122())) {
            return;
        }
        $event = new AuditEvent($now, AuditAction::ControlSyncRefused, AuditSubjectType::ControlDevice, $device->id(), $device->activation()->municipality()->id(), AuditActor::public(), null, [
            'state' => DeviceNotOperational::of($device, $now)->state,
        ]);
        $this->transaction->run(fn () => $this->audit->record($event));
    }
}
