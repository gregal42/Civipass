<?php

declare(strict_types=1);

namespace App\Request\Application;

use App\Request\Application\Result\InspectedDocument;
use App\Request\Domain\PermitRequestAttachment;
use App\Shared\Domain\DomainRuleViolation;

/**
 * Contrôle serveur d'un justificatif (S4) sur son CONTENU réel : jamais le type MIME ni la taille déclarés par le navigateur.
 *
 * Extensions autorisées : pdf, jpg, jpeg, png, doc (DOCX exclu). L'extension doit être cohérente avec le type détecté par
 * libmagic (`finfo`) ET avec la structure du contenu : PDF complet (en-tête et fin de fichier), image décodable et complète (jpg, png : marqueur de fin),
 * conteneur OLE (doc). Un fichier vide, tronqué, d'un autre format renommé ou illisible est refusé. Aucun moteur documentaire
 * générique : seulement ces vérifications raisonnables.
 */
final readonly class UploadedDocumentInspector
{
    private const OLE_SIGNATURE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
    private const OLE_MIME_TYPES = ['application/msword', 'application/vnd.ms-office', 'application/CDFV2', 'application/x-ole-storage'];

    /**
     * @throws DomainRuleViolation fichier refusé (message destiné au citoyen)
     */
    public function inspect(string $content, string $clientName): InspectedDocument
    {
        $name = self::safeName($clientName);
        $extension = strtolower((string) pathinfo($name, \PATHINFO_EXTENSION));
        if ('docx' === $extension) {
            throw new DomainRuleViolation(sprintf('« %s » : le format DOCX n\'est pas accepté (PDF, JPG, PNG ou DOC).', $name));
        }
        if (!\in_array($extension, ['pdf', 'jpg', 'jpeg', 'png', 'doc'], true)) {
            throw new DomainRuleViolation(sprintf('« %s » : format non accepté (PDF, JPG, PNG ou DOC).', $name));
        }
        $size = \strlen($content);
        if (0 === $size) {
            throw new DomainRuleViolation(sprintf('« %s » : le fichier est vide.', $name));
        }
        if ($size > PermitRequestAttachment::MAX_SIZE_BYTES) {
            throw new DomainRuleViolation(sprintf('« %s » : le fichier dépasse 10 Mo.', $name));
        }

        $detected = (new \finfo(\FILEINFO_MIME_TYPE))->buffer($content);
        $mime = match ($extension) {
            'pdf' => 'application/pdf' === $detected && $this->isCompletePdf($content) ? 'application/pdf' : null,
            'jpg', 'jpeg' => 'image/jpeg' === $detected && $this->isImage($content, \IMAGETYPE_JPEG) && str_contains(substr($content, -64), "\xFF\xD9") ? 'image/jpeg' : null,
            'png' => 'image/png' === $detected && $this->isImage($content, \IMAGETYPE_PNG) && str_contains(substr($content, -16), 'IEND') ? 'image/png' : null,
            'doc' => \in_array($detected, self::OLE_MIME_TYPES, true) && str_starts_with($content, self::OLE_SIGNATURE) ? 'application/msword' : null,
        };
        if (null === $mime) {
            throw new DomainRuleViolation(sprintf('« %s » : le contenu ne correspond pas au format .%s ou le fichier est corrompu.', $name, $extension));
        }

        return new InspectedDocument($name, $mime, $size);
    }

    /** Nom d'affichage : sans répertoire, sans caractère de contrôle ni de séparation, 255 caractères au plus. */
    private static function safeName(string $clientName): string
    {
        $name = basename(str_replace('\\', '/', $clientName));
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', $name));
        if ('' === $name || '' === pathinfo($name, \PATHINFO_FILENAME)) {
            throw new DomainRuleViolation('Le nom du fichier est invalide.');
        }
        if (mb_strlen($name) > 255) {
            $extension = (string) pathinfo($name, \PATHINFO_EXTENSION);
            $name = mb_substr($name, 0, 250 - mb_strlen($extension)).'.'.$extension;
        }

        return $name;
    }

    private function isCompletePdf(string $content): bool
    {
        return str_starts_with($content, '%PDF-') && str_contains(substr($content, -2048), '%%EOF');
    }

    private function isImage(string $content, int $type): bool
    {
        $info = @getimagesizefromstring($content);

        return \is_array($info) && $info[0] > 0 && $info[1] > 0 && $type === $info[2];
    }
}
