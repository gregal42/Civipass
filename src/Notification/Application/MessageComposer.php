<?php

declare(strict_types=1);

namespace App\Notification\Application;

use App\Notification\Domain\NotificationType;
use App\Permit\Application\Read\NotificationFacts;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;

/**
 * Composition du message du bénéficiaire (LOT 05.3.3, DM-117, D9) à partir de l'état COURANT du laissez-passer. Gabarits texte et HTML sobres,
 * sans image, sans ressource externe, sans pixel de suivi. Contenu : prénom, référence LP, événement, communes et zones ; le lien bénéficiaire
 * UNIQUEMENT dans le message de première approbation. Jamais de plaque, d'adresse postale, de justificatif ni de motif.
 *
 * Le Message-ID est dérivé de l'identifiant du message d'outbox : identique à chaque réessai (les clients de messagerie et les serveurs peuvent
 * dédoublonner un envoi répété). L'URL du lien est bâtie à partir de DEFAULT_URI, jamais de l'en-tête Host.
 */
final readonly class MessageComposer
{
    public function __construct(
        private Environment $twig,
        private UrlGeneratorInterface $urls,
        #[Autowire('%env(DEFAULT_URI)%')]
        private string $baseUrl,
        #[Autowire('%env(MAILER_FROM)%')]
        private string $senderAddress,
    ) {
    }

    /**
     * @param string|null $secret secret du lien, obligatoire pour la première approbation, interdit sinon
     *
     * @throws \InvalidArgumentException adresse du destinataire invalide (`RfcComplianceException`)
     */
    public function compose(Uuid $outboxId, NotificationType $type, NotificationFacts $facts, #[\SensitiveParameter] ?string $secret): Email
    {
        $recipient = $facts->recipient() ?? throw new \LogicException('Destinataire absent.');
        if ((NotificationType::PermitIssued === $type) !== (null !== $secret)) {
            throw new \LogicException('Le lien n\'accompagne que la première mise à disposition.');
        }
        $context = [
            'firstName' => $facts->firstName,
            'reference' => $facts->reference,
            'eventName' => $facts->eventName,
            'communes' => $facts->communes,
            'url' => null === $secret ? null : rtrim($this->baseUrl, '/').$this->urls->generate('app_beneficiary_pass', ['secret' => $secret], UrlGeneratorInterface::ABSOLUTE_PATH),
        ];
        [$name, $subject] = match ($type) {
            NotificationType::PermitIssued => ['permit_issued', sprintf('Votre laissez-passer %s est disponible', $facts->reference)],
            NotificationType::AuthorizationAdded => ['authorization_added', sprintf('Votre laissez-passer %s : droits étendus', $facts->reference)],
            default => throw new \LogicException('Aucun gabarit pour ce type de message avant le LOT 05.5.'),
        };

        $email = (new Email())
            ->to(new Address($recipient))
            ->subject($subject)
            ->text($this->twig->render('notification/'.$name.'.txt.twig', $context))
            ->html($this->twig->render('notification/'.$name.'.html.twig', $context));
        $domain = substr(strrchr($this->senderAddress, '@') ?: '@civipass.invalid', 1);
        $email->getHeaders()->addIdHeader('Message-ID', $outboxId->toRfc4122().'@'.$domain);
        $email->getHeaders()->addTextHeader('Auto-Submitted', 'auto-generated');

        return $email;
    }
}
