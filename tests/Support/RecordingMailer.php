<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * Faux transport : enregistre ce qui est « accepté » et rejoue des pannes scénarisées. Ne contient aucun secret en dehors de ce que le test inspecte.
 * Chaque envoi note s'il a eu lieu dans une transaction de base (il ne doit jamais).
 */
final class RecordingMailer implements MailerInterface
{
    /** @var list<array{id: string, subject: string, text: string, html: string, secretHash: ?string, inTransaction: bool}> */
    public array $sent = [];

    /** @var list<array{id: string, secretHash: ?string, text: string}> chaque tentative, acceptée ou non */
    public array $tried = [];

    /** @var list<\Throwable|null> pannes à rejouer, une par envoi (null : accepter) */
    private array $script = [];

    /** @var (callable(): void)|null exécuté pendant l'envoi, avant l'acceptation (simule un événement concurrent) */
    public $duringSend;

    public int $attempts = 0;

    public function __construct(private readonly ?\Doctrine\DBAL\Connection $connection = null)
    {
    }

    public static function smtpFailure(int $code): TransportException
    {
        return new TransportException(sprintf('Expected response code "250" but got code "%d", with message "%d simulé pour marie@exemple.fr".', $code, $code));
    }

    public function script(?\Throwable ...$outcomes): void
    {
        $this->script = array_values($outcomes);
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        ++$this->attempts;
        \assert($message instanceof Email);
        $text = (string) $message->getTextBody();
        $this->tried[] = [
            'id' => $message->getHeaders()->get('Message-ID')?->getBodyAsString() ?? '',
            'secretHash' => 1 === preg_match('#/laissez-passer/([A-Za-z0-9_-]{43})#', $text, $m) ? hash('sha256', $m[1]) : null,
            'text' => $text,
        ];
        if (null !== $this->duringSend) {
            ($this->duringSend)();
        }
        $next = [] === $this->script ? null : array_shift($this->script);
        if (null !== $next) {
            throw $next;
        }
        $this->sent[] = [
            'id' => $message->getHeaders()->get('Message-ID')?->getBodyAsString() ?? '',
            'subject' => (string) $message->getSubject(),
            'text' => $text,
            'html' => (string) $message->getHtmlBody(),
            'secretHash' => 1 === preg_match('#/laissez-passer/([A-Za-z0-9_-]{43})#', $text, $m) ? hash('sha256', $m[1]) : null,
            'inTransaction' => $this->connection?->isTransactionActive() ?? false,
        ];
    }
}
