<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * Faux transport des processus de test : une ligne JSON par message accepté (Message-ID, empreinte du secret du lien, jamais le clair ni l'adresse),
 * dans un fichier partagé. `crashAfterAcceptance` : le processus meurt APRÈS l'acceptation, avant d'avoir pu marquer le message envoyé.
 */
final class FileMailbox implements MailerInterface
{
    public function __construct(private readonly string $file, private readonly bool $crashAfterAcceptance = false, private readonly int $delayMicroseconds = 0)
    {
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        \assert($message instanceof Email);
        usleep($this->delayMicroseconds);
        $text = (string) $message->getTextBody();
        $line = json_encode([
            'id' => $message->getHeaders()->get('Message-ID')?->getBodyAsString() ?? '',
            'secretHash' => 1 === preg_match('#/laissez-passer/([A-Za-z0-9_-]{43})#', $text, $m) ? hash('sha256', $m[1]) : null,
            'pid' => getmypid(),
        ], \JSON_THROW_ON_ERROR)."\n";
        file_put_contents($this->file, $line, \FILE_APPEND | \LOCK_EX);
        if ($this->crashAfterAcceptance) {
            exit(9);
        }
    }
}
