<?php

declare(strict_types=1);

namespace App\Permit\Application;

use App\Event\Application\Support\EventTransactions;
use App\Identity\Domain\User;
use App\Permit\Application\Result\RevocationOutcome;
use App\Permit\Domain\NotificationIntent;
use App\Permit\Domain\NotificationKind;
use App\Permit\Domain\PermitAuthorization;
use App\Permit\Domain\PermitRevision;
use App\Permit\Domain\PermitRevisionRepository;
use App\Permit\Domain\RevocationReason;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditActor;
use App\Shared\Domain\AuditSubjectType;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationConflict;
use App\Shared\Domain\OperationNotAllowed;
use Psr\Clock\ClockInterface;

/**
 * Révocation d'UNE autorisation communale (LOT 05.5.1, DM-116 §2, DM-119 §4.1). Une commune ne révoque que SA propre autorisation (le garde ne
 * connaît que celle-là) ; les autres communes gardent leurs droits ; la décision initiale n'est jamais modifiée ; la révocation est IRRÉVERSIBLE
 * (aucune méthode inverse, toute révision ultérieure de cette autorisation est refusée) et le QR ne change pas.
 *
 * Motif obligatoire (catalogue `RevocationReason`), commentaire de 200 caractères au plus, obligatoire pour « Autre motif » ; confirmation
 * explicite. Dans UNE transaction : autorisation révoquée, révision immuable, audit, intention de notification prioritaire. Le motif et le
 * commentaire ne sortent jamais : ni audit (le code seulement), ni notification, ni page publique.
 *
 * Idempotente : une autorisation déjà révoquée (double clic, requête concurrente perdante) renvoie `AlreadyRevoked` sans écrire de révision, d'audit ni de
 * notification supplémentaires.
 */
final readonly class AuthorizationRevocationService
{
    public function __construct(
        private EventTransactions $transactions,
        private PermitRevisionGuard $guard,
        private PermitRevisionRepository $revisions,
        private ClockInterface $clock,
        private AuditTrail $audit,
        private NotificationIntentRecorder $notifications,
    ) {
    }

    /**
     * @throws OperationNotAllowed     commune, demande ou laissez-passer inaccessible
     * @throws PermitAccessDenied      rôle absent
     * @throws PermitAccessUnavailable participation de la commune non ACTIVE (relue sous verrou)
     * @throws DomainRuleViolation     motif absent ou inconnu, commentaire trop long ou manquant, confirmation absente
     * @throws OperationConflict       conflit technique (verrou, interblocage) : rien n'est écrit, réessayable
     */
    public function revoke(User $agent, string $municipalityId, string $requestId, string $reasonCode, ?string $comment, bool $confirmed): RevocationOutcome
    {
        $scope = $this->guard->locate($agent, $municipalityId, $requestId, write: true);
        $reason = RevocationReason::tryFrom($reasonCode) ?? throw new DomainRuleViolation('Choisissez un motif de révocation.');
        $comment = self::validComment($reason, $comment);
        if (!$confirmed) {
            throw new DomainRuleViolation('Confirmez la révocation : elle est définitive.');
        }

        return $this->transactions->run(function () use ($agent, $municipalityId, $scope, $reason, $comment): RevocationOutcome {
            $membership = $this->guard->lock($scope, $agent, $municipalityId);
            $authorization = $scope->authorization;
            if ($authorization->isRevoked()) {
                return RevocationOutcome::AlreadyRevoked;
            }
            $now = $this->clock->now(); // pris sous verrou (DT-038)
            $before = self::state($authorization);
            $authorization->revoke($now);
            $revision = PermitRevision::revocation($authorization, $agent, $reason, $comment, $now, $before, self::state($authorization));
            $this->revisions->add($revision);
            // Jamais le commentaire, jamais un jeton ni une donnée personnelle (DT-037, DM-113 §2) : le code du motif seulement.
            $this->audit->record(new AuditEvent(
                $now,
                AuditAction::PermitAuthorizationRevoked,
                AuditSubjectType::Permit,
                $scope->permit->id(),
                $membership->municipality()->id(),
                AuditActor::user($agent->id()),
                null,
                [
                    'reference' => $scope->permit->reference()->value,
                    'authorization' => $authorization->id()->toRfc4122(),
                    'revision' => $revision->id()->toRfc4122(),
                    'municipality' => $membership->municipality()->id()->toRfc4122(),
                    'reason' => $reason->value,
                ],
            ));
            // Intention écrite dans la même transaction, sous le verrou du laissez-passer (contrat du port).
            $this->notifications->record(new NotificationIntent(
                NotificationKind::AuthorizationRevoked,
                $scope->permit->id(),
                $authorization->id(),
                $now,
                '' !== trim((string) $scope->permit->request()->email()),
                $revision->id(),
            ));

            return RevocationOutcome::Revoked;
        });
    }

    private static function validComment(RevocationReason $reason, ?string $comment): ?string
    {
        $comment = null === $comment ? '' : trim($comment);
        if (mb_strlen($comment) > PermitRevision::COMMENT_MAX_LENGTH) {
            throw new DomainRuleViolation(sprintf('Le commentaire ne peut pas dépasser %d caractères.', PermitRevision::COMMENT_MAX_LENGTH));
        }
        if ('' === $comment) {
            if ($reason->needsComment()) {
                throw new DomainRuleViolation('Un commentaire est obligatoire pour « Autre motif ».');
            }

            return null;
        }

        return $comment;
    }

    /**
     * État structuré de l'autorisation (aucune donnée personnelle).
     *
     * @return array<string, scalar|list<scalar>|null>
     */
    private static function state(PermitAuthorization $authorization): array
    {
        return [
            'revokedAt' => $authorization->revokedAt()?->format(\DATE_ATOM),
            'durationMode' => $authorization->window()->mode->value,
            'validFrom' => $authorization->window()->from->format(\DATE_ATOM),
            'validUntil' => $authorization->window()->until?->format(\DATE_ATOM),
            'activeZones' => array_map(static fn ($z): string => $z->zone()->id()->toRfc4122(), $authorization->activeZones()),
        ];
    }
}
