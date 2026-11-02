<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Mail;

use App\Identity\Application\Notification\PasswordResetNotifier;
use App\Identity\Domain\PasswordResetRequest;
use App\Identity\Domain\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Le lien est construit à partir de DEFAULT_URI (configuration), JAMAIS de l'en-tête Host de la requête :
 * un Host falsifié ne peut pas rediriger le lien vers un site pirate (attaque par en-tête Host).
 * Aucun email ne contient de mot de passe.
 */
#[AsAlias(PasswordResetNotifier::class)]
final readonly class QueuedPasswordResetNotifier implements PasswordResetNotifier
{
    public function __construct(
        private DeferredMailQueue $queue,
        private UrlGeneratorInterface $urls,
        #[Autowire('%env(DEFAULT_URI)%')]
        private string $baseUrl,
    ) {
    }

    public function sendResetLink(User $user, string $plainToken, \DateTimeImmutable $expiresAt): void
    {
        $path = $this->urls->generate('app_reset_password_token', ['token' => $plainToken], UrlGeneratorInterface::ABSOLUTE_PATH);
        $lifetime = (new \DateTimeImmutable('@0'))->add(new \DateInterval(PasswordResetRequest::LIFETIME))->getTimestamp() / 60;

        $this->queue->enqueue((new TemplatedEmail())
            ->to(new Address($user->email()))
            ->subject('Réinitialisation de votre mot de passe Civipass')
            ->textTemplate('email/password_reset.txt.twig')
            ->htmlTemplate('email/password_reset.html.twig')
            ->context([
                'firstName' => $user->firstName(),
                'resetUrl' => rtrim($this->baseUrl, '/').$path,
                'lifetimeMinutes' => (int) $lifetime,
            ]));
    }

    public function sendPasswordChanged(User $user): void
    {
        $this->queue->enqueue((new TemplatedEmail())
            ->to(new Address($user->email()))
            ->subject('Votre mot de passe Civipass a été modifié')
            ->textTemplate('email/password_changed.txt.twig')
            ->htmlTemplate('email/password_changed.html.twig')
            ->context([
                'firstName' => $user->firstName(),
                'forgotUrl' => rtrim($this->baseUrl, '/').$this->urls->generate('app_forgot_password', [], UrlGeneratorInterface::ABSOLUTE_PATH),
            ]));
    }
}
