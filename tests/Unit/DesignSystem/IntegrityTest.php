<?php

declare(strict_types=1);

namespace App\Tests\Unit\DesignSystem;

use PHPUnit\Framework\TestCase;

/**
 * Le design system importé ne se modifie pas « à la main » : tout passe par design-system/tools/import.mjs, qui
 * enregistre les empreintes dans design-system/provenance.json. Ces tests échouent si un fichier importé a changé
 * sans repasser par l'outil, et vérifient la compatibilité avec AssetMapper / Stimulus / Turbo.
 */
final class IntegrityTest extends TestCase
{
    private const ROOT = __DIR__.'/../../..';

    /**
     * @return array{files: list<array{source: ?string, target: string, sourceSha: ?string, targetSha: string, adapted: bool}>, adaptations: list<array<string, mixed>>, counts: array<string, int>}
     */
    private static function provenance(): array
    {
        /** @var array{files: list<array{source: ?string, target: string, sourceSha: ?string, targetSha: string, adapted: bool}>, adaptations: list<array<string, mixed>>, counts: array<string, int>} $data */
        $data = json_decode((string) file_get_contents(self::ROOT.'/design-system/provenance.json'), true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }

    public function testEveryImportedFileMatchesItsRecordedHash(): void
    {
        $modified = [];
        foreach (self::provenance()['files'] as $file) {
            $path = self::ROOT.'/'.$file['target'];
            if (!is_file($path)) {
                $modified[] = $file['target'].' (absent)';
                continue;
            }
            if (hash_file('sha256', $path) !== $file['targetSha']) {
                $modified[] = $file['target'];
            }
        }
        self::assertSame([], $modified, 'Fichiers importés modifiés hors de l\'outil d\'import (design-system/tools/import.mjs).');
    }

    public function testFilesNotMarkedAdaptedAreIdenticalToTheSource(): void
    {
        foreach (self::provenance()['files'] as $file) {
            if (!$file['adapted'] && null !== $file['source']) {
                self::assertSame($file['sourceSha'], $file['targetSha'], $file['target'].' est déclaré identique à la source.');
            }
        }
    }

    public function testEveryAdaptationIsDocumented(): void
    {
        $provenance = (string) file_get_contents(self::ROOT.'/design-system/PROVENANCE.md');
        $ids = array_unique(array_map(static fn (array $a): string => (string) $a['id'], self::provenance()['adaptations']));
        self::assertNotEmpty($ids);
        foreach ($ids as $id) {
            self::assertStringContainsString('| '.$id.' |', $provenance, 'Adaptation '.$id.' absente de PROVENANCE.md.');
        }
    }

    public function testStimulusControllersAreBrowserReadyJavaScript(): void
    {
        $controllers = glob(self::ROOT.'/assets/controllers/cp/*_controller.js') ?: [];
        self::assertCount(self::provenance()['counts']['controllers'], $controllers);
        foreach ($controllers as $file) {
            $code = (string) file_get_contents($file);
            self::assertDoesNotMatchRegularExpression('/from\s+["\']\.\.?\/[^"\']+(?<!\.js)["\']/', $code, basename($file).' : import relatif sans extension (AssetMapper/navigateur).');
            self::assertDoesNotMatchRegularExpression('/^\s*import\s+type\b|\bdeclare\s+readonly\b|:\s*(HTMLElement|string|boolean)\b\s*[=;)]/m', $code, basename($file).' : TypeScript résiduel.');
            self::assertMatchesRegularExpression('/export\s*\{\s*\w+\s+as\s+default\s*\}|export\s+default\s/', $code, basename($file).' : export par défaut attendu par StimulusBundle.');
        }
        self::assertFileExists(self::ROOT.'/assets/controllers/cp/events.js');
        self::assertCount(0, glob(self::ROOT.'/assets/controllers/cp/*.ts') ?: [], 'Aucun TypeScript dans Civipass (CLAUDE.md, règle 9).');
    }

    public function testTokensCssIsScopedAndReferencesAnExistingFont(): void
    {
        $tokens = (string) file_get_contents(self::ROOT.'/assets/styles/civipass/tokens.css');
        self::assertDoesNotMatchRegularExpression('/^\.(display|h1|h2|h3|body|body-strong|small|caption|eyebrow|ref|plate|plate-lg)\s*\{/m', $tokens, 'Classes typographiques non préfixées : collision possible avec Bootstrap/Sonata.');
        self::assertMatchesRegularExpression('/^\.cp-h1\s*\{/m', $tokens);
        preg_match_all('~url\("([^"]+\.woff2)"\)~', $tokens, $fonts);
        self::assertCount(5, $fonts[1]);
        foreach ($fonts[1] as $url) {
            self::assertFileExists(self::ROOT.'/assets/styles/civipass/'.$url, 'Police introuvable pour AssetMapper : '.$url);
        }
        self::assertFileExists(self::ROOT.'/assets/fonts/OFL.txt', 'La licence des polices (OFL) doit accompagner les fichiers.');
    }

    public function testComponentCssDoesNotStyleGlobalElements(): void
    {
        $css = (string) file_get_contents(self::ROOT.'/assets/styles/civipass/civipass.css');
        self::assertDoesNotMatchRegularExpression('/^(html|body|a|button|input|h[1-6]|p|ul|table|\*)\s*[,{]/m', $css, 'Le design system doit rester scopé sous .cp-root.');
    }

    public function testNoGetLogoutLinkRemainsInTheTemplates(): void
    {
        foreach (['topbar', 'mobile_menu'] as $component) {
            $source = (string) file_get_contents(self::ROOT.'/templates/components/'.$component.'.html.twig');
            self::assertStringNotContainsString('href="{{ logout_href', $source, $component.' : la déconnexion est POST + CSRF.');
            self::assertStringContainsString('csrf_token(\'logout\')', $source);
            self::assertStringContainsString('method="post"', $source);
        }
    }

    public function testLayoutsLoadStylesByLinkAndAreNotCachedByTurbo(): void
    {
        foreach (['app', 'public', 'auth'] as $layout) {
            $source = (string) file_get_contents(self::ROOT.'/templates/layouts/'.$layout.'.html.twig');
            self::assertStringContainsString("asset('styles/civipass/tokens.css')", $source, $layout);
            self::assertStringContainsString("asset('styles/civipass/civipass.css')", $source, $layout);
            self::assertStringContainsString('turbo-cache-control', $source, $layout.' : pas de cache Turbo pour des pages à données personnelles.');
            self::assertStringContainsString("importmap('app')", $source, $layout);
        }
        self::assertStringNotContainsString('styles/', (string) file_get_contents(self::ROOT.'/assets/app.js'), 'Le CSS ne se charge pas par JavaScript (flash de page non stylée).');
    }

    public function testNoExternalResourceIsLoaded(): void
    {
        foreach (array_merge(glob(self::ROOT.'/templates/layouts/*.twig') ?: [], glob(self::ROOT.'/templates/components/*.twig') ?: []) as $file) {
            self::assertDoesNotMatchRegularExpression('~(src|href)="https?://~', (string) file_get_contents($file), basename($file).' : ressource externe (RGPD : polices et scripts servis par l\'application).');
        }
    }

    public function testPreviewRoutesAreRestrictedToTheDevEnvironmentInTheSource(): void
    {
        $controller = (string) file_get_contents(self::ROOT.'/src/Shared/UI/DevUi/DesignPreviewController.php');
        self::assertStringContainsString("#[When(env: 'dev')]", $controller);
        self::assertStringContainsString("env: 'dev')]", $controller);
        self::assertStringContainsString('assertDevelopment', $controller);
        self::assertDoesNotMatchRegularExpression('/use Doctrine|EntityManager|Repository/', $controller, 'La prévisualisation ne dépend d\'aucune entité.');
    }
}
