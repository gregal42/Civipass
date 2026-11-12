<?php

declare(strict_types=1);

namespace App\Rights\Application;

use App\Archive\Application\ArchiveAccessPolicy;
use App\Identity\Domain\User;
use App\Request\Domain\PermitRequestMunicipality;
use App\Rights\Domain\RightsDecision;
use App\Rights\Domain\RightsExtensionReason;
use App\Rights\Domain\RightsIdentityMethod;
use App\Rights\Domain\RightsRefusalReason;
use App\Rights\Domain\RightsRequest;
use App\Rights\Domain\RightsRequestDossier;
use App\Rights\Domain\RightsRequestKind;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Application\TransactionRunner;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditActor;
use App\Shared\Domain\AuditSubjectType;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Instruction d'une demande de droits (LOT 05.6.6) : enregistrement, attestation d'identité, prorogation, décision motivée, clôture. Réservé au `MUNICIPALITY_ADMIN` de la
 * commune (droit relu à chaque appel, commune de l'appelant et jamais celle de l'URL pour les requêtes) ; toute autre personne reçoit le refus uniforme. Chaque transition est
 * une mise à jour CONDITIONNELLE sous verrou de la ligne : deux décisions concurrentes ne peuvent pas toutes deux aboutir. CWS n'a aucun accès. Aucune donnée du demandeur n'est
 * enregistrée : les dossiers sont désignés par leur référence publique, jamais par un nom ou une adresse.
 */
final class RightsRequestService
{
    public const MAX_REFERENCES = 10;

    public function __construct(
        private readonly ArchiveAccessPolicy $policy,
        private readonly Connection $connection,
        private readonly EntityManagerInterface $entityManager,
        private readonly TransactionRunner $transaction,
        private readonly AuditTrail $audit,
        private readonly ClockInterface $clock,
        private readonly RightsSettings $settings,
        private readonly RightsRequestStore $store,
    ) {
    }

    /**
     * @param list<string> $references références publiques des demandes (dossiers de la commune)
     *
     * @throws OperationNotAllowed refus uniforme
     * @throws DomainRuleViolation
     */
    public function register(User $actor, string $municipalityId, string $kindCode, array $references, ?\DateTimeImmutable $receivedAt = null): RightsRequest
    {
        $membership = $this->policy->administrator($actor, $municipalityId, write: true);
        $kind = RightsRequestKind::tryFrom($kindCode) ?? throw new DomainRuleViolation('Choisissez la nature de la demande.');
        $references = array_values(array_unique(array_filter(array_map(static fn (string $r): string => strtoupper(trim($r)), $references), static fn (string $r): bool => '' !== $r)));
        if ([] === $references || \count($references) > self::MAX_REFERENCES) {
            throw new DomainRuleViolation(sprintf('Indiquez de 1 à %d références de dossier.', self::MAX_REFERENCES));
        }
        $now = RightsRequestStore::utc($this->clock->now());
        $received = null === $receivedAt ? $now : RightsRequestStore::utc($receivedAt);
        if ($received > $now || $received < $now->modify('-1 year')) {
            throw new DomainRuleViolation('La date de réception doit être comprise dans les douze derniers mois.');
        }
        $own = $membership->municipality();
        $found = $this->connection->fetchFirstColumn(
            'SELECT rm.id FROM request_permit_request_municipality rm JOIN request_permit_request r ON r.id = rm.request_id WHERE rm.municipality_id = :m AND r.public_reference IN (:refs)',
            ['m' => $own->id()->toBinary(), 'refs' => $references],
            ['refs' => ArrayParameterType::STRING],
        );
        if (\count($found) !== \count($references)) {
            // Inconnue ou appartenant à une autre commune : même message, aucune indication sur l'existence ailleurs.
            throw new DomainRuleViolation('Une ou plusieurs références ne correspondent à aucun dossier de votre commune.');
        }

        /** @var RightsRequest $request */
        $request = $this->transaction->run(function () use ($actor, $own, $kind, $received, $found): RightsRequest {
            $request = new RightsRequest($own, 'DR-'.strtoupper(bin2hex(random_bytes(4))), $kind, $received, $actor, $received->modify(sprintf('+%d days', $this->settings->responseDays())), \count($found));
            $this->entityManager->persist($request);
            foreach ($found as $binary) {
                $dossier = $this->entityManager->find(PermitRequestMunicipality::class, Uuid::fromBinary((string) $binary)) ?? throw new OperationNotAllowed('Ressource inaccessible.');
                $this->entityManager->persist(new RightsRequestDossier($request, $dossier));
            }
            $this->record(AuditAction::RightsRequestRegistered, $request->id(), $own->id(), $actor, ['kind' => $kind->value, 'dossiers' => \count($found), 'due' => $request->dueAt()->format(\DATE_ATOM)]);

            return $request;
        });

        return $request;
    }

