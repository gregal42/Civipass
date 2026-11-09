<?php

declare(strict_types=1);

namespace App\Permit\Application;

use App\Permit\Domain\NotificationIntent;
use App\Permit\Domain\NotificationKind;
use App\Permit\Domain\Permit;
use App\Permit\Domain\PermitReferenceGenerator;
use App\Permit\Domain\PermitRepository;
use App\Request\Application\ApprovedPartListener;
use App\Request\Domain\PermitRequestDecision;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditActor;
use App\Shared\Domain\AuditSubjectType;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Création du laissez-passer à l'approbation (LOT 05.1, DM-112, DT-046) : implémentation du port ApprovedPartListener, appelée DANS la
 * transaction de décision (instruction ET délivrance directe), après la décision et son audit. Elle n'écrit que des lignes en base :
 * aucun PDF, SMTP ni appel réseau. Une exception annule l'approbation et la création ensemble.
 *
 * Idempotent et sans doublon : un laissez-passer par demande, une autorisation par décision. Le rejeu du port ne crée rien.
 *
 * Verrous (ordre DT-024, déjà acquis par l'appelant : événement → participation → zones → part) puis, ICI : ligne du dossier (si le
 * dossier est déjà écrit) → compteur `LP-` EN DERNIER. Sous REPEATABLE READ, une lecture simple ne voit pas un laissez-passer qu'une autre
 * commune vient de créer : si la lecture simple ne le trouve pas, le créateur prend d'abord le verrou du compteur (qui sérialise les
 * créateurs et évite les interblocages de verrous d'intervalle) puis relit en VERROUILLANT. Dossier de délivrance directe : le dossier n'est
 * pas encore écrit (ni concurrent possible, ni verrou nécessaire) ; le flush unique de la transaction écrit tout.
 */
#[AsAlias(ApprovedPartListener::class)]
final readonly class PermitIssuer implements ApprovedPartListener
{
    public function __construct(
        private PermitRepository $permits,
        private PermitReferenceGenerator $references,
        private AuditTrail $audit,
        private NotificationIntentRecorder $notifications,
    ) {
    }

    public function partApproved(PermitRequestDecision $decision): void
    {
        $this->issue($decision, notify: true);
    }

    /**
     * @param bool $notify faux pour le rattrapage historique (`app:permit:backfill`) : aucune intention de notification n'est enregistrée
     */
    public function issue(PermitRequestDecision $decision, bool $notify): void
    {
        $part = $decision->requestMunicipality();
        $request = $part->request();
        $persisted = $this->permits->isPersisted($request);
        $this->permits->lockRequest($request);

        $permit = $this->permits->findByRequest($request->id());
        if (null === $permit && $persisted) {
            // Un laissez-passer validé entre-temps par une autre commune est invisible d'une lecture simple (instantané) : sérialiser
            // les créateurs, puis relire sous verrou.
            $this->references->lock($decision->decidedAt(), $request->event()->timezone());
            $permit = $this->permits->findByRequest($request->id(), locking: true);
        }
        if (null !== $permit && null !== $permit->authorizationForDecision($decision->id())) {
            return; // rejeu : rien à créer
        }

        $created = null === $permit;
        if (null === $permit) {
            $permit = Permit::issue($request, $decision, $this->references->next($decision->decidedAt(), $request->event()->timezone()), $decision->decidedAt());
            $this->permits->add($permit);
        }
        if (!$created && $persisted) {
            // Commune supplémentaire : verrou du laissez-passer (après la demande, avant toute écriture le concernant), le même que celui des demandes
            // manuelles de notification et des futures révisions : leurs lectures et écritures d'outbox sont ainsi sérialisées, sans interblocage.
            $this->permits->lock($permit->id());
        }
        $authorization = $permit->authorize($part, $decision);
        $this->recordAudit($created ? AuditAction::PermitIssued : AuditAction::PermitAuthorizationAdded, $permit, $authorization, $decision);
        if ($notify) {
            // Intention écrite dans la même transaction (outbox, DT-049) : identifiants seulement, aucun envoi ici.
            $this->notifications->record(new NotificationIntent(
                $created ? NotificationKind::PermitIssued : NotificationKind::AuthorizationAdded,
                $permit->id(),
                $authorization->id(),
                $decision->decidedAt(),
                '' !== trim((string) $request->email()),
            ));
        }
    }

    private function recordAudit(AuditAction $action, Permit $permit, \App\Permit\Domain\PermitAuthorization $authorization, PermitRequestDecision $decision): void
    {
        // Jamais le jeton, jamais une donnée personnelle (DT-037, DM-113).
        $this->audit->record(new AuditEvent(
            $decision->decidedAt(),
            $action,
            AuditSubjectType::Permit,
            $permit->id(),
            $authorization->municipality()->id(),
            AuditActor::user($decision->decidedBy()->id()),
            null,
            [
                'reference' => $permit->reference()->value,
                'request' => $permit->request()->reference()->value,
                'authorization' => $authorization->id()->toRfc4122(),
                'decision' => $decision->id()->toRfc4122(),
                'durationMode' => $authorization->window()->mode->value,
                'zones' => \count($authorization->activeZones()),
            ],
        ));
    }
}
