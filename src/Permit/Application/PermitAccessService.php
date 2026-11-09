<?php

declare(strict_types=1);

namespace App\Permit\Application;

use App\Event\Application\Support\EventLocker;
use App\Event\Application\Support\EventTransactions;
use App\Event\Domain\ParticipationStatus;
use App\Identity\Domain\User;
use App\Municipality\Application\MunicipalityContext;
use App\Municipality\Domain\MunicipalityMembership;
use App\Municipality\Domain\MunicipalityRole;
use App\Permit\Application\Read\AccessLinkView;
use App\Permit\Application\Read\AccessOverview;
use App\Permit\Application\Result\IssuedAccessLink;
use App\Permit\Domain\AccessExpiry;
use App\Permit\Domain\AccessSecret;
use App\Permit\Domain\ManualNotificationOutcome;
use App\Permit\Domain\Permit;
use App\Permit\Domain\PermitAccessToken;
use App\Permit\Domain\PermitAccessTokenRepository;
use App\Permit\Domain\PermitAuthorization;
use App\Permit\Domain\PermitRepository;
use App\Request\Domain\DecisionPath;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditActor;
use App\Shared\Domain\AuditSubjectType;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationConflict;
use App\Shared\Domain\OperationNotAllowed;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Remise du lien bénéficiaire par un agent (LOT 05.2, DM-114) : voir, émettre, révoquer. Aucune notification (LOT 05.3).
 *
 * Droits : adhésion effective et ACTIVE à la commune (écriture), rôle INSTRUCTOR ou MUNICIPALITY_ADMIN — ou EVENT_MANAGER, pour le seul
 * laissez-passer qu'il a lui-même délivré par DÉLIVRANCE DIRECTE (matrice de lot-05-schema.md § 6) ; tout autre cas : 403 — et commune
 * CONCERNÉE par le laissez-passer, c'est-à-dire titulaire d'une de ses autorisations (demande inconnue, sans laissez-passer ou d'une autre
 * commune : même refus uniforme, 404). L'émission et la révocation exigent en plus une participation ACTIVE, relue sous verrou ; une
 * participation suspendue ou clôturée reste en consultation. Aucune confiance dans les identifiants de l'URL.
 *
 * Un secret n'est connu que de ce service au moment de l'émission : seule son empreinte est écrite. « Retrouver » un lien est impossible
 * par construction ; en cas de perte, on en émet un nouveau (les précédents restent actifs jusqu'à révocation explicite). Verrous (DT-024) :
 * événement (partagé) → participation → ligne du lien ; aucun compteur, aucun autre verrou ensuite. L'audit est écrit dans la même
 * transaction, sans secret ni empreinte.
 */
final readonly class PermitAccessService
{
    public function __construct(
        private EventTransactions $transactions,
        private EventLocker $locker,
        private ClockInterface $clock,
        private MunicipalityContext $context,
        private PermitRepository $permits,
        private PermitAccessTokenRepository $tokens,
        private AuditTrail $audit,
        private NotificationStatusProvider $notifications,
        private ManualNotificationRequester $manualNotifications,
        private SystemAccessLinks $links,
    ) {
    }

    /**
     * @throws OperationNotAllowed commune ou demande inaccessible
     * @throws PermitAccessDenied  rôle INSTRUCTOR ou MUNICIPALITY_ADMIN absent
     */
    public function overview(User $agent, string $municipalityId, string $requestId): AccessOverview
    {
        [$membership, $permit, $authorization] = $this->authorize($agent, $municipalityId, $requestId);
        $own = $membership->municipality()->id();
        $now = $this->clock->now();
        $links = array_map(
            fn (PermitAccessToken $t): AccessLinkView => new AccessLinkView(
                $t->id()->toRfc4122(),
                $t->createdAt(),
                AccessExpiry::of($permit->event()),
                $t->revokedAt(),
                $t->isRevoked() ? AccessLinkView::REVOKED : ($t->isExpiredAt($now) ? AccessLinkView::EXPIRED : AccessLinkView::ACTIVE),
                $t->issuedByMunicipalityId()?->equals($own) ?? false,
                $t->isIssuedBySystem(),
            ),
            $this->tokens->findByPermit($permit->id()),
        );
        $own = array_map(static fn (PermitAuthorization $a): Uuid => $a->id(), array_values(array_filter($permit->authorizations(), static fn (PermitAuthorization $a): bool => $a->municipality()->id()->equals($membership->municipality()->id()))));
        $status = $authorization->participation()->status();
        $reason = ParticipationStatus::Active === $status ? '' : sprintf('La participation de %s à cet événement n’est pas active : les liens sont consultables, mais il n’est plus possible d’en émettre ni d’en révoquer.', $membership->municipality()->name());

        return new AccessOverview(
            $requestId,
            $permit->reference()->value,
            $permit->request()->reference()->value,
            $permit->event()->name(),
            $permit->event()->timezone()->identifier(),
            $permit->request()->lastName(),
            $permit->request()->firstName(),
            $links,
            '' === $reason,
            $reason,
            $this->notifications->overviewFor($permit->id(), $own),
            '' !== trim((string) $permit->request()->email()),
        );
    }

    /**
     * Émet un NOUVEAU lien ; les liens existants ne sont pas révoqués.
     *
     * @throws OperationNotAllowed     commune ou demande inaccessible
     * @throws PermitAccessDenied      rôle absent
     * @throws PermitAccessUnavailable participation de la commune non ACTIVE (relue sous verrou)
     * @throws OperationConflict       conflit technique (verrou, interblocage) : rien n'est écrit, réessayable
     * @throws DomainRuleViolation     expiration (fin effective + 30 jours) déjà dépassée : un lien ne serait jamais utilisable
     */
    public function issue(User $agent, string $municipalityId, string $requestId): IssuedAccessLink
    {
        [, $permit, $authorization] = $this->authorize($agent, $municipalityId, $requestId);

        return $this->transactions->run(function () use ($agent, $municipalityId, $permit, $authorization): IssuedAccessLink {
            $participation = $this->locker->participation($authorization->participation()->id(), $this->locker->sharedEvent($permit->event()->id()));
            if (ParticipationStatus::Active !== $participation->status()) {
                throw new PermitAccessUnavailable($participation->status()->value);
            }
            // Droits relus SOUS verrou (adhésion ou rôles modifiés depuis le contrôle initial).
            $membership = $this->context->requireMembership($agent, $municipalityId, write: true);
            $this->assertMay($membership, $authorization, $agent);
            $now = $this->clock->now(); // pris sous verrou (DT-038)
            $expiry = AccessExpiry::of($permit->event());
            if (null !== $expiry && $now >= $expiry) {
                throw new DomainRuleViolation('Cet événement est terminé depuis plus de 30 jours : un nouveau lien ne pourrait plus être utilisé.');
            }
            $secret = AccessSecret::generate();
            $token = new PermitAccessToken($permit, $secret, $now, $expiry, $agent->id(), $membership->municipality()->id());
            $this->tokens->add($token);
            $this->recordAudit(AuditAction::PermitAccessLinkIssued, $permit, $token, $agent, $membership, $now);

            return new IssuedAccessLink($secret->value, $token->id()->toRfc4122(), $expiry);
        });
    }

    /**
     * Demande une NOUVELLE notification de mise à disposition (LOT 05.3.4) : une intention est enregistrée, l'envoi est fait plus tard par le
     * distributeur, jamais dans cette requête. Même périmètre que l'émission d'un lien (rôle, participation ACTIVE relue sous verrou). Aucun lien
     * existant n'est révoqué ; le secret du nouveau lien sera créé à l'envoi (jamais un secret supprimé). Verrous : événement (partagé) →
     * participation → laissez-passer ; le formulaire porte un identifiant (`$nonce`) qui rend le double clic inoffensif, et un seul message de
     * mise à disposition peut être en attente à la fois.
     *
     * @throws OperationNotAllowed     commune ou demande inaccessible
     * @throws PermitAccessDenied      rôle absent
     * @throws PermitAccessUnavailable participation non ACTIVE
     * @throws OperationConflict       conflit technique : rien n'est écrit, réessayable
     * @throws DomainRuleViolation     identifiant de formulaire invalide, laissez-passer inutilisable ou sans adresse e-mail
     */
    public function requestNotification(User $agent, string $municipalityId, string $requestId, string $nonce): ManualNotificationOutcome
    {
        [, $permit, $authorization] = $this->authorize($agent, $municipalityId, $requestId);
        if (!Uuid::isValid($nonce)) {
            throw new DomainRuleViolation('Formulaire invalide : recommencez.');
        }

        return $this->transactions->run(function () use ($agent, $municipalityId, $permit, $authorization, $nonce): ManualNotificationOutcome {
            $participation = $this->locker->participation($authorization->participation()->id(), $this->locker->sharedEvent($permit->event()->id()));
            if (ParticipationStatus::Active !== $participation->status()) {
                throw new PermitAccessUnavailable($participation->status()->value);
            }
            $membership = $this->context->requireMembership($agent, $municipalityId, write: true);
            $this->assertMay($membership, $authorization, $agent);
            $this->permits->lock($permit->id());
            $now = $this->clock->now(); // pris sous verrou (DT-038)
            if ('' === trim((string) $permit->request()->email())) {
                throw new DomainRuleViolation('Aucune adresse e-mail n’est enregistrée pour ce bénéficiaire : remettez le lien en main propre.');
            }
            if (!$this->links->isUsable($permit->id(), $now)) {
                throw new DomainRuleViolation('Ce laissez-passer n’est plus utilisable : aucune notification n’est envoyée.');
            }
            $outcome = $this->manualNotifications->request($permit->id(), Uuid::fromString($nonce), $now);
            if (ManualNotificationOutcome::Created === $outcome) {
                // Jamais d'adresse ni de secret (DT-037) : qui, quand, pour quel laissez-passer.
                $this->audit->record(new AuditEvent($now, AuditAction::PermitNotificationRequested, AuditSubjectType::Permit, $permit->id(), $membership->municipality()->id(), AuditActor::user($agent->id()), null, [
                    'reference' => $permit->reference()->value,
                    'municipality' => $membership->municipality()->id()->toRfc4122(),
                ]));
            }

            return $outcome;
        });
    }

    /**
     * Révoque UN lien (les autres ne sont pas touchés). Idempotent : un lien déjà révoqué (même par un autre agent, simultanément) ne produit ni
     * erreur ni second audit. Retourne vrai si CET appel a révoqué le lien.
     *
     * @throws OperationNotAllowed     commune, demande ou lien inaccessible (lien d'un autre laissez-passer : même refus)
     * @throws PermitAccessDenied      rôle absent
     * @throws PermitAccessUnavailable participation de la commune non ACTIVE
     * @throws OperationConflict       conflit technique (verrou, interblocage) : rien n'est écrit, réessayable
     */
    public function revoke(User $agent, string $municipalityId, string $requestId, string $linkId): bool
    {
        [, $permit, $authorization] = $this->authorize($agent, $municipalityId, $requestId);
        if (!Uuid::isValid($linkId)) {
            throw new OperationNotAllowed('Ressource inaccessible.');
        }

        return $this->transactions->run(function () use ($agent, $municipalityId, $permit, $authorization, $linkId): bool {
            $participation = $this->locker->participation($authorization->participation()->id(), $this->locker->sharedEvent($permit->event()->id()));
            if (ParticipationStatus::Active !== $participation->status()) {
                throw new PermitAccessUnavailable($participation->status()->value);
            }
            $token = $this->tokens->lockForPermit(Uuid::fromString($linkId), $permit->id()) ?? throw new OperationNotAllowed('Ressource inaccessible.');
            $membership = $this->context->requireMembership($agent, $municipalityId, write: true);
            $this->assertMay($membership, $authorization, $agent);
            if ($token->isRevoked()) {
                return false;
            }
            $now = $this->clock->now();
            $token->revoke($agent->id(), $now);
            $this->recordAudit(AuditAction::PermitAccessLinkRevoked, $permit, $token, $agent, $membership, $now);

            return true;
        });
    }

    /**
     * @return array{MunicipalityMembership, Permit, PermitAuthorization}
     */
    private function authorize(User $agent, string $municipalityId, string $requestId): array
    {
        $membership = $this->context->requireMembership($agent, $municipalityId, write: true);
        if (!self::mayHandleAnyPermit($membership) && !$membership->hasRole(MunicipalityRole::EventManager)) {
            throw new PermitAccessDenied('Rôle INSTRUCTOR, MUNICIPALITY_ADMIN ou EVENT_MANAGER (émetteur) requis.');
        }
        $permit = Uuid::isValid($requestId) ? $this->permits->findByRequest(Uuid::fromString($requestId)) : null;
        $own = $membership->municipality()->id();
        foreach (null === $permit ? [] : $permit->authorizations() as $authorization) {
            if ($authorization->municipality()->id()->equals($own)) {
                $this->assertMay($membership, $authorization, $agent);

                return [$membership, $permit, $authorization];
            }
        }

        throw new OperationNotAllowed('Ressource inaccessible.');
    }

    private static function mayHandleAnyPermit(MunicipalityMembership $membership): bool
    {
        return $membership->hasRole(MunicipalityRole::Instructor) || $membership->hasRole(MunicipalityRole::MunicipalityAdmin);
    }

    /** INSTRUCTOR et MUNICIPALITY_ADMIN : tout laissez-passer de la commune ; EVENT_MANAGER : seulement ce qu'il a délivré directement. */
    private function assertMay(MunicipalityMembership $membership, PermitAuthorization $authorization, User $agent): void
    {
        if (self::mayHandleAnyPermit($membership)) {
            return;
        }
        $decision = $authorization->decision();
        if ($membership->hasRole(MunicipalityRole::EventManager) && DecisionPath::DirectIssuance === $decision->path() && $decision->decidedBy()->id()->equals($agent->id())) {
            return;
        }

        throw new PermitAccessDenied('Cet agent ne peut pas gérer les liens de ce laissez-passer.');
    }

    private function recordAudit(AuditAction $action, Permit $permit, PermitAccessToken $token, User $agent, MunicipalityMembership $membership, \DateTimeImmutable $now): void
    {
        // Jamais le secret, jamais son empreinte, jamais l'URL (DT-037, DT-048).
        $this->audit->record(new AuditEvent(
            $now,
            $action,
            AuditSubjectType::Permit,
            $permit->id(),
            $membership->municipality()->id(),
            AuditActor::user($agent->id()),
            null,
            [
                'reference' => $permit->reference()->value,
                'link' => $token->id()->toRfc4122(),
                'municipality' => $membership->municipality()->id()->toRfc4122(),
                'expiresAt' => $token->expiresAt()?->format(\DATE_ATOM),
            ],
        ));
    }
}
