<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Permit\Infrastructure\Security\PermitTokenSealer;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Relit les jetons de contrôle EN CLAIR pour les tests (SEC-020 : la base ne les contient plus ; on rouvre la copie scellée comme le fait l'application).
 */
final class PlainTokens
{
    /**
     * @param list<mixed> $params
     *
     * @return list<string> jetons en clair, dans l'ordre de `created_at, id`
     */
    public static function all(Connection $db, string $where = '1 = 1', array $params = []): array
    {
        $sealer = new PermitTokenSealer((string) ($_SERVER['APP_SECRET'] ?? $_ENV['APP_SECRET'] ?? ''), $_SERVER['PERMIT_TOKEN_KEYRING'] ?? null, $_SERVER['PERMIT_TOKEN_ACTIVE_KEY_ID'] ?? null);

        return array_map(
            static fn (array $r): string => $sealer->open((string) $r['token_encrypted'], Uuid::fromBinary((string) $r['id'])),
            $db->fetchAllAssociative('SELECT t.id, t.token_encrypted FROM permit_verification_token t JOIN permit_permit p ON p.id = t.permit_id WHERE '.$where.' ORDER BY t.created_at, t.id', $params),
        );
    }

    /** Rouvre une copie scellée (identifiant de ligne en binaire). */
    public static function open(string $sealed, string $binaryId): string
    {
        return (new PermitTokenSealer((string) ($_SERVER['APP_SECRET'] ?? $_ENV['APP_SECRET'] ?? ''), $_SERVER['PERMIT_TOKEN_KEYRING'] ?? null, $_SERVER['PERMIT_TOKEN_ACTIVE_KEY_ID'] ?? null))->open($sealed, Uuid::fromBinary($binaryId));
    }

    /** @param list<mixed> $params */
    public static function one(Connection $db, string $where = '1 = 1', array $params = []): string
    {
        return self::all($db, $where, $params)[0] ?? throw new \LogicException('Aucun jeton.');
    }
}
