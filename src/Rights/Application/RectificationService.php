<?php

declare(strict_types=1);

namespace App\Rights\Application;

use App\Archive\Application\ArchiveAccessPolicy;
use App\Identity\Domain\User;
use App\Request\Domain\PermitRequestMunicipality;
use App\Rights\Domain\RectificationField;
use App\Rights\Domain\RectificationMention;
use App\Rights\Domain\RightsRequest;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Application\TransactionRunner;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditActor;
use App\Shared\Domain\AuditSubjectType;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Rectification d'une archive PAR MENTION (LOT 05.6.6) : le texte est AJOUTÉ à côté de la valeur d'origine, rien n'est remplacé ; demandes et décisions historiques restent
 * intactes. Événement clos seulement (pendant l'événement, la correction d'identité existante s'applique). Mention propre au dossier de la commune, supprimée avec lui.
 * Réservé à l'administrateur de la commune, demande de rectification dont l'identité est attestée, dossier lié à CETTE demande et à CETTE commune.
 */
final class RectificationService
{
    public function __construct(
        private readonly ArchiveAccessPolicy $policy,
        private readonly Connection $connection,
        private readonly EntityManagerInterface $entityManager,
        private readonly TransactionRunner $transaction,
        private readonly AuditTrail $audit,
        private readonly ClockInterface $clock,
        private readonly RightsRequestStore $store,
    ) {
    }

    /**
     * @param list<string> $fieldCodes codes de `RectificationField`
     *
     * @throws OperationNotAllowed|DomainRuleViolation
     */
    public function addMention(User $actor, string $municipalityId, string $requestId, string $dossierId, array $fieldCodes, string $text): RectificationMention
    {
        $municipality = $this->policy->administrator($actor, $municipalityId, write: true)->municipality()->id();
        $fields = [];
        foreach ($fieldCodes as $code) {
            $fields[] = (RectificationField::tryFrom($code) ?? throw new DomainRuleViolation('Champ de rectification inconnu.'))->value;
        }
        $fields = array_values(array_unique($fields));
        $text = trim($text);
        if ([] === $fields || '' === $text || mb_strlen($text) > RectificationMention::MAX_LENGTH) {
            throw new DomainRuleViolation(sprintf('Indiquez au moins un champ et une mention de %d caractères au plus.', RectificationMention::MAX_LENGTH));
        }

        /** @var RectificationMention $mention */
        $mention = $this->transaction->run(function () use ($actor, $municipality, $requestId, $dossierId, $fields, $text): RectificationMention {
            $row = $this->store->find($requestId, $municipality, lock: true) ?? throw new OperationNotAllowed('Ressource inaccessible.');
            if ('RECTIFICATION' !== $row['kind'] || !\in_array($row['status'], ['IDENTITY_VERIFIED', 'DECIDED'], true) || 'REFUSED' === $row['decision']) {
                throw new DomainRuleViolation('Une mention n’est possible que pour une demande de rectification dont l’identité est attestée et qui n’est ni refusée ni clôturée.');
            }
            $id = Uuid::fromBinary((string) $row['id']);
            if (!Uuid::isValid($dossierId) || !\in_array(Uuid::fromString($dossierId)->toRfc4122(), $this->store->dossierIds($id), true)) {
                throw new OperationNotAllowed('Ressource inaccessible.'); // dossier non lié à cette demande (ou d'une autre commune, ou purgé)
            }
            $dossier = $this->entityManager->find(PermitRequestMunicipality::class, Uuid::fromString($dossierId));
            if (null === $dossier || !$dossier->municipality()->id()->equals($municipality)) {
                throw new OperationNotAllowed('Ressource inaccessible.');
            }
            $status = $this->connection->fetchOne('SELECT e.status FROM request_permit_request r JOIN event_event e ON e.id = r.event_id WHERE r.id = :r', ['r' => $dossier->request()->id()->toBinary()]);
            if ('CLOSED' !== $status) {
                throw new DomainRuleViolation('L’événement n’est pas clos : utilisez la correction d’identité, qui modifie la fiche avec traçabilité.');
            }
            $request = $this->entityManager->find(RightsRequest::class, $id) ?? throw new OperationNotAllowed('Ressource inaccessible.');
            $now = $this->clock->now();
            $mention = new RectificationMention($dossier, $request, implode(',', $fields), $text, $now, $actor);
            $this->entityManager->persist($mention);
            $this->audit->record(new AuditEvent($now, AuditAction::RightsMentionAdded, AuditSubjectType::RightsRequest, $id, $municipality, AuditActor::user($actor->id()), null, ['fields' => implode(',', $fields)]));

            return $mention;
        });

        return $mention;
    }
}
