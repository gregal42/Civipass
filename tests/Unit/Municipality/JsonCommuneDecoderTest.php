<?php

declare(strict_types=1);

namespace App\Tests\Unit\Municipality;

use App\Municipality\Application\NationalCommuneSourceUnavailable;
use App\Municipality\Infrastructure\JsonCommuneDecoder;
use PHPUnit\Framework\TestCase;

final class JsonCommuneDecoderTest extends TestCase
{
    public function testTheApiGeoFormatIsDecoded(): void
    {
        $records = JsonCommuneDecoder::decode((string) json_encode([
            ['nom' => 'Afa', 'code' => '2A001', 'codeDepartement' => '2A', 'codeRegion' => '94', 'codesPostaux' => ['20167'], 'population' => 3350],
            ['nom' => 'Saumos', 'code' => '33503', 'codeDepartement' => '33'],
        ]));

        self::assertCount(2, $records);
        self::assertSame(['2A001', 'Afa', '2A', '94', ['20167'], 3350], [$records[0]->inseeCode, $records[0]->name, $records[0]->departmentCode, $records[0]->regionCode, $records[0]->postalCodes, $records[0]->population]);
        self::assertNull($records[1]->regionCode);
        self::assertSame([], $records[1]->postalCodes);
        self::assertNull($records[1]->population);
    }

    public function testMalformedEntriesAreSkippedAndTheRestIsKept(): void
    {
        $records = JsonCommuneDecoder::decode((string) json_encode([
            'pas un objet',
            ['nom' => 'Sans code', 'codeDepartement' => '33'],
            ['nom' => 'Code numérique', 'code' => 33503, 'codeDepartement' => '33'],
            ['nom' => 'Bonne', 'code' => '33503', 'codeDepartement' => '33', 'codesPostaux' => ['33680', 5]],
        ]));

        self::assertCount(1, $records);
        self::assertSame(['33680'], $records[0]->postalCodes);
    }

    public function testAnUnusableResponseIsReportedAsAnUnavailableSource(): void
    {
        foreach (['', 'pas du json', '{"nom":"x"}', '"texte"'] as $body) {
            try {
                JsonCommuneDecoder::decode($body);
                self::fail('source inexploitable attendue : '.$body);
            } catch (NationalCommuneSourceUnavailable) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
