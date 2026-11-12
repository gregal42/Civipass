<?php

declare(strict_types=1);

namespace App\Rights\Application;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/** Lecture des lignes de demandes de droits, TOUJOURS bornée à la commune de l'appelant (jamais l'identifiant de commune de l'URL). */
final class RightsRequestStore
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(string $requestId, Uuid $municipalityId, bool $lock = false): ?array
    {
        if (!Uuid::isValid($requestId)) {
            return null;
        }
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM rights_request WHERE id = :id AND municipality_id = :m'.($lock ? ' FOR UPDATE' : ''),
            ['id' => Uuid::fromString($requestId)->toBinary(), 'm' => $municipalityId->toBinary()],
        );

        return false === $row ? null : $row;
    }

    /** @return list<string> identifiants (RFC 4122) des dossiers communaux encore liés à la demande */
    public function dossierIds(Uuid $requestId): array
    {
        return array_map(
            static fn (mixed $v): string => Uuid::fromBinary((string) $v)->toRfc4122(),
            $this->connection->fetchFirstColumn('SELECT request_municipality_id FROM rights_request_dossier WHERE rights_request_id = :r ORDER BY id', ['r' => $requestId->toBinary()]),
        );
    }

    public static function utc(\DateTimeImmutable $at): \DateTimeImmutable
    {
        return $at->setTimezone(new \DateTimeZone('UTC'));
    }
}