    /** @throws OperationNotAllowed|DomainRuleViolation */
    public function attestIdentity(User $actor, string $municipalityId, string $requestId, string $methodCode): void
    {
        $municipality = $this->policy->administrator($actor, $municipalityId, write: true)->municipality()->id();
        $method = RightsIdentityMethod::tryFrom($methodCode) ?? throw new DomainRuleViolation('Indiquez comment l’identité a été vérifiée.');
        $this->transaction->run(function () use ($actor, $municipality, $requestId, $method): void {
            $row = $this->store->find($requestId, $municipality, lock: true) ?? throw new OperationNotAllowed('Ressource inaccessible.');
            if ('RECEIVED' !== $row['status']) {
                throw new DomainRuleViolation('L’identité de cette demande est déjà attestée, ou la demande est traitée.');
            }
            $now = RightsRequestStore::utc($this->clock->now());
            $this->connection->executeStatement('UPDATE rights_request SET status = \'IDENTITY_VERIFIED\', identity_verified_at = :now, identity_verified_by_user_id = :u, identity_method = :m WHERE id = :id', ['now' => $now->format('Y-m-d H:i:s'), 'u' => $actor->id()->toBinary(), 'm' => $method->value, 'id' => $row['id']]);
            $this->record(AuditAction::RightsIdentityAttested, Uuid::fromBinary((string) $row['id']), $municipality, $actor, ['method' => $method->value]);
        });
    }

    /** Prorogation motivée, une seule fois (jusqu'à `RIGHTS_EXTENSION_DAYS` après l'échéance initiale). */
    public function extend(User $actor, string $municipalityId, string $requestId, string $reasonCode): void
    {
        $municipality = $this->policy->administrator($actor, $municipalityId, write: true)->municipality()->id();
        $reason = RightsExtensionReason::tryFrom($reasonCode) ?? throw new DomainRuleViolation('Indiquez le motif de la prorogation.');
        $this->transaction->run(function () use ($actor, $municipality, $requestId, $reason): void {
            $row = $this->store->find($requestId, $municipality, lock: true) ?? throw new OperationNotAllowed('Ressource inaccessible.');
            if (!\in_array($row['status'], ['RECEIVED', 'IDENTITY_VERIFIED'], true) || null !== $row['extended_until']) {
                throw new DomainRuleViolation('La demande ne peut plus être prorogée (déjà prorogée ou déjà décidée).');
            }
            $until = (new \DateTimeImmutable((string) $row['due_at'], new \DateTimeZone('UTC')))->modify(sprintf('+%d days', $this->settings->extensionDays()));
            $this->connection->executeStatement('UPDATE rights_request SET extended_until = :u, extension_reason = :r WHERE id = :id', ['u' => $until->format('Y-m-d H:i:s'), 'r' => $reason->value, 'id' => $row['id']]);
            $this->record(AuditAction::RightsRequestExtended, Uuid::fromBinary((string) $row['id']), $municipality, $actor, ['reason' => $reason->value, 'until' => $until->format(\DATE_ATOM)]);
        });
    }

    /**
     * Décision motivée. Exige l'identité attestée. Refus : motif codé obligatoire. Accès et effacement : accordés ou refusés (pas de décision partielle). Effacement accordé :
     * vérification EXPLICITE des obligations de conservation (case obligatoire) ET contrôle serveur de l'absence de gel et de la clôture de l'événement sur chaque dossier ; l'exécution
     * reste désactivée tant que la politique juridique n'est pas validée (arbitrage D1). Opposition ou limitation : aucun effet automatique.
     */
    public function decide(User $actor, string $municipalityId, string $requestId, string $decisionCode, ?string $refusalCode, bool $conservationChecked): RightsDecision
    {
        $municipality = $this->policy->administrator($actor, $municipalityId, write: true)->municipality()->id();
        $decision = RightsDecision::tryFrom($decisionCode) ?? throw new DomainRuleViolation('Choisissez la décision.');
        $refusal = null;
        if (RightsDecision::Refused === $decision) {
            $refusal = RightsRefusalReason::tryFrom((string) $refusalCode) ?? throw new DomainRuleViolation('Un refus exige un motif.');
        }

        $this->transaction->run(function () use ($actor, $municipality, $requestId, $decision, $refusal, $conservationChecked): void {
            $row = $this->store->find($requestId, $municipality, lock: true) ?? throw new OperationNotAllowed('Ressource inaccessible.');
            if ('IDENTITY_VERIFIED' !== $row['status']) {
                throw new DomainRuleViolation('Attestez d’abord l’identité du demandeur : aucune décision n’est possible avant, et une demande déjà décidée ne se redécide pas.');
            }
            $kind = RightsRequestKind::from((string) $row['kind']);
            $id = Uuid::fromBinary((string) $row['id']);
            if (RightsDecision::Partial === $decision && \in_array($kind, [RightsRequestKind::Access, RightsRequestKind::Erasure], true)) {
                throw new DomainRuleViolation('Cette demande est accordée ou refusée, sans décision partielle.');
            }
            $dossiers = $this->store->dossierIds($id);
            if ($decision->isGrant() && [] === $dossiers && \in_array($kind, [RightsRequestKind::Access, RightsRequestKind::Erasure, RightsRequestKind::Rectification], true)) {
                throw new DomainRuleViolation('Aucun dossier n’est plus rattaché à cette demande (purgé) : elle ne peut qu’être refusée puis clôturée.');
            }
            $conservationAt = null;
            if (RightsRequestKind::Erasure === $kind && $decision->isGrant()) {
                if (!$conservationChecked) {
                    throw new DomainRuleViolation('Confirmez avoir vérifié les obligations de conservation applicables avant d’accorder un effacement.');
                }
                $this->assertErasureAllowedNow($dossiers);
                $conservationAt = RightsRequestStore::utc($this->clock->now())->format('Y-m-d H:i:s');
            }
            $now = RightsRequestStore::utc($this->clock->now());
            $until = RightsRequestKind::Access === $kind && $decision->isGrant() ? $now->modify(sprintf('+%d days', $this->settings->exportWindowDays()))->format('Y-m-d H:i:s') : null;
            $this->connection->executeStatement(
                'UPDATE rights_request SET status = \'DECIDED\', decided_at = :now, decided_by_user_id = :u, decision = :d, refusal_reason = :r, export_available_until = :until,'
                .' conservation_checked_at = :c, conservation_checked_by_user_id = :cu WHERE id = :id AND status = \'IDENTITY_VERIFIED\'',
                ['now' => $now->format('Y-m-d H:i:s'), 'u' => $actor->id()->toBinary(), 'd' => $decision->value, 'r' => $refusal?->value, 'until' => $until, 'c' => $conservationAt, 'cu' => null === $conservationAt ? null : $actor->id()->toBinary(), 'id' => $row['id']],
            );
            $this->record(AuditAction::RightsRequestDecided, $id, $municipality, $actor, ['kind' => $kind->value, 'decision' => $decision->value, 'refusal' => $refusal?->value]);
        });

        return $decision;
    }

