<?php

declare(strict_types=1);

namespace App\Tests\Unit\Request;

use App\Request\UI\Http\PublicDepositThrottle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * SEC-003 : dépôts limités par adresse réseau ET par événement ; accusés limités par adresse destinataire. Comportement des adresses partagées.
 */
final class PublicDepositThrottleTest extends TestCase
{
    private function throttle(int $limit = 3): PublicDepositThrottle
    {
        return new PublicDepositThrottle(
            new RateLimiterFactory(['id' => 'd', 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '1 hour'], new InMemoryStorage()),
            new RateLimiterFactory(['id' => 'r', 'policy' => 'sliding_window', 'limit' => 3, 'interval' => '1 hour'], new InMemoryStorage()),
        );
    }

    /**
     * @param array<string, string> $headers
     */
    private function from(string $ip, array $headers = []): Request
    {
        return Request::create('/demandes/EVT/recapitulatif', 'POST', [], [], [], ['REMOTE_ADDR' => $ip] + $headers);
    }

    public function testUsersSharingAnAddressAreCountedTogetherUpToTheLimitThenRefusedWithAWait(): void
    {
        $throttle = $this->throttle(3);
        foreach (range(1, 3) as $citizen) {
            self::assertNull($throttle->checkDeposit($this->from('198.51.100.7'), 'event-1'), 'citoyen '.$citizen.' derrière la même adresse');
        }
        $refused = $this->from('198.51.100.7');
        $wait = $throttle->checkDeposit($refused, 'event-1');

        self::assertIsInt($wait);
        self::assertGreaterThanOrEqual(1, $wait);
        self::assertLessThanOrEqual(3600, $wait);
    }

    public function testAnotherAddressAndAnotherEventAreNeverAffected(): void
    {
        $throttle = $this->throttle(1);
        self::assertNull($throttle->checkDeposit($this->from('198.51.100.7'), 'event-1'));
        self::assertNotNull($throttle->checkDeposit($this->from('198.51.100.7'), 'event-1'));

        self::assertNull($throttle->checkDeposit($this->from('198.51.100.8'), 'event-1'), 'autre adresse');
        self::assertNull($throttle->checkDeposit($this->from('198.51.100.7'), 'event-2'), 'autre événement');
    }

    public function testAnIpv6SubscriberCannotEscapeByChangingTheLowAddressBits(): void
    {
        $throttle = $this->throttle(1);
        self::assertNull($throttle->checkDeposit($this->from('2001:db8:abcd:12::1'), 'event-1'));

        self::assertNotNull($throttle->checkDeposit($this->from('2001:db8:abcd:12:ffff:ffff:ffff:ffff'), 'event-1'), 'même préfixe /64');
        self::assertNull($throttle->checkDeposit($this->from('2001:db8:abcd:13::1'), 'event-1'), 'autre /64');
    }

    public function testAForgedForwardingHeaderDoesNotChangeTheKeyWithoutATrustedProxy(): void
    {
        $throttle = $this->throttle(1);
        self::assertNull($throttle->checkDeposit($this->from('198.51.100.7', ['HTTP_X_FORWARDED_FOR' => '203.0.113.1']), 'event-1'));

        self::assertNotNull($throttle->checkDeposit($this->from('198.51.100.7', ['HTTP_X_FORWARDED_FOR' => '203.0.113.2']), 'event-1'), 'l\'en-tête forgé ne donne pas une nouvelle clé');
    }

    public function testReceiptsToTheSameAddressAreCappedWhateverTheCase(): void
    {
        $throttle = $this->throttle();
        self::assertTrue($throttle->allowReceipt('Victime@Exemple.fr'));
        self::assertTrue($throttle->allowReceipt('victime@exemple.fr'));
        self::assertTrue($throttle->allowReceipt(' victime@exemple.fr '));
        self::assertFalse($throttle->allowReceipt('VICTIME@exemple.fr'), 'quatrième accusé vers la même adresse');
        self::assertTrue($throttle->allowReceipt('autre@exemple.fr'));
    }
}
