<?php

declare(strict_types=1);

namespace App\Onboarding\Application;

use App\Event\Application\Command\InviteMunicipalityCommand;
use App\Event\Application\EventPermission;
use App\Event\Application\EventResourceResolver;
use App\Event\Application\Service\EventParticipationService;
use App\Event\Domain\EventParticipationRepository;
use App\Event\Domain\ParticipationStatus;
use App\Identity\Domain\EmailAddress;
use App\Identity\Domain\User;
use App\Municipality\Application\MunicipalityRegistrar;
use App\Municipality\Domain\InseeCode;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRepository;
use App\Municipality\Domain\NationalCommuneRepository;
use App\Onboarding\Domain\InvitationDispatchResult;
use App\Onboarding\Domain\InvitationEmailContext;
use App\Onboarding\Domain\InvitationLink;
use App\Onboarding\Domain\InvitationLinkKind;
use App\Onboarding\Domain\InvitationLinkRepository;
use App\Onboarding\Domain\InvitationToken;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Application\TransactionRunner;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditActor;
use App\Shared\Domain\AuditSubjectType;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationConflict;
use App\Shared\Domain\OperationNotAllowed;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Invitation d'une commune par l'organisatrice : sélection dans le référentiel national, adresse e-mail de contact LIBRE
 * (pas nécessairement celle du futur administrateur, aucun domaine imposé), e-mail contenant un lien sécurisé.
 *
 * Aucune règle métier dupliquée : l'enregistrement de l'invitation est EventParticipationService::invite (droits, éligibilité,
 * unicité, audit, DM-084/086/089). Ce service ajoute seulement, dans cet ordre :
 *   1. le droit d'inviter est vérifié AVANT toute écriture (aucune commune n'est inscrite pour un utilisateur sans droit) ;
 *   2. la commune du référentiel est inscrite si elle ne l'est pas (MunicipalityRegistrar : sans droit commercial) ;
 *   3. l'invitation est enregistrée ; 4. un lien sécurisé est émis (jeton haché) ; 5. l'e-mail est envoyé.
 * L'invitation n'est JAMAIS perdue ni dupliquée par un échec d'e-mail : elle est enregistrée avant l'envoi, l'échec est noté
 * (statut FAILED, audit) et « Renvoyer l'invitation » émet un nouveau lien qui remplace l'ancien.
 */
