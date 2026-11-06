<?php

declare(strict_types=1);

namespace App\Request\Application\Read;

/** Justificatif tel que présenté : jamais de clé de stockage ni de chemin, uniquement un nom assaini, un type et une taille. */
final readonly class RequestAttachmentView
{
    private const TYPES = [
        'application/pdf' => 'PDF',
        'image/jpeg' => 'Image JPEG',
        'image/png' => 'Image PNG',
        'application/msword' => 'Document Word',
    ];

    public function __construct(
        public string $id,
        public string $name,
        public string $typeLabel,
        public string $sizeLabel,
    ) {
    }

    public static function of(string $id, string $originalName, string $mimeType, int $sizeBytes): self
    {
        return new self($id, self::displayName($originalName), self::TYPES[$mimeType] ?? 'Fichier', self::sizeLabel($sizeBytes));
    }

    /** Nom saisi par le déposant : sans caractère de contrôle, séparateur de chemin, guillemet ni chevron ; borné. */
    public static function displayName(string $original): string
    {
        $name = preg_replace(['/[\p{Cc}\p{Cf}]+/u', '#[\\\\/"<>:*?|]+#', '/\s+/u'], ['', ' ', ' '], $original) ?? '';
        $name = trim($name, " .\t");
        $name = mb_substr($name, 0, 120);

        return '' === $name ? 'justificatif' : $name;
    }

    public static function sizeLabel(int $bytes): string
    {
        return $bytes >= 1024 * 1024
            ? number_format($bytes / (1024 * 1024), 1, ',', ' ').' Mo'
            : max(1, (int) round($bytes / 1024)).' Ko';
    }
}
