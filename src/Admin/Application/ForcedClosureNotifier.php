<?php

declare(strict_types=1);

namespace App\Admin\Application;

use App\Municipality\Domain\MunicipalityRole;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Uid\Uuid;

/**
 * Information des administrateurs communaux après une clôture exceptionnelle (LOT 08.6, DM-139) : l'événement a été clos par l'éditeur de la plateforme.
 * JAMAIS le motif interne CWS, ni aucune donnée de dossier. Envoi APRÈS la validation de la clôture et au mieux (un échec est journalisé, il n'annule rien).
 * Destinataires : administrateurs communaux actifs des communes qui participaient à l'événement.
 */
final readonly class ForcedClosureNotifier
{
    public function __construct(
        private Connection $connection,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        #[Autowire('%env(MAILER_FROM)%')] private string $from,
    ) {
    }

    /** @param list<string> $municipalityNames informatif ; les destinataires sont relus par identifiant d'événement */
    public function notify(string $eventId, string $reference, string $eventName, array $municipalityNames): void
    {
        try {
            $recipients = array_map(static fn (mixed $v): string => (string) $v, $this->connection->fetchFirstColumn(
                'SELECT DISTINCT u.email FROM identity_user u JOIN municipality_membership m ON m.user_id = u.id'
                .' JOIN event_participation p ON p.municipality_id = m.municipality_id'
                .' WHERE p.event_id = :event AND m.active = 1 AND u.active = 1 AND JSON_CONTAINS(m.roles, :role) ORDER BY u.email',
                ['event' => Uuid::fromString($eventId)->toBinary(), 'role' => json_encode(MunicipalityRole::MunicipalityAdmin->value, \JSON_THROW_ON_ERROR)],
            ));
            if ([] === $recipients) {
                return;
            }
            $email = (new Email())->from($this->from)->subject(sprintf('Civipass — événement clôturé par l’éditeur : %s', $reference));
            foreach ($recipients as $address) {
                $email->addTo($address);
            }
            $this->mailer->send($email->text(sprintf(
                "Bonjour,\n\nL'événement « %s » (%s) a été clôturé à titre exceptionnel par l'éditeur de la plateforme Civipass.\n\n"
                ."La clôture est définitive : les laissez-passer ne donnent plus aucun droit, le contrôle affiche « Événement terminé » et les participations sont clôturées.\n"
                ."Pour toute question, contactez le support Civipass. Cet e-mail ne contient aucune donnée personnelle.\n",
                $eventName,
                $reference,
            )));
        } catch (\Throwable $e) {
            $this->logger->error('Notification de clôture exceptionnelle non envoyée.', ['event' => $eventId, 'error' => $e::class]);
        }
    }
}
