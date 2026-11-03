<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Environment;

/**
 * Non-régression de la compatibilité du design system avec le Twig PHP du projet (il a été conçu avec twig.js) :
 * chaque gabarit compile, chaque exemple se rend. Aucune base de données.
 */
final class DesignSystemTwigTest extends KernelTestCase
{
    private const ROOT = __DIR__.'/../..';

    private function twig(): Environment
    {
        self::bootKernel();
        // Certains composants (menu utilisateur) émettent un jeton CSRF de déconnexion : il leur faut une session.
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get('request_stack')->push($request);

        return self::getContainer()->get('twig');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function templates(): iterable
    {
        foreach (array_merge(glob(self::ROOT.'/templates/components/*.twig') ?: [], glob(self::ROOT.'/templates/layouts/*.twig') ?: []) as $file) {
            yield str_replace(self::ROOT.'/templates/', '', $file) => [str_replace(self::ROOT.'/templates/', '', $file)];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function examples(): iterable
    {
        foreach (glob(self::ROOT.'/templates/dev/ui/examples/*.twig') ?: [] as $file) {
            yield basename($file, '.html.twig') => [basename($file)];
        }
    }

    #[DataProvider('templates')]
    public function testEveryTemplateCompiles(string $name): void
    {
        self::assertSame($name, $this->twig()->load($name)->getTemplateName());
    }

    #[DataProvider('examples')]
    public function testEveryExampleRenders(string $file): void
    {
        $html = $this->twig()->render('dev/ui/examples/'.$file, ['qr_svg' => '<svg aria-hidden="true"><rect width="1" height="1"/></svg>']);

        self::assertGreaterThan(50, \strlen(trim($html)));
        self::assertStringNotContainsString('{{', $html);
        self::assertStringNotContainsString('{%', $html);
    }

    public function testTheCatalogMatchesTheImportedExamples(): void
    {
        /** @var list<array{name: string}> $catalog */
        $catalog = json_decode((string) file_get_contents(self::ROOT.'/src/Shared/UI/DevUi/Resources/catalog.json'), true, 512, \JSON_THROW_ON_ERROR);
        $names = array_column($catalog, 'name');
        sort($names);
        $examples = array_map(static fn (string $f): string => basename($f, '.html.twig'), glob(self::ROOT.'/templates/dev/ui/examples/*.twig') ?: []);
        sort($examples);

        self::assertSame($names, $examples);
        self::assertCount(66, $names); // 56 (UI-01) + 10 composants du LOT UI-02
    }

    public function testComponentsDoNotInheritPageVariables(): void
    {
        // Règle du design system : toujours « include … only ». Un composant rendu sans contexte ne doit pas échouer.
        $html = $this->twig()->render('components/status_pill.html.twig', ['status' => 'pending']);

        self::assertStringContainsString('En attente', $html);
    }
}
