<?php

declare(strict_types=1);

namespace App\Onboarding\Infrastructure;

use App\Onboarding\Application\AdministratorValidationNotifier;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Uid\Uuid;

/**
 * E-mail d'alerte à CWS (SEC-016), même transport et même expéditeur que les autres messages (Mailer, MAILER_FROM), même esprit que l'alerte d'exploitation.
 * Destinataire : `ADMIN_VALIDATION_ALERT_EMAIL`, à défaut `OPS_ALERT_EMAIL`. Le message ne contient QUE ce qui sert à traiter : commune, identifiant de la demande, date et la
 * commande de traitement — jamais le nom ni l'adresse du demandeur (la liste console les donne au super-administrateur), jamais de lien d'action.
 */
#[AsAlias(AdministratorValidationNotifier::class)]
final readonly class MailAdministratorValidationNotifier implements AdministratorValidationNotifier
{
    public function __construct(
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        #[Autowire('%env(MAILER_FROM)%')] private string $from,
        #[Autowire('%env(ADMIN_VALIDATION_ALERT_EMAIL)%')] private string $recipient,
        #[Autowire('%env(OPS_ALERT_EMAIL)%')] private string $fallbackRecipient,
    ) {
    }

    public function notifyPending(Uuid $validationId, string $communeName, \DateTimeImmutable $requestedAt): bool
    {
        $to = '' !== trim($this->recipient) ? trim($this->recipient) : trim($this->fallbackRecipient);
        if ('' === $to) {
            $this->logger->warning('onboarding.administrator_validation.no_recipient', ['validation' => $validationId->toRfc4122()]);

            return false;
        }
        $commune = trim((string) preg_replace('/[\r\n\t]+/', ' ', $communeName));
        try {
            $this->mailer->send((new Email())->from($this->from)->to($to)
                ->subject('Civipass — demande de validation d’un administrateur communal en attente')
                ->text(sprintf(
                    "Une demande de premier administrateur communal attend la validation d'un super-administrateur CWS.\n\nCommune : %s\nDemande : %s\nReçue le : %s\n\nAucun droit n'est attribué avant validation. Vérifier la légitimité hors Civipass puis traiter selon la procédure :\n  bin/console app:onboarding:validate-administrator --operator=<votre adresse>\n(docs/runbooks/validation-administrateur-communal.md)\n",
                    $commune,
                    $validationId->toRfc4122(),
                    $requestedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i \U\T\C'),
                )));
        } catch (\Throwable $e) {
            $this->logger->error('onboarding.administrator_validation.notification_failed', ['validation' => $validationId->toRfc4122(), 'exception' => $e::class]);

            return false;
        }

        return true;
    }
}
