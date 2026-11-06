<?php

declare(strict_types=1);

namespace App\Request\Application;

use App\Request\Application\Result\StoredTemporaryUpload;
use App\Request\Domain\PermitRequestAttachment;
use App\Shared\Application\FileStorageInterface;
use App\Shared\Domain\DomainRuleViolation;

/**
 * Réception d'un justificatif AVANT le dépôt : contrôle du contenu réel puis stockage en zone temporaire privée (purgée
 * après 24 h, voir `app:request-uploads:purge`). Reçoit un chemin local et le nom d'origine, jamais d'objet HTTP : le
 * futur contrôleur y passera le fichier téléversé. Le nom d'origine n'intervient jamais dans le chemin de stockage.
 */
final readonly class TemporaryUploadService
{
    public function __construct(
        private UploadedDocumentInspector $inspector,
        private FileStorageInterface $files,
    ) {
    }

    /**
     * @throws DomainRuleViolation fichier refusé
     */
    public function store(string $localPath, string $clientName): StoredTemporaryUpload
    {
        $content = is_file($localPath) && !is_link($localPath) ? @file_get_contents($localPath, false, null, 0, PermitRequestAttachment::MAX_SIZE_BYTES + 1) : false;
        if (false === $content) {
            throw new DomainRuleViolation('Le fichier n\'a pas pu être lu : renvoyez-le.');
        }
        $document = $this->inspector->inspect($content, $clientName);

        return new StoredTemporaryUpload($this->files->putTemporary($localPath), $document);
    }

    /** Retire un téléversement temporaire (idempotent). */
    public function discard(string $temporaryKey): void
    {
        if (1 === preg_match(FileStorageInterface::TEMPORARY_KEY_PATTERN, $temporaryKey)) {
            $this->files->delete($temporaryKey);
        }
    }
}
