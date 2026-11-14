<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/** Charte Civipass sur tous les e-mails : gabarit commun pour les messages HTML, version HTML bâtie pour les messages en texte seul. */
final class BrandedEmailTest extends KernelTestCase
{
    public function testEveryHtmlEmailTemplateUsesTheCommonLayout(): void
    {
        $root = \dirname(__DIR__, 3).'/templates';
        $files = array_merge(glob($root.'/email/*.html.twig') ?: [], glob($root.'/notification/*.html.twig') ?: []);
        self::assertNotEmpty($files);
        foreach ($files as $file) {
            if (str_ends_with($file, '/layout.html.twig')) {
                continue;
            }
            self::assertStringContainsString("extends 'email/layout.html.twig'", (string) file_get_contents($file), basename($file).' doit étendre le gabarit commun');
        }
    }

    public function testTextOnlyEmailGetsBrandedHtmlAndKeepsItsTextUntouched(): void
    {
        $text = "Bonjour,\n\nL'événement « A & B » demande une action : https://civipass.exemple.fr/x?a=1&b=2\n\n{\"anchor\":\"abc\"}\n";
        $email = (new Email())->from('noreply@exemple.fr')->to('a@exemple.fr')->subject('Civipass — rappel important')->text($text);
        $this->dispatch($email);

        $html = (string) $email->getHtmlBody();
        self::assertSame($text, $email->getTextBody(), 'texte inchangé');
        self::assertStringContainsString('#1d3f73', $html, 'bandeau brand');
        self::assertStringContainsString('#f2a12e', $html, 'filet signal');
        self::assertStringContainsString('Civipass', $html);
        self::assertStringContainsString('prefers-color-scheme: dark', $html);
        self::assertStringContainsString('<h2', $html);
        self::assertStringContainsString('rappel important', $html);
        self::assertStringContainsString('A &amp; B', $html, 'échappé');
        self::assertStringContainsString('href="https://civipass.exemple.fr/x?a=1&amp;b=2"', $html);
        self::assertMatchesRegularExpression('/<pre[^>]*>\{"anchor":"abc"\}<\/pre>/', $html);
    }

    public function testEmailAlreadyInHtmlIsLeftAlone(): void
    {
        $email = (new Email())->from('noreply@exemple.fr')->to('a@exemple.fr')->subject('S')->text('t')->html('<p>déjà</p>');
        $this->dispatch($email);

        self::assertSame('<p>déjà</p>', $email->getHtmlBody());
    }

    private function dispatch(Email $email): void
    {
        self::bootKernel();
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $dispatcher->dispatch(new MessageEvent($email, new Envelope(new Address('noreply@exemple.fr'), [new Address('a@exemple.fr')]), 'test'));
    }
}
