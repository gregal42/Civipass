<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shell;

use App\Permit\Infrastructure\RateLimiter\RateLimiterStorageFailure;
use App\Shell\RateLimiterStorageFailureListener;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * SEC-014 : une panne d'écriture des compteurs donne un 503 neutre journalisé ; aucune autre exception n'est touchée.
 */
final class RateLimiterStorageFailureListenerTest extends TestCase
{
    private function event(\Throwable $e): ExceptionEvent
    {
        return new ExceptionEvent($this->createStub(HttpKernelInterface::class), Request::create('/login'), HttpKernelInterface::MAIN_REQUEST, $e);
    }

    public function testAStorageFailureAnywhereInTheCauseChainBecomesANeutralLoggedServiceUnavailable(): void
    {
        $logger = new class () extends AbstractLogger {
            /** @var list<array{string, string}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [(string) $level, (string) $message];
            }
        };
        $listener = new RateLimiterStorageFailureListener($logger);

        $event = $this->event(new \RuntimeException('enveloppe', 0, new RateLimiterStorageFailure('/var/www/html/var/cache/prod/secret-path')));
        $listener->onException($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('60', $response->headers->get('Retry-After'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringNotContainsString('var/cache', (string) $response->getContent(), 'aucun chemin ni détail technique');
        self::assertSame('error', $logger->records[0][0] ?? null);
    }

    public function testAnyOtherExceptionIsLeftAlone(): void
    {
        $listener = new RateLimiterStorageFailureListener(new \Psr\Log\NullLogger());
        $event = $this->event(new \LogicException('autre'));

        $listener->onException($event);

        self::assertNull($event->getResponse());
    }
}
