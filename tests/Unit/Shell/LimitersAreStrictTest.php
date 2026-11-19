<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shell;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * SEC-014 : TOUT limiteur déclaré utilise le stockage strict (une écriture impossible fait échouer bruyamment, jamais en silence). Un nouveau limiteur
 * sans `storage_service` strict fait échouer ce test.
 */
final class LimitersAreStrictTest extends TestCase
{
    public function testEveryRateLimiterUsesTheStrictStorage(): void
    {
        /** @var array{framework: array{rate_limiter: array<string, array<string, mixed>>}} $config */
        $config = Yaml::parseFile(\dirname(__DIR__, 3).'/config/packages/framework.yaml');
        $limiters = $config['framework']['rate_limiter'];

        self::assertGreaterThan(20, \count($limiters));
        foreach ($limiters as $name => $definition) {
            self::assertSame('App\Permit\Infrastructure\RateLimiter\StrictCacheStorage', $definition['storage_service'] ?? null, 'limiteur « '.$name.' » : stockage non strict (SEC-014)');
        }
    }
}
