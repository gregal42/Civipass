<?php

declare(strict_types=1);

namespace App\Permit\Application;

use App\Event\Application\Support\EventLocker;
use App\Event\Application\Support\EventTransactions;
use App\Identity\Domain\User;
use App\Permit\Application\Result\RevisionScope;
use App\Permit\Application\Result\TokenReplacementRequest;
use App\Permit\Application\Result\TokenReplacementResult;
use App\Permit\Domain\NotificationIntent;
use App\Permit\Domain\NotificationKind;
use App\Permit\Domain\Permit;
use App\Permit\Domain\PermitRepository;
use App\Permit\Domain\PermitRevision;
use App\Permit\Domain\PermitRevisionRepository;
use App\Permit\Domain\RevisionType;
use App\Permit\Domain\TokenReplacementReason;
use App\Permit\Domain\TokenReplacementRules;
use App\Permit\Domain\VerificationToken;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditActor;
use App\Shared\Domain\AuditSubjectType;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationConflict;
use App\Shared\Domain\OperationNotAllowed;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Remplacement du QR code (jeton de vérification) d'un laissez-passer (LOT 05.5.5, DM-113 §4, DM-119 §4.5). Le QR est COMMUN à toutes les communes : un MUNICIPALITY_ADMIN
 * d'une commune ACTIVE qui détient une autorisation NON révoquée le remplace pour toutes, après avoir confirmé cet effet global. Le laissez-passer, sa référence, ses
 * autorisations (zones, périodes), ses plaques, la demande citoyenne et les liens bénéficiaires sont inchangés ; le lien bénéficiaire montre le nouveau QR et un PDF à jour.
 *
 * Atomique, sous le verrou du laissez-passer : l'ancien jeton est retiré (définitivement : HTTP 410) puis le nouveau créé dans la MÊME transaction ; aucune autre transaction
 * ne voit un instant où deux QR sont valides (ni aucun). Idempotence PERSISTANTE : l'identifiant d'opération du formulaire est le `batch_id` de la révision, relu sous verrou ;
 * le même identifiant rejoué par la même commune ne remplace rien et restitue le résultat initial. Verrous : événement (partagé) → participation → laissez-passer → jeton →
 * autorisation (aucune zone, aucune plaque).
 *
 * Abus : jamais de blocage d'une opération légitime. Un second remplacement dans l'heure exige la référence du laissez-passer ressaisie et un motif réel ; l'indicateur
 * « rapproché » est posé par le SERVEUR ; 3 remplacements en 24 h sur le laissez-passer ou 10 par agent : l'audit est marqué « inhabituel » et une ligne structurée est journalisée.
 * Un jeton n'apparaît JAMAIS dans la révision, l'audit, l'intention de notification, le journal, une exception ni un message d'erreur (la révision porte des identifiants de lignes).
 */
