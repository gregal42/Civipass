<?php

declare(strict_types=1);

namespace App\Tests\Unit\Permit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * RECETTE (LOT 05.5.6, rendu à 390 px) : deux défauts d'affichage trouvés dans les écrans des LOT 05.5.2 à 05.5.5 sont verrouillés. (1) Une liste `cp-consequences` est une grille
 * « icône 20 px + UN seul élément de texte » : un `<li>` qui mélange texte brut et balises éclate en colonnes (le texte était écrasé dans 20 px). (2) Un bloc `cp-box` a un en-tête
 * `cp-box__head` et un corps `cp-box__body` (marges) : sans eux, le texte colle au cadre. Contrôle statique des gabarits de `templates/permit`.
 */
#[Group('recette')]
final class PermitTemplateStructureTest extends TestCase
{
    /** @return list<string> */
    private static function templates(): array
    {
        return glob(\dirname(__DIR__, 3).'/templates/permit/*.html.twig') ?: [];
    }

    public function testEveryConsequenceItemIsAnIconFollowedByASingleTextElement(): void
    {
        $checked = 0;
        foreach (self::templates() as $file) {
            $source = (string) file_get_contents($file);
            if (1 !== preg_match_all('#<ul class="cp-consequences[^"]*">(.*?)</ul>#s', $source, $lists)) {
                if (!str_contains($source, 'cp-consequences')) {
                    continue;
                }
            }
            foreach ($lists[1] as $list) {
                preg_match_all('#<li>(.*?)</li>#s', $list, $items);
                foreach ($items[1] as $item) {
                    ++$checked;
                    self::assertMatchesRegularExpression('#^\s*\{% include \'components/icon\.html\.twig\'[^%]*%\}<span>.*</span>\s*$#s', $item, basename($file).' : un <li> de cp-consequences doit être « icône + <span>texte</span> » : '.trim(substr($item, 0, 90)));
                }
            }
        }
        self::assertGreaterThan(8, $checked, 'des éléments de liste ont bien été contrôlés');
    }

    public function testEveryBoxHasItsHeadAndBodyWrappers(): void
    {
        $checked = 0;
        foreach (self::templates() as $file) {
            $source = (string) file_get_contents($file);
            preg_match_all('#<section class="cp-box"[^>]*>(.*?)</section>#s', $source, $boxes);
            foreach ($boxes[1] as $box) {
                ++$checked;
                self::assertStringContainsString('<div class="cp-box__head">', $box, basename($file).' : cp-box sans en-tête');
                if (\in_array(basename($file), ['period.html.twig', 'vehicles.html.twig', 'zones.html.twig', 'qr.html.twig'], true)) { // écrans des LOT 05.5 ; la page du bénéficiaire et la page publique ont leur propre mise en page de corps (pp-*, vp-*)
                    self::assertStringContainsString('<div class="cp-box__body">', $box, basename($file).' : cp-box sans corps (marges)');
                }
            }
        }
        self::assertGreaterThan(3, $checked);
    }

    public function testNoTemplateWritesATechnicalEnumerationValueToTheUser(): void
    {
        foreach (self::templates() as $file) {
            self::assertDoesNotMatchRegularExpression('#\.state\.value\s*\|\s*lower#', (string) file_get_contents($file), basename($file).' : valeur technique d\'énumération affichée (utiliser cp_state_label)');
        }
    }
}