final readonly class InvitationDispatcher
{
    public function __construct(
        private EventResourceResolver $resolver,
        private EventParticipationService $participations,
        private EventParticipationRepository $participationRepository,
        private MunicipalityRepository $municipalities,
        private NationalCommuneRepository $nationalCommunes,
        private MunicipalityRegistrar $registrar,
        private InvitationLinkRepository $links,
        private InvitationNotifier $notifier,
        private TransactionRunner $transaction,
        private AuditTrail $audit,
        private ClockInterface $clock,
        private InvitationDeliveryRecorder $delivery,
    ) {
    }

    /**
     * @throws DomainRuleViolation adresse e-mail invalide
     * @throws OperationNotAllowed droit insuffisant, événement inaccessible, commune introuvable ou indisponible, déjà invitée…
     * @throws OperationConflict   verrou non obtenu à temps (rien n'est enregistré, nouvelle tentative explicite)
     */
    public function invite(User $actor, string $eventId, string $inseeCode, string $contactEmail, ?string $message): InvitationDispatchResult
    {
        $address = EmailAddress::fromString($contactEmail);
        $code = self::inseeCode($inseeCode);
        $event = $this->resolver->event($actor, $eventId, EventPermission::InviteMunicipality); // droit d'inviter, AVANT toute écriture

        $municipality = $this->municipalities->findByInseeCode($code);
        $registered = false;
        if (null === $municipality) {
            $commune = $this->nationalCommunes->findByInseeCode($code);
            if (null === $commune || !$commune->isActive()) {
                throw new OperationNotAllowed('Commune introuvable ou indisponible.');
            }
            $municipality = $this->registrar->registerFromReferential($commune, $actor);
            $registered = true;
        }

        $invitation = $this->participations->invite($actor, new InviteMunicipalityCommand($event->id()->toRfc4122(), $municipality->id()->toRfc4122(), $message));

        return $this->issueAndSend($actor, $invitation->participationId, $address, $message, $municipality, $registered);
    }

    /**
     * Renvoie l'invitation d'une participation encore `INVITED` : le moteur enregistre la demande de réexpédition, puis un
     * NOUVEAU lien remplace l'ancien (révoqué). L'adresse peut être corrigée ; à défaut, celle du dernier envoi.
     *
     * @throws DomainRuleViolation adresse invalide ou absente
     * @throws OperationNotAllowed
     * @throws OperationConflict
     */
    public function resend(User $actor, string $eventId, string $participationId, ?string $contactEmail): InvitationDispatchResult
    {
        $event = $this->resolver->event($actor, $eventId, EventPermission::InviteMunicipality);
        $participation = Uuid::isValid($participationId) ? $this->participationRepository->find(Uuid::fromString($participationId)) : null;
        if (null === $participation || !$participation->event()->id()->equals($event->id()) || ParticipationStatus::Invited !== $participation->status()) {
            throw new OperationNotAllowed('Ressource inaccessible.');
        }
        $previous = $this->links->latestContact($participation->id());
        $email = '' !== trim((string) $contactEmail) ? $contactEmail : $previous?->recipientEmail();
        if (null === $email) {
            throw new DomainRuleViolation('Indiquez l\'adresse e-mail du destinataire de l\'invitation.');
        }
        $address = EmailAddress::fromString($email);

        $this->participations->resendInvitation($actor, $participationId);

        return $this->issueAndSend($actor, $participationId, $address, $participation->invitationMessage(), $participation->municipality(), false);
    }

    private function issueAndSend(User $actor, string $participationId, EmailAddress $address, ?string $message, Municipality $municipality, bool $registered): InvitationDispatchResult
    {
        $token = InvitationToken::generate();
        $actorId = $actor->id();
        $now = $this->clock->now();
        $link = $this->transaction->run(function () use ($participationId, $address, $token, $actor, $actorId, $now): InvitationLink {
            $participation = $this->participationRepository->find(Uuid::fromString($participationId)) ?? throw new OperationNotAllowed('Ressource inaccessible.');
            $this->links->revokeOpen($participation->id(), InvitationLinkKind::Contact, $now);
            $link = new InvitationLink($participation, InvitationLinkKind::Contact, $address->value, $token->hash(), $now, $actor);
            $this->links->add($link);
            $this->audit->record(new AuditEvent($now, AuditAction::InvitationLinkIssued, AuditSubjectType::InvitationLink, $link->id(), $participation->municipality()->id(), AuditActor::user($actorId), null, ['kind' => InvitationLinkKind::Contact->value, 'participationId' => $participation->id()->toRfc4122(), 'recipient' => self::mask($address->value), 'expiresAt' => $link->expiresAt()->format(\DATE_ATOM)]));

            return $link;
        });

        $participation = $link->participation();
        $context = new InvitationEmailContext($participation->event()->organizerMunicipality()->name(), $participation->event()->name(), $municipality->name(), $message, $link->expiresAt());
        $sent = $this->delivery->sendAndRecord($link, AuditActor::user($actorId), fn () => $this->notifier->sendContactInvitation($link, $token->plain, $context));

        return new InvitationDispatchResult($participationId, $municipality->name(), $registered, $sent, self::mask($address->value));
    }

    private static function inseeCode(string $raw): InseeCode
    {
        try {
            return InseeCode::fromString($raw);
        } catch (DomainRuleViolation) {
            throw new OperationNotAllowed('Commune introuvable ou indisponible.');
        }
    }

    /** « c***@domaine » : suffisant pour reconnaître son adresse, insuffisant pour la deviner. */
    public static function mask(string $email): string
    {
        $at = strrpos($email, '@');
        if (false === $at || 0 === $at) {
            return '***';
        }

        return mb_substr($email, 0, 1).'***'.substr($email, $at);
    }
}
