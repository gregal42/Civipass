<?php

declare(strict_types=1);

namespace App\Onboarding\Infrastructure;

use App\Municipality\Application\RoleLabels;
use App\Onboarding\Application\AdminInvitationNotifier;
use App\Onboarding\Application\InvitationDeliveryFailed;
use App\Onboarding\Domain\MunicipalityAdminInvitation;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * E-mail (HTML + texte) de l'invitation d'administrateur communal. Lien construit à partir de DEFAULT_URI (jamais de l'en-tête
 * Host). Aucun mot de passe, aucun droit : le lien ouvre une page qui exige la création du compte et l'attestation.
 */
#[AsAlias(AdminInvitationNotifier::class)]
final readonly class MailAdminInvitationNotifier implements AdminInvitationNotifier
{
    public function __construct(
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urls,
        #[Autowire('%env(DEFAULT_URI)%')]
        private string $baseUrl,
    ) {
    }

    public function send(MunicipalityAdminInvitation $invitation, #[\SensitiveParameter] string $plainToken, ?string $inviterName = null): void
    {
        $path = $this->urls->generate('app_invitation_link', ['token' => $plainToken], UrlGeneratorInterface::ABSOLUTE_PATH);
        $email = (new TemplatedEmail())
            ->to(new Address($invitation->recipientEmail()))
            ->subject(null === $inviterName
                ? sprintf('Administrer %s sur Civipass : votre invitation', $invitation->municipality()->name())
                : sprintf('%s vous invite à rejoindre l’équipe de %s sur Civipass', $inviterName, $invitation->municipality()->name()))
            ->textTemplate('email/admin_invitation.txt.twig')
            ->htmlTemplate('email/admin_invitation.html.twig')
            ->context([
                'communeName' => $invitation->municipality()->name(),
                'inviterName' => $inviterName,
                'roles' => array_map(static fn ($r): array => ['label' => RoleLabels::label($r), 'description' => RoleLabels::description($r)], $invitation->roles()),
                'joinUrl' => rtrim($this->baseUrl, '/').$path,
                'expiresAt' => $invitation->expiresAt(),
            ]);
        try {
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            throw new InvitationDeliveryFailed('Envoi de l\'e-mail d\'invitation impossible.', 0, $e);
        }
    }
}
