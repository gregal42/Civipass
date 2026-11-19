<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shell;

use App\Control\UI\Http\ControlOriginListener;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * SEC-013 : en production, le contrôle hors connexion activé sans CONTROL_HOST ne peut pas partager l'origine des cookies de session (404 générique) ;
 * le comportement de développement et de test est inchangé.
 */
final class ControlOriginFailClosedTest extends TestCase
{
    private function handle(ControlOriginListener $listener, string $path): ?\Throwable
    {
        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), Request::create('http://civipass.exemple.fr'.$path), HttpKernelInterface::MAIN_REQUEST);
        try {
            $listener->onRequest($event);
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
    }

    /**
     * @return iterable<string, array{string, bool, string, bool}>
     */
    public static function cases(): iterable
    {
        yield 'prod, contrôle actif, pas d\'hôte : refusé' => ['', true, 'prod', true];
        yield 'prod, contrôle inactif : sans effet' => ['', false, 'prod', false];
        yield 'dev, contrôle actif, pas d\'hôte : inchangé' => ['', true, 'dev', false];
        yield 'test, contrôle actif, pas d\'hôte : inchangé' => ['', true, 'test', false];
    }

    #[DataProvider('cases')]
    public function testControlPathsOnASingleOrigin(string $host, bool $enabled, string $env, bool $refused): void
    {
        $listener = new ControlOriginListener($host, $enabled, $env);

        $error = $this->handle($listener, '/controle/app');
        self::assertSame($refused, $error instanceof NotFoundHttpException);
        self::assertNull($this->handle($listener, '/login'), 'le reste du site n\'est jamais touché');
    }

    public function testWithAControlHostTheGuardsStayActiveWhateverTheEnvironment(): void
    {
        $listener = new ControlOriginListener('controle.civipass.exemple.fr', true, 'prod');

        self::assertInstanceOf(NotFoundHttpException::class, $this->handle($listener, '/controle/app'), 'G3 : /controle refusé sur la gestion');
        self::assertNull($this->handle($listener, '/login'));
    }
}
