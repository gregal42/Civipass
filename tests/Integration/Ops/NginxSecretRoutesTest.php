<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ops;

use App\Permit\Application\VerificationUrlFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Routing\RouterInterface;

/**
 * Contrôle de cohérence entre le routeur Symfony et le masquage des journaux nginx (ops/nginx) : toute route qui porte un secret dans son chemin doit être couverte, toute
 * route fixe sous un préfixe secret doit figurer dans la liste blanche (sinon elle serait masquée inutilement), et les liens sensibles sont bâtis en https:// sur le domaine
 * configuré, jamais à partir de l'hôte ou du schéma de la requête.
 */
final class NginxSecretRoutesTest extends KernelTestCase
{
    private const PREFIXES = ['v', 'laissez-passer', 'invitation', 'reset-password'];

    /**
     * @return array<string, list<string>> liste blanche des routes fixes, par préfixe
     */
    private function allowList(): array
    {
        $template = (string) file_get_contents(\dirname(__DIR__, 3).'/ops/nginx/templates/00-civipass-logging.conf.template');
        self::assertSame(1, preg_match('/invitation\(\?=\/\+\(\?!\(\?:([a-z|]+)\)\(\?:\/\|\$\)\)/', $template, $invitation));
        self::assertSame(1, preg_match('/reset-password\(\?=\/\+\(\?!\(\?:([a-z|]+)\)\(\?:\/\|\$\)\)/', $template, $reset));

        return ['invitation' => explode('|', $invitation[1]), 'reset-password' => explode('|', $reset[1])];
    }

    /**
     * @return array<string, string> chemin de route => nom
     */
    private function paths(): array
    {
        self::bootKernel();
        $paths = [];
        foreach (self::getContainer()->get(RouterInterface::class)->getRouteCollection()->all() as $name => $route) {
            $paths[$route->getPath()] = $name;
        }

        return $paths;
    }

    public function testEveryRouteCarryingASecretInItsPathIsCoveredByTheMask(): void
    {
        $uncovered = [];
        foreach ($this->paths() as $path => $name) {
            $segments = array_values(array_filter(explode('/', $path), static fn (string $s): bool => '' !== $s));
            $hasSecretParameter = 1 === preg_match('/\{[a-zA-Z]*(token|secret|jeton)[a-zA-Z]*\}/i', $path);
            if ($hasSecretParameter && !\in_array($segments[0] ?? '', self::PREFIXES, true)) {
                $uncovered[] = $path.' ('.$name.')';
            }
        }

        self::assertSame([], $uncovered, 'route avec un secret dans le chemin hors des préfixes masqués par nginx : étendre le masquage (00-civipass-logging.conf.template, filtre, tests)');
    }

    public function testEverySecretPrefixRouteWithAParameterIsMaskedAndEveryFixedRouteIsAllowListed(): void
    {
        $allow = $this->allowList();
        $parameterised = [];
        $fixedNotListed = [];
        foreach ($this->paths() as $path => $name) {
            $segments = array_values(array_filter(explode('/', $path), static fn (string $s): bool => '' !== $s));
            if (!\in_array($segments[0] ?? '', self::PREFIXES, true) || !isset($segments[1])) {
                continue;
            }
            if (str_starts_with($segments[1], '{')) {
                $parameterised[] = $path;
            } elseif (!\in_array($segments[1], $allow[$segments[0]] ?? [], true)) {
                $fixedNotListed[] = $path; // aucune route fixe tolérée sous /v/ ni /laissez-passer/ : son segment serait masqué
            }
        }

        self::assertSame([], $fixedNotListed, 'route fixe sous un préfixe secret absente de la liste blanche (elle serait masquée dans les journaux) : l\'ajouter à nginx ET au filtre');
        sort($parameterised);
        self::assertSame(['/invitation/{token}', '/laissez-passer/{secret}', '/laissez-passer/{secret}/pdf', '/reset-password/{token}', '/v/{token}', '/v/{token}/'], $parameterised);
        self::assertSame(['invitation' => ['delegation', 'rejoindre'], 'reset-password' => ['done']], $allow);
        // La liste blanche ne contient que des routes qui existent réellement.
        $existing = array_keys($this->paths());
        foreach ($allow as $prefix => $fixedRoutes) {
            foreach ($fixedRoutes as $fixed) {
                self::assertContains('/'.$prefix.'/'.$fixed, $existing);
            }
        }
    }

    public function testTokenFormatsMatchWhatTheMaskAndTheRoutesAssume(): void
    {
        $root = \dirname(__DIR__, 3).'/src';
        foreach (['Identity/Domain/PasswordResetToken.php', 'Onboarding/Domain/InvitationToken.php', 'Permit/Domain/AccessSecret.php'] as $file) {
            self::assertStringContainsString("rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=')", (string) file_get_contents($root.'/'.$file), $file.' : 256 bits, base64url sans remplissage = 43 caractères');
        }
        self::assertStringContainsString("'token' => '[A-Za-z0-9_-]{43}'", (string) file_get_contents($root.'/Identity/UI/PasswordResetController.php'));
        self::assertStringContainsString("'token' => '[A-Za-z0-9_-]{43}'", (string) file_get_contents($root.'/Onboarding/UI/Http/InvitationLinkController.php'));
        self::assertStringContainsString('random_bytes(20)', (string) file_get_contents($root.'/Permit/Domain/VerificationToken.php'), 'jeton de vérification : 160 bits = 32 caractères base32');
    }

    public function testSensitiveLinksAreBuiltFromTheConfiguredHttpsDomainNeverFromTheRequest(): void
    {
        $factory = new VerificationUrlFactory('https://civipass.exemple.fr/');

        self::assertSame('https://civipass.exemple.fr/v/abcdefghijklmnopqrstuvwxyz234567', $factory->forToken('abcdefghijklmnopqrstuvwxyz234567'));
        self::assertSame('https://civipass.exemple.fr/laissez-passer/xyz', $factory->absolute('/laissez-passer/xyz'));

        $offenders = [];
        foreach ((new Finder())->files()->in(\dirname(__DIR__, 3).'/src')->name('*.php') as $file) {
            if (1 === preg_match('/ABSOLUTE_URL|getSchemeAndHttpHost\(|getHttpHost\(|->getHost\(|HTTP_HOST|->getUri\(\)|X-Forwarded-Host/', $file->getContents())) {
                $offenders[] = $file->getRelativePathname();
            }
        }
        self::assertSame([], $offenders, 'aucun code ne construit d\'URL absolue à partir de la requête (hôte et schéma fournis par le client) ; DEFAULT_URI est la seule source');
    }

    public function testEveryMailedOrQrLinkUsesTheConfiguredBaseUrl(): void
    {
        $root = \dirname(__DIR__, 3).'/src';
        foreach (['Identity/Infrastructure/Mail/QueuedPasswordResetNotifier.php', 'Onboarding/Infrastructure/MailInvitationNotifier.php', 'Notification/Application/MessageComposer.php'] as $file) {
            $code = (string) file_get_contents($root.'/'.$file);
            self::assertStringContainsString('%env(DEFAULT_URI)%', $code, $file);
            self::assertStringContainsString('ABSOLUTE_PATH', $code, $file);
        }
        self::assertStringContainsString("'%env(DEFAULT_URI)%'", (string) file_get_contents($root.'/Permit/Application/VerificationUrlFactory.php'));
    }
}
