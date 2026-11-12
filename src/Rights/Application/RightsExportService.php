<?php

declare(strict_types=1);

namespace App\Rights\Application;

use App\Archive\Application\ArchiveAccessPolicy;
use App\Identity\Domain\User;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Application\FileStorageInterface;
use App\Shared\Application\TransactionRunner;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditActor;
use App\Shared\Domain\AuditSubjectType;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Export d'accès (LOT 05.6.6, arbitrage D3) : généré À LA DEMANDE dans un fichier temporaire que l'appelant supprime après l'envoi (jamais conservé), en 3 téléchargements
 * au plus et pendant 30 jours au plus. L'habilitation (administrateur de la commune) est RECONTRÔLÉE à chaque téléchargement ; le compteur est pris par une mise à jour
 * conditionnelle (aucun dépassement possible en concurrence) ; chaque téléchargement est journalisé AVANT l'envoi, sinon il est refusé. Possible pendant un événement actif.
 */
final class RightsExportService
{
    public function __construct(
        private readonly ArchiveAccessPolicy $policy,
        private readonly RightsRequestStore $store,
        private readonly RightsDossierExporter $exporter,
        private readonly Connection $connection,
        private readonly FileStorageInterface $storage,
        private readonly TransactionRunner $transaction,
        private readonly AuditTrail $audit,
        private readonly ClockInterface $clock,
        private readonly RightsSettings $settings,
    ) {
    }

    /**
     * @return array{path: string, filename: string} fichier temporaire à SUPPRIMER après l'envoi
     *
     * @throws OperationNotAllowed refus uniforme
     * @throws DomainRuleViolation fenêtre expirée, limite atteinte, demande non accordée
     */
    public function download(User $actor, string $municipalityId, string $requestId): array
    {
        $municipality = $this->policy->administrator($actor, $municipalityId, write: true)->municipality()->id();
        $row = $this->store->find($requestId, $municipality) ?? throw new OperationNotAllowed('Ressource inaccessible.');
        $id = Uuid::fromBinary((string) $row['id']);
        if ('ACCESS' !== $row['kind']) {
            throw new OperationNotAllowed('Ressource inaccessible.');
        }
        $dossierIds = $this->store->dossierIds($id);
        $now = RightsRequestStore::utc($this->clock->now());
        $claimed = $this->connection->executeStatement(
            'UPDATE rights_request SET export_downloads = export_downloads + 1 WHERE id = :id AND municipality_id = :m AND kind = \'ACCESS\' AND status = \'DECIDED\' AND decision = \'GRANTED\''
            .' AND identity_verified_at IS NOT NULL AND export_available_until IS NOT NULL AND export_available_until >= :now AND export_downloads < :max',
            ['id' => $id->toBinary(), 'm' => $municipality->toBinary(), 'now' => $now->format('Y-m-d H:i:s'), 'max' => $this->settings->exportMaxDownloads()],
        );
        if (1 !== $claimed) {
            throw new DomainRuleViolation($this->why($row, $now));
        }
        $path = null;
        try {
            if ([] === $dossierIds) {
                throw new DomainRuleViolation('Aucun dossier n’est plus rattaché à cette demande (purgé) : rien à exporter.');
            }
            $path = $this->build($municipality, $dossierIds, (string) $row['public_reference']);
            $count = (int) $this->connection->fetchOne('SELECT export_downloads FROM rights_request WHERE id = :id', ['id' => $id->toBinary()]);
            $this->transaction->run(function () use ($actor, $id, $municipality, $count, $dossierIds): void {
                $this->audit->record(new AuditEvent($this->clock->now(), AuditAction::RightsExportDownloaded, AuditSubjectType::RightsRequest, $id, $municipality, AuditActor::user($actor->id()), null, ['download' => $count, 'dossiers' => \count($dossierIds)]));
            });
        } catch (\Throwable $e) {
            // Téléchargement non abouti (génération ou journal) : le compteur est rendu et aucun fichier ne subsiste.
            $this->connection->executeStatement('UPDATE rights_request SET export_downloads = export_downloads - 1 WHERE id = :id AND export_downloads > 0', ['id' => $id->toBinary()]);
            if (null !== $path) {
                @unlink($path);
            }
            throw $e;
        }

        return ['path' => $path, 'filename' => 'export-'.strtolower((string) $row['public_reference']).'.zip'];
    }

    /** @param array<string, mixed> $row */
    private function why(array $row, \DateTimeImmutable $now): string
    {
        if ('DECIDED' !== $row['status'] || 'GRANTED' !== $row['decision']) {
            return 'Cette demande n’est pas accordée, ou elle est clôturée : aucun export n’est disponible.';
        }
        if (null === $row['export_available_until'] || new \DateTimeImmutable((string) $row['export_available_until'], new \DateTimeZone('UTC')) < $now) {
            return 'La fenêtre d’accès à l’export est expirée.';
        }

        return sprintf('La limite de %d téléchargements est atteinte.', $this->settings->exportMaxDownloads());
    }

    /** @param list<string> $dossierIds */
    private function build(Uuid $municipality, array $dossierIds, string $reference): string
    {
        $path = tempnam(sys_get_temp_dir(), 'civipass-export-');
        if (false === $path) {
            throw new \RuntimeException('Fichier temporaire indisponible.');
        }
        chmod($path, 0600);
        $zip = new \ZipArchive();
        if (true !== $zip->open($path, \ZipArchive::OVERWRITE)) {
            @unlink($path);
            throw new \RuntimeException('Archive non créée.');
        }
        try {
            foreach ($dossierIds as $dossierId) {
                $part = $this->exporter->find($dossierId);
                if (null === $part || !$part->municipality()->id()->equals($municipality)) {
                    continue; // dossier purgé entre-temps, ou jamais de cette commune : jamais exporté
                }
                $dir = preg_replace('/[^A-Za-z0-9_-]/', '_', $part->request()->reference()->value).'/';
                $zip->addFromString($dir.'dossier.json', json_encode($this->exporter->build($part), \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
                foreach ($part->request()->attachments() as $i => $attachment) {
                    $stream = $this->storage->readStream($attachment->storageKey());
                    $content = stream_get_contents($stream);
                    if (\is_resource($stream)) {
                        fclose($stream);
                    }
                    $zip->addFromString($dir.'justificatifs/'.($i + 1).'-'.preg_replace('/[^A-Za-z0-9._-]/', '_', basename($attachment->originalName())), false === $content ? '' : $content);
                }
            }
            $zip->addFromString('LISEZMOI.txt', 'Export des données de la demande '.$reference.". Données de VOTRE commune uniquement ; les autres communes n'y figurent que par un décompte neutre.\n");
            if (!$zip->close()) {
                throw new \RuntimeException('Archive non finalisée.');
            }
        } catch (\Throwable $e) {
            @unlink($path);
            throw $e;
        }

        return $path;
    }
}
