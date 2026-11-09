<?php

declare(strict_types=1);

namespace App\Permit\Application;

use App\Permit\Application\Result\IssuedAccessLink;
use App\Permit\Domain\AccessExpiry;
use App\Permit\Domain\AccessSecret;
use App\Permit\Domain\Permit;
use App\Permit\Domain\PermitAccessToken;
use App\Permit\Domain\PermitAccessTokenRepository;
use App\Permit\Domain\PermitRepository;
use App\Permit\Domain\RightState;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditActor;
use App\Shared\Domain\AuditSubjectType;
use Symfony\Component\Uid\Uuid;

/**
 * Liens bénéficiaires créés et révoqués par le SYSTÈME pour la notification automatique (LOT 05.3.2, DM-118, D12). Seul contrat de Permit
 * que le module Notification appelle en dehors du port d'enregistrement.
 *
 * N'ouvre aucune transaction : l'appelant (Notification) l'exécute dans la sienne, pour que le lien, le secret chiffré et l'état du message
 * soient écrits ou annulés ensemble. Le secret n'est retourné qu'en mémoire, à chiffrer aussitôt ; il n'apparaît dans aucun audit.
 */
final readonly class SystemAccessLinks
{
    /** États qui ne reviendront pas : si toutes les zones y sont, le laissez-passer n'est plus utilisable. */
    private const TERMINAL = [RightState::Expired, RightState::Revoked, RightState::EventClosed, RightState::ZoneNotAuthorized];

    public function __construct(
        private PermitRepository $permits,
        private PermitAccessTokenRepository $tokens,
        private PermitRightsEvaluator $evaluator,
        private AuditTrail $audit,
    ) {
    }

    /**
     * Le laissez-passer peut-il encore être utilisé, donc l'annonce de sa mise à disposition est-elle encore vraie ? Faux s'il n'existe plus,
     * si le délai d'accès au lien est dépassé, ou si toutes ses zones sont dans un état définitif (expirée, révoquée, événement clos).
     */
    public function isUsable(Uuid $permitId, \DateTimeImmutable $now): bool
    {
        $permit = $this->permits->find($permitId);

        return null !== $permit && $this->usable($permit, $now);
    }

    /** Le lien existe-t-il encore, non révoqué (par un agent ou le système) et non expiré ? Lecture sans verrou, avant un envoi. */
    public function isLinkActive(Uuid $linkId, \DateTimeImmutable $now): bool
    {
        $token = $this->tokens->find($linkId);

        return null !== $token && $token->isUsableAt($now);
    }

    /**
     * Crée un lien émis par le système, ou `null` si le laissez-passer n'est plus utilisable (aucune écriture dans ce cas).
     */
    public function issue(Uuid $permitId, \DateTimeImmutable $now): ?IssuedAccessLink
    {
        $permit = $this->permits->find($permitId);
        if (null === $permit || !$this->usable($permit, $now)) {
            return null;
        }
        $expiry = AccessExpiry::of($permit->event());
        $secret = AccessSecret::generate();
        $token = PermitAccessToken::issuedBySystem($permit, $secret, $now, $expiry);
        $this->tokens->add($token);
        $this->recordAudit(AuditAction::PermitAccessLinkIssued, $permit, $token, $now);

        return new IssuedAccessLink($secret->value, $token->id()->toRfc4122(), $expiry);
    }

    /**
     * Révoque un lien automatique jamais transmis. Idempotent ; retourne vrai si CET appel l'a révoqué. Ne touche jamais un lien émis par un agent.
     */
    public function revoke(Uuid $linkId, \DateTimeImmutable $now): bool
    {
        $token = $this->tokens->lock($linkId);
        if (null === $token || !$token->isIssuedBySystem() || $token->isRevoked()) {
            return false;
        }
        $token->revokeBySystem($now);
        $this->recordAudit(AuditAction::PermitAccessLinkRevoked, $token->permit(), $token, $now);

        return true;
    }

    private function usable(Permit $permit, \DateTimeImmutable $now): bool
    {
        $expiry = AccessExpiry::of($permit->event());
        if (null !== $expiry && $now >= $expiry) {
            return false;
        }
        foreach ($this->evaluator->evaluate($permit, $now)->zones() as $zone) {
            if (!\in_array($zone->state, self::TERMINAL, true)) {
                return true;
            }
        }

        return false;
    }

    private function recordAudit(AuditAction $action, Permit $permit, PermitAccessToken $token, \DateTimeImmutable $now): void
    {
        // Jamais le secret, jamais son empreinte, jamais l'URL (DT-037, DT-048). Commune : la première autorisation, pour rester visible d'une commune.
        $first = $permit->authorizations()[0] ?? null;
        $this->audit->record(new AuditEvent(
            $now,
            $action,
            AuditSubjectType::Permit,
            $permit->id(),
            $first?->municipality()->id(),
            AuditActor::system(),
            null,
            [
                'reference' => $permit->reference()->value,
                'link' => $token->id()->toRfc4122(),
                'issuer' => 'SYSTEM',
                'expiresAt' => $token->expiresAt()?->format(\DATE_ATOM),
            ],
        ));
    }
}
