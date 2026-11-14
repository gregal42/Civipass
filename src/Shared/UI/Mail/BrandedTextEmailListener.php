<?php

declare(strict_types=1);

namespace App\Shared\UI\Mail;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/**
 * Charte graphique sur TOUS les e-mails sortants : un message livré en texte seul (alertes d'exploitation, rappels de conservation ou d'inactivité,
 * point d'ancrage du registre…) reçoit une version HTML aux couleurs de Civipass, bâtie à partir de son texte. La partie texte n'est JAMAIS modifiée
 * (le point d'ancrage reste vérifiable à l'identique). Un message qui porte déjà une version HTML (gabarits `email/*`, `notification/*`) est laissé tel quel.
 */
final readonly class BrandedTextEmailListener
{
    public function __construct(private Environment $twig)
    {
    }

    #[AsEventListener]
    public function onMessage(MessageEvent $event): void
    {
        $message = $event->getMessage();
        if (!$message instanceof Email || null !== $message->getHtmlBody()) {
            return;
        }
        $text = $message->getTextBody();
        if (!\is_string($text) || '' === trim($text)) {
            return;
        }
        $message->html($this->twig->render('email/system.html.twig', [
            'subject' => (string) $message->getSubject(),
            'heading' => preg_replace('/^Civipass\s+[—-]\s+/u', '', (string) $message->getSubject()),
            'blocks' => $this->blocks($text),
        ]));
    }

    /** @return list<array{pre: bool, text: string}> paragraphes du texte ; un bloc JSON ou un texte de code est conservé tel quel ; les URL deviennent des liens */
    private function blocks(string $text): array
    {
        $blocks = [];
        foreach (preg_split('/\R{2,}/', trim($text)) ?: [] as $paragraph) {
            $paragraph = trim($paragraph);
            if ('' === $paragraph) {
                continue;
            }
            if (str_starts_with($paragraph, '{') || str_starts_with($paragraph, '[')) {
                $blocks[] = ['pre' => true, 'text' => $paragraph];
                continue;
            }
            $escaped = htmlspecialchars($paragraph, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
            $escaped = (string) preg_replace('~(https?://[^\s<]+)~', '<a href="$1">$1</a>', $escaped);
            $blocks[] = ['pre' => false, 'text' => nl2br($escaped, false)];
        }

        return $blocks;
    }
}
