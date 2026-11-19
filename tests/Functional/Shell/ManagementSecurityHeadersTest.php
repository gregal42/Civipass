<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shell;

use App\Control\UI\Http\ControlHeadersListener;
use App\Permit\UI\Http\PublicVerificationHeadersListener;
use App\Shell\ManagementSecurityHeadersListener;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * SEC-002 : l'origine de gestion porte des en-têtes de sécurité de base ; les politiques plus strictes des autres surfaces ne sont pas écrasées.
 */
final class ManagementSecurityHeadersTest extends WebTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function managementPages(): iterable
    {
        yield 'connexion' => ['/login'];
        yield 'réinitialisation' => ['/reset-password'];
        yield 'page inconnue (404)' => ['/page-qui-n-existe-pas'];
        yield 'espace communal sans session (redirection)' => ['/communes'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('managementPages')]
    public function testManagementResponsesCarryTheBaselineHeaders(string $path): void
    {
        $client = static::createClient();
        $client->request('GET', $path);
        $headers = $client->getResponse()->headers;

        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'), $path);
        self::assertSame('DENY', $headers->get('X-Frame-Options'), $path);
        self::assertSame('same-origin', $headers->get('Referrer-Policy'), $path);
        self::assertSame(ManagementSecurityHeadersListener::CSP, $headers->get('Content-Security-Policy'), $path);
    }

    public function testStricterPoliciesOfOtherSurfacesAreLeftUntouched(): void
    {
        $client = static::createClient();
        $client->request('GET', '/v/'.str_repeat('A', 43));
        self::assertSame(PublicVerificationHeadersListener::CSP, $client->getResponse()->headers->get('Content-Security-Policy'));
        self::assertSame('no-referrer', $client->getResponse()->headers->get('Referrer-Policy'));

        $client->request('GET', '/controle/');
        self::assertSame(ControlHeadersListener::CSP, $client->getResponse()->headers->get('Content-Security-Policy'));
        self::assertSame('no-referrer', $client->getResponse()->headers->get('Referrer-Policy'));
    }

    public function testNoScriptRestrictionIsImposedYet(): void
    {
        self::assertStringNotContainsString('script-src', ManagementSecurityHeadersListener::CSP, 'une CSP de scripts exige une recette en navigateur réel (dette P2)');
    }
}
