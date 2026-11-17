<?php

declare(strict_types=1);

namespace App\Tests\Unit\Permit;

use App\Permit\Infrastructure\RateLimiter\RateLimiterStorageFailure;
use App\Permit\Infrastructure\RateLimiter\StrictCacheStorage;
use App\Permit\UI\Http\RateLimiterVerificationThrottle;
use App\Permit\UI\Http\ThrottleStorageUnavailable;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\RateLimiter\Storage\StorageInterface;

/**
 * Deux plafonds indépendants (consultations, jetons inconnus : DT-055, les échecs ne bloquent jamais l'accès d'un jeton valide), temps d'attente calculé, reprise après la fenêtre, journal des seuils sans donnée, panne du stockage visible.
 */
final class RateLimiterVerificationThrottleTest extends TestCase
{
    private function factory(string $id, int $limit, string $interval, StorageInterface $storage): RateLimiterFactory
    {
        return new RateLimiterFactory(['id' => $id, 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => $interval], $storage);
    }

    private function throttle(int $access = 3, int $failures = 2, string $accessInterval = '10 minutes', string $failureInterval = '15 minutes'): RateLimiterVerificationThrottle
    {
        return new RateLimiterVerificationThrottle(
            $this->factory('verification_access', $access, $accessInterval, new InMemoryStorage()),
            $this->factory('verification_failure', $failures, $failureInterval, new InMemoryStorage()),
        );
    }

    public function testAccessIsRefusedOnceTheLimitIsReachedWithACalculatedWait(): void
    {
        $throttle = $this->throttle(access: 3);

        $results = [];
        for ($i = 0; $i < 4; ++$i) {
            $results[] = $throttle->check('v4:203.0.113.7');
        }

        self::assertSame([null, null, null], \array_slice($results, 0, 3));
        $wait = (int) $results[3];
        self::assertGreaterThanOrEqual(1, $wait, 'consultation refusée : attente calculée');
        self::assertLessThanOrEqual(601, $wait, 'jamais plus que la fenêtre de 10 minutes (arrondie à la seconde supérieure)');
    }

    public function testEachClientHasItsOwnCounter(): void
    {
        $throttle = $this->throttle(access: 1);

        self::assertNull($throttle->check('v4:203.0.113.7'));
        self::assertNotNull($throttle->check('v4:203.0.113.7'));
        self::assertNull($throttle->check('v4:203.0.113.8'), 'un autre client n\'est pas touché');
    }

    public function testUnknownTokensAreRefusedOnceTheirOwnAllowanceIsUsedUpButNeverTheAccessCheck(): void
    {
        $throttle = $this->throttle(access: 100, failures: 2);

        $first = $throttle->recordFailure('c');
        $second = $throttle->recordFailure('c');
        $third = $throttle->recordFailure('c');

        self::assertNull($first, 'dans l\'allocation : 404');
        self::assertNull($second);
        self::assertNotNull($third, 'allocation épuisée : le jeton inconnu suivant est refusé (429)');
        self::assertGreaterThanOrEqual(1, $third);
        self::assertLessThanOrEqual(900, $third);
        self::assertNull($throttle->check('c'), 'DT-055 : les échecs ne bloquent JAMAIS l\'accès d\'un jeton valide de la même adresse');
        self::assertNull($throttle->recordFailure('autre'), 'les échecs d\'un client ne touchent pas les autres');
    }

    public function testTheTwoAllowancesAreIndependentInBothDirections(): void
    {
        $throttle = $this->throttle(access: 2, failures: 5);

        self::assertNull($throttle->recordFailure('c'));
        self::assertNull($throttle->recordFailure('c'));
        self::assertNull($throttle->check('c'), 'deux échecs sur cinq : la consultation est admise');
        self::assertNull($throttle->check('c'));
        self::assertNotNull($throttle->check('c'), 'le plafond de consultations s\'applique de son côté');
        self::assertNull($throttle->recordFailure('c'), 'et n\'a pas consommé l\'allocation d\'échecs');
    }

    public function testManyFailuresNeverRefuseAValidTokenOnlyTheAccessCapDoes(): void
    {
        $throttle = $this->throttle(access: 60, failures: 3);

        for ($i = 0; $i < 50; ++$i) {
            $throttle->recordFailure('nat');
        }

        for ($i = 0; $i < 60; ++$i) {
            self::assertNull($throttle->check('nat'), 'consultation valide '.($i + 1).' après 50 jetons inconnus');
        }
        self::assertNotNull($throttle->check('nat'), 'seul le plafond d\'accès, garde-fou global, peut refuser un jeton valide');
    }

    public function testThresholdsAreLoggedOnceWithoutAnyClientDataToken(): void
    {
        /** @var \ArrayObject<int, array{string, string, array<mixed>}> $records */
        $records = new \ArrayObject();
        $logger = new class ($records) extends \Psr\Log\AbstractLogger {
            /** @param \ArrayObject<int, array{string, string, array<mixed>}> $records */
            public function __construct(private readonly \ArrayObject $records)
            {
            }

            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
                $this->records->append([(string) $level, (string) $message, $context]);
            }
        };
        $throttle = new RateLimiterVerificationThrottle(
            $this->factory('verification_access', 2, '10 minutes', new InMemoryStorage()),
            $this->factory('verification_failure', 2, '15 minutes', new InMemoryStorage()),
            $logger,
        );

