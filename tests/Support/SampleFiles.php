<?php

declare(strict_types=1);

namespace App\Tests\Support;

/** Contenus de fichiers minimaux mais RÉELS (reconnus par libmagic), pour les tests de justificatifs. */
final class SampleFiles
{
    public static function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
    }

    public static function png(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true);
    }

    public static function jpeg(): string
    {
        $image = imagecreatetruecolor(4, 4);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }

    /** Conteneur OLE2 (signature) : le format d'un fichier .doc. */
    public static function doc(): string
    {
        return "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\0", 504);
    }

    /** Archive ZIP : le format d'un fichier .docx (interdit). */
    public static function zip(): string
    {
        return "PK\x03\x04".str_repeat("\0", 60);
    }

    public static function temporaryFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'civipass-sample-');
        file_put_contents($path, $content);

        return $path;
    }
}
