<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use App\Shared\Application\FileStorageInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Stockage local V1, HORS du répertoire public (par défaut `var/storage`). Les fichiers n'ont ni extension ni nom d'origine
 * (clé aléatoire), sont créés en 0600 dans des répertoires 0700 et écrits de façon atomique (fichier partiel puis rename) :
 * rien d'exécutable ni de prévisible. Aucun S3 ni stockage distant à ce stade.
 */
#[AsAlias(FileStorageInterface::class)]
final readonly class LocalFileStorage implements FileStorageInterface
{
    public function __construct(#[Autowire(env: 'PRIVATE_STORAGE_DIR')] private string $root)
    {
    }

    public function putTemporary(string $localPath): string
    {
        if (!is_file($localPath) || is_link($localPath)) {
            throw new \RuntimeException('Fichier à stocker introuvable.');
        }
        $key = 'tmp/'.bin2hex(random_bytes(16));
        $target = $this->path($key);
        $this->ensureDirectory(\dirname($target));
        $partial = $target.'.part';
        if (!copy($localPath, $partial) || !chmod($partial, 0o600) || !rename($partial, $target)) {
            @unlink($partial);
            throw new \RuntimeException('Écriture du fichier impossible.');
        }

        return $key;
    }

    public function promote(string $temporaryKey): string
    {
        if (1 !== preg_match(self::TEMPORARY_KEY_PATTERN, $temporaryKey) || !is_file($this->path($temporaryKey))) {
            throw new \RuntimeException('Fichier temporaire introuvable.');
        }
        $key = 'files/'.bin2hex(random_bytes(16));
        $target = $this->path($key);
        $this->ensureDirectory(\dirname($target));
        if (!@link($this->path($temporaryKey), $target)) {
            // Repli (système de fichiers sans lien physique) : copie atomique.
            $partial = $target.'.part';
            if (!copy($this->path($temporaryKey), $partial) || !chmod($partial, 0o600) || !rename($partial, $target)) {
                @unlink($partial);
                throw new \RuntimeException('Rattachement du fichier impossible.');
            }
        }

        return $key;
    }

    public function readStream(string $key)
    {
        $path = $this->path($key);
        $stream = is_file($path) && !is_link($path) ? @fopen($path, 'rb') : false;
        if (false === $stream) {
            throw new \RuntimeException('Fichier introuvable.');
        }

        return $stream;
    }

    public function exists(string $key): bool
    {
        $path = $this->path($key);

        return is_file($path) && !is_link($path);
    }

    public function delete(string $key): void
    {
        $path = $this->path($key);
        if (is_file($path) || is_link($path)) {
            @unlink($path);
        }
    }

    public function deleteTemporaryOlderThan(\DateTimeImmutable $limit): int
    {
        $directory = $this->root.'/tmp';
        if (!is_dir($directory)) {
            return 0;
        }
        $deleted = 0;
        foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $file) {
            // Uniquement des fichiers ordinaires nommés comme nos clés (ou leurs fichiers partiels) : jamais un lien ni un répertoire.
            if ($file->isFile() && !$file->isLink() && 1 === preg_match('/^[a-f0-9]{32}(\.part)?$/', $file->getFilename()) && $file->getMTime() < $limit->getTimestamp()) {
                if (@unlink($file->getPathname())) {
                    ++$deleted;
                }
            }
        }

        return $deleted;
    }

    /** Chemin d'une clé, uniquement si elle a exactement la forme générée par ce stockage (aucune traversée possible). */
    private function path(string $key): string
    {
        if (1 !== preg_match(self::TEMPORARY_KEY_PATTERN, $key) && 1 !== preg_match(self::PERMANENT_KEY_PATTERN, $key)) {
            throw new \InvalidArgumentException('Clé de stockage invalide.');
        }

        return rtrim($this->root, '/').'/'.$key;
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Répertoire de stockage inaccessible.');
        }
    }
}