    /** Réponse envoyée (hors Civipass) : la demande est clôturée ; l'export n'est plus disponible. */
    public function close(User $actor, string $municipalityId, string $requestId): void
    {
        $municipality = $this->policy->administrator($actor, $municipalityId, write: true)->municipality()->id();
        $this->transaction->run(function () use ($actor, $municipality, $requestId): void {
            $row = $this->store->find($requestId, $municipality, lock: true) ?? throw new OperationNotAllowed('Ressource inaccessible.');
            if ('DECIDED' !== $row['status']) {
                throw new DomainRuleViolation('Seule une demande décidée peut être clôturée.');
            }
            $now = RightsRequestStore::utc($this->clock->now());
            $this->connection->executeStatement('UPDATE rights_request SET status = \'CLOSED\', closed_at = :now, export_available_until = NULL WHERE id = :id AND status = \'DECIDED\'', ['now' => $now->format('Y-m-d H:i:s'), 'id' => $row['id']]);
            $this->record(AuditAction::RightsRequestClosed, Uuid::fromBinary((string) $row['id']), $municipality, $actor, ['kind' => $row['kind']]);
        });
    }

    /**
     * Blocages MÉCANIQUES d'un effacement (jamais contournés) : événement non clos, gel actif.
     *
     * @param list<string> $dossierIds
     *
     * @throws DomainRuleViolation
     */
    public function assertErasureAllowedNow(array $dossierIds): void
    {
        foreach ($dossierIds as $dossierId) {
            $blocked = $this->erasureBlocker($dossierId);
            if (null !== $blocked) {
                throw new DomainRuleViolation(sprintf('Un dossier ne peut pas être effacé : %s. Refusez la demande avec ce motif, ou attendez.', RightsRefusalReason::from($blocked)->label()));
            }
        }
    }

    /** @return string|null code de `RightsRefusalReason` ou null */
    public function erasureBlocker(string $dossierId): ?string
    {
        $id = Uuid::fromString($dossierId);
        $status = $this->connection->fetchOne('SELECT e.status FROM request_permit_request_municipality rm JOIN request_permit_request r ON r.id = rm.request_id JOIN event_event e ON e.id = r.event_id WHERE rm.id = :d', ['d' => $id->toBinary()]);
        if ('CLOSED' !== $status) {
            return RightsRefusalReason::EventOngoing->value;
        }
        if ((int) $this->connection->fetchOne('SELECT COUNT(*) FROM archive_legal_hold WHERE request_municipality_id = :d AND active_marker = 1', ['d' => $id->toBinary()]) > 0) {
            return RightsRefusalReason::LegalHold->value;
        }

        return null;
    }

    /** @param array<string, int|string|null> $values */
    private function record(AuditAction $action, Uuid $requestId, Uuid $municipalityId, User $actor, array $values): void
    {
        $this->audit->record(new AuditEvent($this->clock->now(), $action, AuditSubjectType::RightsRequest, $requestId, $municipalityId, AuditActor::user($actor->id()), null, $values));
    }
}
