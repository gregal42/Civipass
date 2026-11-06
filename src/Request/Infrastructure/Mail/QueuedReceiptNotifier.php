<?php

declare(strict_types=1);

namespace App\Request\Infrastructure\Mail;

use App\Identity\Infrastructure\Mail\DeferredMailQueue;
use App\Request\Application\ReceiptNotifier;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Mime\Address;

/**
 * Réutilise la file d'emails existante : l'envoi a lieu APRÈS la réponse (kernel.terminate), donc ni la durée du transport ni
 * un incident SMTP n'affectent le dépôt ; un échec est journalisé sans destinataire, sans relance (le lot Notifications
 * remplacera la file par un envoi asynchrone avec outbox).
 */
#[AsAlias(ReceiptNotifier::class)]
final readonly class QueuedReceiptNotifier implements ReceiptNotifier
{
    public function __construct(private DeferredMailQueue $queue)
    {
    }

    public function sendReceipt(string $email, string $reference, string $eventName): void
    {
        $this->queue->enqueue((new TemplatedEmail())
            ->to(new Address($email))
            ->subject(sprintf('Votre demande %s a bien été reçue', $reference))
            ->textTemplate('email/request_receipt.txt.twig')
            ->htmlTemplate('email/request_receipt.html.twig')
            ->context(['reference' => $reference, 'eventName' => $eventName]));
    }
}