        $throttle->check('v4:203.0.113.7');
        self::assertCount(0, $records, 'rien tant qu\'aucun plafond n\'est atteint');
        $throttle->check('v4:203.0.113.7');
        $throttle->check('v4:203.0.113.7');
        $throttle->recordFailure('v4:203.0.113.7');
        $throttle->recordFailure('v4:203.0.113.7');
        $throttle->recordFailure('v4:203.0.113.7');
        $throttle->recordFailure('v4:203.0.113.7');

        self::assertCount(2, $records, 'une ligne par plafond atteint, pas une par requête refusée');
        foreach ($records->getArrayCopy() as [$level, $message, $context]) {
            self::assertSame('error', $level);
            self::assertSame([], $context, 'aucun contexte : ni adresse, ni clé, ni jeton');
            self::assertStringNotContainsString('203.0.113.7', $message);
        }
        self::assertStringContainsString('consultations', $records[0][1]);
        self::assertStringContainsString('jetons inconnus', $records[1][1]);
        self::assertStringContainsString('jetons valides ne sont pas touchés', $records[1][1]);
    }

    public function testAccessIsGivenBackAfterTheWindow(): void
    {
        $throttle = $this->throttle(access: 1, accessInterval: '1 second');

        self::assertNull($throttle->check('c'));
        self::assertNotNull($throttle->check('c'));
        $wait = (int) $throttle->check('c');
        self::assertGreaterThanOrEqual(1, $wait);
        self::assertLessThanOrEqual(2, $wait, 'attente calculée sur la fenêtre d\'une seconde, arrondie');
        usleep(2_200_000);

        self::assertNull($throttle->check('c'), 'la fenêtre est passée : nouvelle consultation admise');
    }

    private function failingPool(bool $failSave, bool $failRead = false): ArrayAdapter
    {
        return new class ($failSave, $failRead) extends ArrayAdapter {
            public function __construct(private readonly bool $failSave, private readonly bool $failRead)
            {
                parent::__construct();
            }

            public function save(CacheItemInterface $item): bool
            {
                return $this->failSave ? false : parent::save($item);
            }

            public function getItem(mixed $key): \Symfony\Component\Cache\CacheItem
            {
                if ($this->failRead) {
                    throw new \RuntimeException('lecture impossible');
                }

                return parent::getItem($key);
            }
        };
    }

    public function testAFailedWriteIsReportedInsteadOfSilentlyDroppingTheLimit(): void
    {
        $storage = new StrictCacheStorage($this->failingPool(failSave: true));
        $throttle = new RateLimiterVerificationThrottle(
            $this->factory('verification_access', 3, '10 minutes', $storage),
            $this->factory('verification_failure', 2, '15 minutes', $storage),
        );

        $this->expectException(ThrottleStorageUnavailable::class);
        $throttle->check('c');
    }

    public function testAFailedReadAndAFailedFailureWriteAreReportedToo(): void
    {
        $read = new StrictCacheStorage($this->failingPool(failSave: false, failRead: true));
        $throttle = new RateLimiterVerificationThrottle($this->factory('a', 3, '10 minutes', $read), $this->factory('f', 2, '15 minutes', $read));
        try {
            $throttle->check('c');
            self::fail('lecture impossible : exception attendue');
        } catch (ThrottleStorageUnavailable $e) {
            self::assertInstanceOf(\RuntimeException::class, $e->getPrevious());
        }

        $write = new StrictCacheStorage($this->failingPool(failSave: true));
        $throttle = new RateLimiterVerificationThrottle($this->factory('a', 3, '10 minutes', new InMemoryStorage()), $this->factory('f', 2, '15 minutes', $write));
        $this->expectException(ThrottleStorageUnavailable::class);
        $throttle->recordFailure('c');
    }

    public function testTheStandardStorageWouldHaveFailedSilentlyWhichIsWhyTheStrictOneExists(): void
    {
        $pool = $this->failingPool(failSave: true);
        $standard = new \Symfony\Component\RateLimiter\Storage\CacheStorage($pool);
        $limiter = $this->factory('verification_access', 1, '10 minutes', $standard)->create('c');

        self::assertTrue($limiter->consume()->isAccepted());
        self::assertTrue($limiter->consume()->isAccepted(), 'écriture perdue sans signal : plus aucune limitation (comportement du stockage standard)');
        $this->expectException(RateLimiterStorageFailure::class);
        (new StrictCacheStorage($pool))->save(new \Symfony\Component\RateLimiter\Policy\SlidingWindow('x', 60));
    }

    public function testTheStrictStorageStoresFetchesAndDeletes(): void
    {
        $storage = new StrictCacheStorage(new ArrayAdapter());
        $window = new \Symfony\Component\RateLimiter\Policy\SlidingWindow('verification_access-c', 600);

        $storage->save($window);
        self::assertInstanceOf(\Symfony\Component\RateLimiter\Policy\SlidingWindow::class, $storage->fetch('verification_access-c'));
        $storage->delete('verification_access-c');
        self::assertNull($storage->fetch('verification_access-c'));
    }

    public function testTheExceptionMessageCarriesNoClientKeyNorToken(): void
    {
        $storage = new StrictCacheStorage($this->failingPool(failSave: true));
        $throttle = new RateLimiterVerificationThrottle($this->factory('a', 3, '10 minutes', $storage), $this->factory('f', 2, '15 minutes', $storage));
        try {
            $throttle->check('v4:203.0.113.7');
            self::fail('exception attendue');
        } catch (ThrottleStorageUnavailable $e) {
            self::assertStringNotContainsString('203.0.113.7', $e->getMessage());
            self::assertSame('Compteurs de limitation indisponibles.', $e->getMessage());
        }
    }
}