final readonly class PermitTokenReplacementService
{
    private const MAX_DRAWS = 5;

    public function __construct(
        private EventTransactions $transactions,
        private EventLocker $locker,
        private PermitRevisionGuard $guard,
        private PermitRevisionRepository $revisions,
        private PermitRepository $permits,
        private ClockInterface $clock,
        private AuditTrail $audit,
        private NotificationIntentRecorder $notifications,
        private ?LoggerInterface $logger = null,
        private ?\Closure $tokenGenerator = null,
    ) {
    }

    /**
     * @throws OperationNotAllowed     commune, demande ou laissez-passer inaccessible (refus uniforme)
     * @throws PermitAccessDenied      rôle MUNICIPALITY_ADMIN absent
     * @throws PermitAccessUnavailable participation de la commune non ACTIVE (relue sous verrou)
     * @throws DomainRuleViolation     refus motivé, motif ou confirmation manquant, commentaire invalide, référence manquante d'un remplacement rapproché
     * @throws OperationConflict       conflit technique : rien n'est écrit, réessayable (avec le MÊME identifiant d'opération)
     */
    public function replace(User $agent, string $municipalityId, string $requestId, TokenReplacementRequest $request): TokenReplacementResult
    {
        $scope = $this->guard->locate($agent, $municipalityId, $requestId, write: true, adminOnly: true);
        $operation = Uuid::isValid($request->operationId) ? Uuid::fromString($request->operationId) : throw new DomainRuleViolation('Identifiant d\'opération invalide : rechargez la page.');
        $reason = TokenReplacementReason::tryFrom((string) $request->reason) ?? throw new DomainRuleViolation('Choisissez le motif du remplacement.');
        $comment = self::comment($request->comment, $reason->needsComment());
        if (!$request->confirmedGlobalEffect) {
            throw new DomainRuleViolation('Confirmez que le QR code de toutes les communes et tous les QR imprimés ou téléchargés cesseront de fonctionner.');
        }

        return $this->transactions->run(function () use ($agent, $municipalityId, $scope, $operation, $reason, $comment, $request): TokenReplacementResult {
            $membership = $this->guard->lock($scope, $agent, $municipalityId, [], adminOnly: true);
            $event = $this->locker->sharedEvent($scope->permit->event()->id());
            $now = self::second($this->clock->now()); // pris sous verrou (DT-038)
            $permit = $scope->permit;
            $recipient = '' !== trim((string) $permit->request()->email());

            // Idempotence : lue SOUS VERROU (lecture verrouillante), avant toute règle d'état.
            $existing = $this->revisions->lockedByBatch($permit->id(), $operation);
            if (null !== $existing) {
                if (RevisionType::TokenReplaced !== $existing->type() || !$existing->municipality()->id()->equals($membership->municipality()->id())) {
                    throw new DomainRuleViolation('Cet identifiant d\'opération a déjà été utilisé : rechargez la page.');
                }
                $state = $existing->afterState() ?? [];

                return new TokenReplacementResult((int) ($state['sequence'] ?? 1), true === ($state['close'] ?? false), true === ($state['unusual'] ?? false), true, $recipient);
            }

            TokenReplacementRules::assertAllowed($scope->authorization->isRevoked(), $event->status());
            $active = $this->permits->lockActiveToken($permit) ?? throw new \LogicException('Un laissez-passer possède toujours un jeton de vérification actif.');
            if (str_contains(strtolower((string) preg_replace('/[\s\-_.]+/', '', (string) $comment)), $active->value())) {
                throw new DomainRuleViolation(self::TOKEN_IN_COMMENT);
            }
            $times = $this->revisions->lockedTokenReplacementTimes($permit->id());
            $close = TokenReplacementRules::isClose($times[0] ?? null, $now);
            TokenReplacementRules::assertReference($close, (string) $request->reference, $permit->reference()->value);
            $since = $now->sub(new \DateInterval(TokenReplacementRules::WINDOW));
            $permitCount = 1 + \count(array_filter($times, static fn (\DateTimeImmutable $t): bool => $t >= $since));
            $agentCount = 1 + $this->revisions->countTokenReplacementsByActorSince($agent->id(), $since);
            $unusual = TokenReplacementRules::isUnusual($permitCount, $agentCount);
            $sequence = \count($times) + 1;

            $candidate = $this->draw();
            // L'ancien jeton est retiré ET écrit avant l'insertion du nouveau (index unique du jeton actif) : dans cette transaction, rien n'est visible avant le commit.
            $active->retire($now);
            $this->permits->flush();
            $new = $permit->addToken($candidate, $now);
            $candidate = '';

            $revision = PermitRevision::token(
                $permit,
                $membership->municipality(),
                $agent,
                $reason->value,
                $comment,
                $now,
                $operation,
                ['sequence' => $sequence - 1, 'token' => $active->id()->toRfc4122()],
                ['sequence' => $sequence, 'token' => $new->id()->toRfc4122(), 'close' => $close, 'unusual' => $unusual],
            );
            $this->revisions->add($revision);
            $this->recordAudit($permit, $scope, $membership->municipality()->id(), $agent, $revision, $reason, $sequence, $close, $unusual, $now);
            $this->recordNeutralAudits($permit, $membership->municipality()->id(), $revision, $now);
            if ($unusual) {
                $this->logger?->warning('permit.token_replacement.unusual', ['permit' => $permit->id()->toRfc4122(), 'permitCount24h' => $permitCount, 'agentCount24h' => $agentCount, 'sequence' => $sequence]);
            }
            $this->notifications->record(new NotificationIntent(
                NotificationKind::QrReplaced,
                $permit->id(),
                null, // le QR appartient au laissez-passer : aucune autorisation (couverture par laissez-passer)
                $now,
                $recipient,
                $revision->id(),
            ));

            return new TokenReplacementResult($sequence, $close, $unusual, false, $recipient);
        });
    }

    private const TOKEN_IN_COMMENT = 'Le commentaire ne doit contenir aucun jeton ni adresse de QR code.';

    /** Tire un jeton qui n'existe pas encore (actif ou retiré) ; collision : nouveau tirage (jusqu'à 5), jamais le jeton dans l'erreur. */
    private function draw(): string
    {
        for ($i = 0; $i < self::MAX_DRAWS; ++$i) {
            $candidate = null === $this->tokenGenerator ? VerificationToken::generate() : ($this->tokenGenerator)();
            if (!$this->permits->tokenExists($candidate)) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Impossible de générer un jeton de vérification unique.');
    }

    private static function comment(?string $comment, bool $required): ?string
    {
        $comment = null === $comment ? '' : trim($comment);
        if (mb_strlen($comment) > PermitRevision::COMMENT_MAX_LENGTH) {
            throw new DomainRuleViolation(sprintf('Le commentaire ne peut pas dépasser %d caractères.', PermitRevision::COMMENT_MAX_LENGTH));
        }
        if ('' === $comment) {
            if ($required) {
                throw new DomainRuleViolation('Un commentaire est obligatoire pour « Autre motif ».');
            }

            return null;
        }
        if (TokenReplacementRules::containsToken($comment)) {
            throw new DomainRuleViolation(self::TOKEN_IN_COMMENT);
        }

        return $comment;
    }

    private function recordAudit(Permit $permit, RevisionScope $scope, Uuid $municipality, User $agent, PermitRevision $revision, TokenReplacementReason $reason, int $sequence, bool $close, bool $unusual, \DateTimeImmutable $now): void
    {
        // Jamais le jeton ni le commentaire (DT-037) : identifiants, numéro d'ordre, code de motif et indicateurs.
        $this->audit->record(new AuditEvent(
            $now,
            AuditAction::PermitTokenReplaced,
            AuditSubjectType::Permit,
            $permit->id(),
            $municipality,
            AuditActor::user($agent->id()),
            null,
            [
                'reference' => $permit->reference()->value,
                'revision' => $revision->id()->toRfc4122(),
                'batch' => $revision->batchId()->toRfc4122(),
                'municipality' => $municipality->toRfc4122(),
                'sequence' => $sequence,
                'reason' => $reason->value,
                'close' => $close,
                'unusual' => $unusual,
            ],
        ));
    }

    /** Une ligne NEUTRE par autre commune titulaire, révoquée ou non (DM-119 §7) : ni agent, ni motif, ni détail. */
    private function recordNeutralAudits(Permit $permit, Uuid $acting, PermitRevision $revision, \DateTimeImmutable $now): void
    {
        $others = [];
        foreach ($permit->authorizations() as $authorization) {
            $id = $authorization->municipality()->id();
            if (!$id->equals($acting)) {
                $others[$id->toRfc4122()] = $id;
            }
        }
        ksort($others);
        foreach ($others as $other) {
            $this->audit->record(new AuditEvent(
                $now,
                AuditAction::PermitTokenReplacedByOtherMunicipality,
                AuditSubjectType::Permit,
                $permit->id(),
                $other,
                AuditActor::system(),
                null,
                ['reference' => $permit->reference()->value, 'revision' => $revision->id()->toRfc4122(), 'municipality' => $acting->toRfc4122()],
            ));
        }
    }

    private static function second(\DateTimeImmutable $instant): \DateTimeImmutable
    {
        $utc = $instant->setTimezone(new \DateTimeZone('UTC'));

        return $utc->setTimestamp($utc->getTimestamp());
    }
}
