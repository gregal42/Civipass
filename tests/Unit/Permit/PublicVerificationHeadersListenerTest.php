<?php

declare(strict_types=1);

namespace App\Tests\Unit\Permit;

use App\Permit\UI\Http\PublicVerificationHeadersListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Le listener pose les en-têtes de protection sur `/v/…` et ne retire AUCUN cookie : l'absence de cookie vient du pare-feu, et un cookie qui
 * apparaîtrait doit faire échouer les tests au lieu d'être masqué.
 */
final class PublicVerificationHeadersListenerTest extends TestCase
{
    private function dispatch(string $path, Response $response, int $type = HttpKernelInterface::MAIN_REQUEST): Response
    {
        $event = new ResponseEvent($this->createStub(KernelInterface::class), Request::create($path), $type, $response);
        (new PublicVerificationHeadersListener())->onResponse($event);

        return $event->getResponse();
    }

    public function testItNoLongerStripsCookies(): void
    {
        $response = new Response();
        $response->headers->setCookie(Cookie::create('SESSID', 'valeur'));

        $out = $this->dispatch('/v/abcdefghijklmnopqrstuvwxyz234567', $response);

        self::assertCount(1, $out->headers->getCookies(), 'un cookie posé à tort reste visible : les tests doivent échouer, pas être aveuglés');
        self::assertSame('no-store, private', $out->headers->get('Cache-Control'));
        self::assertSame(PublicVerificationHeadersListener::CSP, $out->headers->get('Content-Security-Policy'));
    }

    public function testOnlyThePublicPathIsConcerned(): void
    {
        self::assertTrue(PublicVerificationHeadersListener::concerns('/v'));
        self::assertTrue(PublicVerificationHeadersListener::concerns('/v/'));
        self::assertTrue(PublicVerificationHeadersListener::concerns('/v/abc/def'));
        self::assertFalse(PublicVerificationHeadersListener::concerns('/versions'));
        self::assertFalse(PublicVerificationHeadersListener::concerns('/vente/x'));
        self::assertFalse(PublicVerificationHeadersListener::concerns('/communes'));
        self::assertNull($this->dispatch('/communes', new Response())->headers->get('Content-Security-Policy'));
        self::assertNull($this->dispatch('/v/x', new Response(), HttpKernelInterface::SUB_REQUEST)->headers->get('Content-Security-Policy'));
    }
}
