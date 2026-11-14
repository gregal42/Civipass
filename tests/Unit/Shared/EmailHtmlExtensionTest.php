<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\UI\Mail\EmailHtmlExtension;
use PHPUnit\Framework\TestCase;

final class EmailHtmlExtensionTest extends TestCase
{
    public function testStylesParagraphsInlineWithTheCharterTokens(): void
    {
        $html = (new EmailHtmlExtension())->style('<h2>Titre</h2><p>Bonjour <strong>Léa</strong></p><p class="note">Mention</p>');

        self::assertStringContainsString('color:#0f1e36', $html, 'ink');
        self::assertStringContainsString('color:#56637a', $html, 'ink-3 pour les mentions');
        self::assertStringContainsString('class="cp-p"', $html);
        self::assertStringContainsString('class="note cp-note"', $html);
        self::assertStringContainsString('Léa', $html, 'accents conservés');
    }

    public function testCtaBecomesABulletproofBrandButton(): void
    {
        $html = (new EmailHtmlExtension())->style('<p class="cta"><a href="https://x.test/a?b=1&amp;c=2">Rejoindre</a></p>');

        self::assertStringContainsString('<table', $html);
        self::assertStringContainsString('bgcolor="#1d3f73"', $html, 'brand');
        self::assertStringContainsString('href="https://x.test/a?b=1&amp;c=2"', $html);
        self::assertStringContainsString('>Rejoindre</a>', $html);
        self::assertStringNotContainsString('class="cp-a"', $html, 'le bouton n\'est pas restylé comme un lien');
    }

    public function testLinksAndListsAreStyledAndMarkupIsNotInjected(): void
    {
        $html = (new EmailHtmlExtension())->style('<ul><li><a href="https://x.test">u</a></li></ul><p>&lt;script&gt;alert(1)&lt;/script&gt;</p>');

        self::assertStringContainsString('text-decoration:underline', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testEmptyInputGivesEmptyOutput(): void
    {
        self::assertSame('', (new EmailHtmlExtension())->style("  \n"));
    }
}
