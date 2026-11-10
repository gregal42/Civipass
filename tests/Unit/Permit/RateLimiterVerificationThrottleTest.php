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
 * Deux plafonds indépendants (consultations, jetons inconnus), temps d'attente calculé, reprise après la fenêtre, panne du stockage visible.
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

    public function testUnknownTokensBlockTheClientOnlyOnceTheirOwnLimitIsReached(): void
    {
        $throttle = $this->throttle(access: 100, failures: 2);

        $throttle->recordFailure('c');
        $before = $throttle->check('c');
        $throttle->recordFailure('c');
        $wait = $throttle->check('c');

        self::assertNull($before);
        self::assertNotNull($wait, 'plafond d\'échecs atteint : plus aucune recherche');
        self::assertGreaterThanOrEqual(1, $wait);
        self::assertLessThanOrEqual(900, $wait);
        self::assertNull($throttle->check('autre'), 'les échecs d\'un client ne touchent pas les autres');
    }

    public function testTheFailureCapDoesNotConsumeTheAccessAllowanceTwiceNorTheOtherWayRound(): void
    {
        $throttle = $this->throttle(access: 2, failures: 5);

        $throttle->recordFailure('c');
        $throttle->recordFailure('c');
        self::assertNull($throttle->check('c'), 'deux échecs sur cinq : la consultation est admise');
        self::assertNull($throttle->check('c'));
        self::assertNotNull($throttle->check('c'), 'le plafond de consultations s\'applique de son côté');
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
