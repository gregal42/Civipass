<?php

declare(strict_types=1);

namespace App\Onboarding\Infrastructure;

use App\Onboarding\Application\InvitationDeliveryFailed;
use App\Onboarding\Application\InvitationNotifier;
use App\Onboarding\Domain\InvitationEmailContext;
use App\Onboarding\Domain\InvitationLink;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * E-mail d'invitation (HTML + texte) envoyé par Symfony Mailer : MailCatcher en développement, aucun envoi réel sur Internet.
 * Le lien est construit à partir de DEFAULT_URI (configuration), JAMAIS de l'en-tête Host de la requête (attaque par en-tête
 * Host). L'e-mail ne contient aucun mot de passe et ne donne aucun droit : le lien ouvre une page d'accueil qui vérifie les droits.
 */
#[AsAlias(InvitationNotifier::class)]
final readonly class MailInvitationNotifier implements InvitationNotifier
{
    public function __construct(
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urls,
        #[Autowire('%env(DEFAULT_URI)%')]
        private string $baseUrl,
    ) {
    }

    public function sendContactInvitation(InvitationLink $link, string $plainToken, InvitationEmailContext $context): void
    {
        $path = $this->urls->generate('app_invitation_link', ['token' => $plainToken], UrlGeneratorInterface::ABSOLUTE_PATH);
        $email = (new TemplatedEmail())
            ->to(new Address($link->recipientEmail()))
            ->subject(sprintf('%s vous invite à rejoindre « %s » sur Civipass', $context->organizerName, $context->eventName))
            ->textTemplate('email/event_invitation.txt.twig')
            ->htmlTemplate('email/event_invitation.html.twig')
            ->context([
                'organizerName' => $context->organizerName,
                'eventName' => $context->eventName,
                'communeName' => $context->communeName,
                'message' => $context->message,
                'joinUrl' => rtrim($this->baseUrl, '/').$path,
                'expiresAt' => $context->expiresAt,
            ]);
        try {
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            throw new InvitationDeliveryFailed('Envoi de l\'e-mail d\'invitation impossible.', 0, $e);
        }
    }

    public function sendAdministratorInvitation(InvitationLink $link, string $plainToken, InvitationEmailContext $context, string $maskedContact): void
    {
        $path = $this->urls->generate('app_invitation_link', ['token' => $plainToken], UrlGeneratorInterface::ABSOLUTE_PATH);
        $email = (new TemplatedEmail())
            ->to(new Address($link->recipientEmail()))
            ->subject(sprintf('Administrer %s sur Civipass : votre invitation personnelle', $context->communeName))
            ->textTemplate('email/event_invitation_administrator.txt.twig')
            ->htmlTemplate('email/event_invitation_administrator.html.twig')
            ->context([
                'organizerName' => $context->organizerName,
                'eventName' => $context->eventName,
                'communeName' => $context->communeName,
                'maskedContact' => $maskedContact,
                'joinUrl' => rtrim($this->baseUrl, '/').$path,
                'expiresAt' => $context->expiresAt,
            ]);
        try {
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            throw new InvitationDeliveryFailed('Envoi de l\'e-mail d\'invitation impossible.', 0, $e);
        }
    }
}
