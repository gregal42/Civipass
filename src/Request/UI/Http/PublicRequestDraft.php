<?php

declare(strict_types=1);

namespace App\Request\UI\Http;

use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Brouillon du formulaire public, conservé dans la SESSION entre les étapes : jamais en base (aucune demande incomplète n'est
 * enregistrée). Ne contient que des chaînes, des listes de chaînes et les métadonnées de téléversement ; les décisions
 * (zones proposables, fichiers réels) sont toujours revérifiées côté serveur. Un brouillon par portée (`scope`) : la référence de l'événement pour le public ; pour un agent, utilisateur + commune d'origine +
 * événement, de sorte qu'aucun brouillon n'est partagé entre agents, communes, événements ni avec un citoyen.
 */
final class PublicRequestDraft
{
    /** Durée d'inactivité au-delà de laquelle le brouillon PUBLIC est effacé (LOT 10, O-2 : navigateur partagé, minimisation). */
    public const TTL_SECONDS = 1800;

    /** Clé (hors champs de saisie) qui porte l'instant de dernière activité sur le formulaire. */
    private const TOUCHED = '__touched';

    private const FIELDS = ['last', 'first', 'address', 'email', 'phone', 'category', 'motif', 'detail', 'mode', 'privacy', 'duration_mode', 'duration_date', 'duration_time', 'duration_occurrence'];

    public function __construct(
        private readonly SessionInterface $session,
        private readonly string $scope,
    ) {
    }

    /**
     * @return array{last: string, first: string, address: string, email: string, phone: string, category: string, motif: string, detail: string, mode: string, privacy: string, duration_mode: string, duration_date: string, duration_time: string, duration_occurrence: string, plates: list<string>, zones: list<string>}
     */
    public function values(): array
    {
        $data = $this->read();
        $values = ['plates' => $this->strings($data['plates'] ?? []), 'zones' => $this->strings($data['zones'] ?? [])];
        foreach (self::FIELDS as $field) {
            $value = $data[$field] ?? '';
            $values[$field] = \is_string($value) ? $value : '';
        }

        return $values;
    }

    /**
     * @param array<string, string|list<string>> $values
     */
    public function merge(array $values): void
    {
        $this->write(array_merge($this->read(), $values));
    }

    /**
     * @return list<array{key: string, name: string, mime: string, size: int}>
     */
    public function files(): array
    {
        $files = [];
        foreach ($this->read()['files'] ?? [] as $file) {
            if (\is_array($file) && \is_string($file['key'] ?? null) && \is_string($file['name'] ?? null) && \is_string($file['mime'] ?? null) && \is_int($file['size'] ?? null)) {
                $files[] = ['key' => $file['key'], 'name' => $file['name'], 'mime' => $file['mime'], 'size' => $file['size']];
            }
        }

        return $files;
    }

    public function addFile(string $key, string $name, string $mime, int $size): void
    {
        $data = $this->read();
        $data['files'] = [...$this->files(), ['key' => $key, 'name' => $name, 'mime' => $mime, 'size' => $size]];
        $this->write($data);
    }

    /** Retire un fichier du brouillon : renvoie vrai s'il en faisait partie (on ne supprime jamais une clé étrangère au brouillon). */
    public function removeFile(string $key): bool
    {
        $files = $this->files();
        $kept = array_values(array_filter($files, static fn (array $f): bool => $f['key'] !== $key));
        if (\count($kept) === \count($files)) {
            return false;
        }
        $data = $this->read();
        $data['files'] = $kept;
        $this->write($data);

        return true;
    }

    /** Clé d'idempotence de la tentative de dépôt : propre au brouillon, jamais fournie par le client (double clic = même clé). */
    public function attemptKey(): string
    {
        $data = $this->read();
        if (!\is_string($data['attempt'] ?? null) || '' === $data['attempt']) {
            $data['attempt'] = bin2hex(random_bytes(16));
            $this->write($data);
        }

        return $data['attempt'];
    }

    /**
     * Dépôt réussi : le brouillon (saisies, fichiers, clé de tentative) est effacé ; seule subsiste la confirmation, lisible par
     * cette session uniquement.
     *
     * @param array{reference: string, event: string, email: string, beneficiary?: string, request_id?: string} $confirmation
     */
    public function confirm(array $confirmation): void
    {
        $this->write(['confirmation' => $confirmation]);
    }

    /**
     * @return array{reference: string, event: string, email: string, beneficiary: string, request_id: string}|null
     */
    public function confirmation(): ?array
    {
        $c = $this->read()['confirmation'] ?? null;
        if (\is_array($c) && \is_string($c['reference'] ?? null) && \is_string($c['event'] ?? null) && \is_string($c['email'] ?? null)) {
            return ['reference' => $c['reference'], 'event' => $c['event'], 'email' => $c['email'], 'beneficiary' => \is_string($c['beneficiary'] ?? null) ? $c['beneficiary'] : '', 'request_id' => \is_string($c['request_id'] ?? null) ? $c['request_id'] : ''];
        }

        return null;
    }

    /**
     * Marque une activité sur le formulaire (chaque requête du parcours prolonge la durée de vie du brouillon).
     */
    public function touch(int $now): void
    {
        $data = $this->read();
        $data[self::TOUCHED] = $now;
        $this->write($data);
    }

    /**
     * Efface le brouillon s'il est resté inactif plus de TTL_SECONDS : saisies, confirmation et références de pièces sont retirées de la
     * session (pas seulement masquées). Un brouillon sans horodatage (ouvert avant cette règle) est conservé et horodaté.
     *
     * @return list<string>|null clés des pièces temporaires à supprimer du stockage, ou null si rien n'a expiré
     */
    public function expireIfStale(int $now): ?array
    {
        $data = $this->read();
        if ([] === $data) {
            return null;
        }
        $touched = $data[self::TOUCHED] ?? null;
        if (!\is_int($touched)) {
            $this->touch($now);

            return null;
        }
        if ($now - $touched <= self::TTL_SECONDS) {
            return null;
        }

        return $this->clear();
    }

    /**
     * Effacement volontaire ou par expiration : la clé de session du brouillon est SUPPRIMÉE (aucune donnée résiduelle).
     *
     * @return list<string> clés des pièces temporaires qui lui étaient rattachées
     */
    public function clear(): array
    {
        $keys = array_map(static fn (array $f): string => $f['key'], $this->files());
        $this->session->remove($this->sessionKey());

        return $keys;
    }

    /** Nouvelle demande après une confirmation : repart d'un brouillon vide. */
    public function reset(): void
    {
        $this->write([]);
    }

    /**
     * @return array<string, mixed>
     */
    private function read(): array
    {
        $data = $this->session->get($this->sessionKey(), []);

        return \is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function write(array $data): void
    {
        $this->session->set($this->sessionKey(), $data);
    }

    private function sessionKey(): string
    {
        return 'public_request.'.$this->scope;
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $value): array
    {
        return \is_array($value) ? array_values(array_filter($value, '\is_string')) : [];
    }
}
