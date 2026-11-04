<?php

declare(strict_types=1);

namespace App\Onboarding\Application;

use App\Identity\Domain\EmailAddress;
use App\Onboarding\Domain\DelegationResult;
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
use App\Shared\Domain\OperationNotAllowed;
use Psr\Clock\ClockInterface;

/**
 * Délégation : le destinataire de l'invitation (lien de CONTACT) désigne l'adresse e-mail de la personne qui sera administrateur
 * communal ; un lien PERSONNEL lui est envoyé. Possible seulement si la commune n'a AUCUN administrateur effectif : un lien ne
 * donne jamais d'accès à une commune déjà administrée. Aucun droit n'est accordé ici : seule l'inscription par le lien
 * personnel, après vérification (AdministratorEnrollment), crée l'appartenance. Un nouvel envoi remplace le précédent.
 */
final readonly class AdministratorDelegation
{
    public function __construct(
        private InvitationLinkResolver $resolver,
        private InvitationLinkRepository $links,
        private InvitationNotifier $notifier,
        private InvitationDeliveryRecorder $delivery,
        private TransactionRunner $transaction,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws DomainRuleViolation adresse e-mail invalide
     * @throws OperationNotAllowed lien inutilisable, lien d'un autre type, commune déjà administrée
     */
    public function delegate(string $plainContactToken, string $administratorEmail): DelegationResult
    {
        $resolution = $this->resolver->resolve($plainContactToken);
        $contact = $resolution->link;
        if (!$resolution->isUsable() || null === $contact || InvitationLinkKind::Contact !== $contact->kind() || $resolution->communeAdministered) {
            throw new OperationNotAllowed('Ce lien ne permet pas cette action.');
        }
        $address = EmailAddress::fromString($administratorEmail);

        $token = InvitationToken::generate();
        $now = $this->clock->now();
        $contactId = $contact->id();
        $link = $this->transaction->run(function () use ($contactId, $address, $token, $now): InvitationLink {
            $contact = $this->links->find($contactId) ?? throw new OperationNotAllowed('Ressource inaccessible.');
            $participation = $contact->participation();
            $this->links->revokeOpen($participation->id(), InvitationLinkKind::Administrator, $now);
            $link = new InvitationLink($participation, InvitationLinkKind::Administrator, $address->value, $token->hash(), $now, null, $contact);
            $this->links->add($link);
            $this->audit->record(new AuditEvent($now, AuditAction::InvitationAdministratorDelegated, AuditSubjectType::InvitationLink, $link->id(), $participation->municipality()->id(), AuditActor::system(), null, [
                'participationId' => $participation->id()->toRfc4122(),
                'contact' => InvitationDispatcher::mask($contact->recipientEmail()),
                'administrator' => InvitationDispatcher::mask($address->value),
                'expiresAt' => $link->expiresAt()->format(\DATE_ATOM),
            ]));

            return $link;
        });

        $context = new InvitationEmailContext($resolution->organizerName, $resolution->eventName, $resolution->communeName, $resolution->message, $link->expiresAt());
        $sent = $this->delivery->sendAndRecord($link, AuditActor::system(), fn () => $this->notifier->sendAdministratorInvitation($link, $token->plain, $context, InvitationDispatcher::mask($contact->recipientEmail())));

        return new DelegationResult(InvitationDispatcher::mask($address->value), $sent);
    }
}
