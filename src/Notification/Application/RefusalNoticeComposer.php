<?php

declare(strict_types=1);

namespace App\Notification\Application;

use App\Request\Application\Read\RefusalNoticeFacts;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Uid\Uuid;

/**
 * Message d'information d'un demandeur refusé (LOT 08.5, DM-136). Contenu MINIMAL : référence, événement, commune, motif du référentiel et invitation à contacter la
 * mairie. Jamais : le commentaire libre, un nom, une adresse postale, une plaque, un lien, un QR. Pour les motifs « doublon » et « autre », AUCUN libellé de motif
 * (ne jamais révéler l'existence d'une autre demande ni un texte libre, l'adresse n'étant pas vérifiée). Le Message-ID dérive de l'identifiant de la ligne (identique
 * à chaque réessai).
 *
 * LIBELLÉ PROVISOIRE : le texte juridique définitif doit être validé avant tout envoi réel (DM-136) ; l'envoi reste désactivé tant que `REFUSAL_NOTICE_ENABLED` vaut 0.
 */
final readonly class RefusalNoticeComposer
{
    /** Motifs dont le libellé n'est JAMAIS communiqué : formulation générique. */
    public const SILENT_REASON_CODES = ['duplicate', 'other'];

    public function __construct(
        #[Autowire('%env(MAILER_FROM)%')]
        private string $sender,
    ) {
    }

    /**
     * @throws \Symfony\Component\Mime\Exception\RfcComplianceException adresse du destinataire invalide
     */
    public function compose(Uuid $noticeId, RefusalNoticeFacts $facts): Email
    {
        $email = (new Email())
            ->from($this->sender)
            ->to(new Address($facts->recipient))
            ->subject(sprintf('Votre demande %s : décision de la commune de %s', $facts->reference, $facts->municipalityName))
            ->text($this->body($facts));
        $email->getHeaders()->addIdHeader('Message-ID', 'refusal-'.$noticeId->toRfc4122().'@civipass.invalid');

        return $email;
    }

    public function body(RefusalNoticeFacts $facts): string
    {
        $lines = [
            'Bonjour,',
            '',
            sprintf('Votre demande %s pour l’événement « %s » n’a pas pu être acceptée par la commune de %s.', $facts->reference, $facts->eventName, $facts->municipalityName),
        ];
        if (null !== $facts->reasonLabel && null !== $facts->reasonCode && !\in_array($facts->reasonCode, self::SILENT_REASON_CODES, true)) {
            $lines[] = sprintf('Motif : %s.', $facts->reasonLabel);
        }
        $contact = array_filter([$facts->municipalityContactPhone, $facts->municipalityContactEmail]);
        $lines[] = '';
        $lines[] = sprintf('Pour toute question, contactez la mairie de %s en indiquant la référence %s%s.', $facts->municipalityName, $facts->reference, [] === $contact ? '' : ' ('.implode(' · ', $contact).')');
        $lines[] = '';
        $lines[] = 'Ce message est envoyé automatiquement : merci de ne pas y répondre. Il ne contient ni lien ni laissez-passer.';

        return implode("\n", $lines)."\n";
    }
}
