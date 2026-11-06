<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Mail;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * File d'emails envoyés APRÈS la réponse (kernel.terminate) : le temps de réponse d'une demande de réinitialisation
 * ne dépend pas de l'existence du compte (anti-énumération), et un incident SMTP ne casse pas la requête.
 *
 * V1 : pas de relance en cas d'échec (l'échec est journalisé, sans destinataire ni jeton). Évolution prévue
 * (LOT 07) : remplacer cette file par un message Messenger asynchrone avec outbox, derrière PasswordResetNotifier.
 */
final class DeferredMailQueue
{
    /** @var list<Email> */
    private array $messages = [];

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function enqueue(Email $message): void
    {
        $this->messages[] = $message;
    }

    public function flush(): void
    {
        $messages = $this->messages;
        $this->messages = [];

        foreach ($messages as $message) {
            try {
                $this->mailer->send($message);
            } catch (\Throwable $e) {
                // Jamais le jeton, le lien, la référence ni le destinataire dans les journaux.
                $this->logger->error('Échec d\'envoi d\'un email différé.', [
                    'subject' => $message->getSubject(),
                    'exception' => $e::class,
                ]);
            }
        }
    }
}
