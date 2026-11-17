<?php

declare(strict_types=1);

namespace App\Tests\Unit\Control;

use App\Control\Application\Dataset\DatasetCodec;
use PHPUnit\Framework\TestCase;

/**
 * Vecteurs communs serveur/appareil (LOT 09.3/09.4) : le fichier `tests/Js/fixtures/dataset-vectors.json` est lu par les tests JavaScript ET régénéré ici. Si le format
 * canonique change d'un côté seulement, l'un des deux échoue. Régénération : `REGENERATE_VECTORS=1 bin/qa run DatasetCodecVectorsTest`.
 */
final class DatasetCodecVectorsTest extends TestCase
{
    private const PATH = __DIR__.'/../../Js/fixtures/dataset-vectors.json';

    /** @return array<string, mixed> */
    public static function build(): array
    {
        $tokens = ['abcdefghijklmnopqrstuvwxyz234567', 'zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz2', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa2', 'mnopqrstuvwxyz234567abcdefghijkl', 'q2q2q2q2q2q2q2q2q2q2q2q2q2q2q2q2'];
        $records = [
            ['t' => 'P', 'r' => 'LP-2026-0001', 'f' => 'Chloé', 'l' => 'DE LA FONTAINE', 'p' => ['AB-123-CD', 'EF-456-GH'], 'z' => [['l' => 'Centre-ville / « Vieux Port »', 's' => 'V', 'w' => [[1_790_000_000, 1_790_043_200], [1_790_100_000, 1_790_143_200]]], ['l' => 'Quartier nord', 's' => 'S', 'w' => []]]],
            ['t' => 'R'],
            ['t' => 'X'],
            ['t' => 'P', 'r' => 'LP-2026-0002', 'f' => 'Jean-François', 'l' => 'O\'BRIEN', 'p' => [], 'z' => [['l' => 'Zone 1', 's' => 'E', 'w' => []]]],
            ['t' => 'P', 'r' => 'LP-2026-0003', 'f' => 'Élodie', 'l' => 'MARTIN', 'p' => ['IJ-789-KL'], 'z' => [['l' => 'Zone "guillemets" \\ barre', 's' => 'F', 'w' => []]]],
        ];
        $byKey = [];
        foreach ($tokens as $i => $token) {
            $key = DatasetCodec::tokenKey($token);
            $byKey[$key] = DatasetCodec::line(['k' => $key] + $records[$i]);
        }
        $sorted = DatasetCodec::sorted($byKey);

        return [
            'tokens' => $tokens,
            'keys' => array_map(static fn (string $t): string => DatasetCodec::tokenKey($t), $tokens),
            'lines' => $sorted,
            'root' => DatasetCodec::root($sorted),
            'buckets' => DatasetCodec::bucketDigests($byKey),
            'emptyRoot' => DatasetCodec::root([]),
        ];
    }

    public function testTheSharedVectorsAreCurrent(): void
    {
        $built = self::build();
        if ('1' === getenv('REGENERATE_VECTORS')) {
            file_put_contents(self::PATH, json_encode($built, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)."\n");
        }
        $stored = json_decode((string) file_get_contents(self::PATH), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame($built, $stored, 'Le format canonique a changé : régénérer les vecteurs et mettre à jour le code JavaScript.');
    }

    public function testTheEmptyDatasetAndTheBucketMathAreWhatTheSpecificationSays(): void
    {
        self::assertSame(hash('sha256', ''), DatasetCodec::root([]));
        self::assertSame(256, \count(DatasetCodec::bucketDigests([])));
        self::assertSame(substr(hash('sha256', ''), 0, 8), DatasetCodec::bucketDigests([])[0]);
        self::assertSame(171, DatasetCodec::bucketOf('ab'.str_repeat('0', 62)));
        self::assertSame(0, DatasetCodec::bucketOf(str_repeat('0', 64)));
        self::assertSame(255, DatasetCodec::bucketOf(str_repeat('f', 64)));
    }
}
