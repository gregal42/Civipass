<?php

declare(strict_types=1);

namespace App\Request\Application;

use App\Event\Application\Support\EventLocker;
use App\Event\Application\Support\EventTransactions;
use App\Event\Domain\Event;
use App\Event\Domain\EventParticipation;
use App\Event\Domain\EventParticipationRepository;
use App\Event\Domain\EventZone;
use App\Event\Domain\EventZoneRepository;
use App\Identity\Domain\EmailAddress;
use App\Identity\Domain\User;
use App\Municipality\Application\MunicipalityContext;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\PhoneNumberNormalizer;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\Result\InspectedDocument;
use App\Request\Application\Result\SubmittedRequest;
use App\Request\Domain\PermitRequest;
use App\Request\Domain\PermitRequestAttachment;
use App\Request\Domain\PermitRequestRepository;
use App\Request\Domain\BeneficiaryCategory;
use App\Request\Domain\RequestedTerritory;
use App\Request\Domain\RequestMotif;
use App\Request\Domain\RequestIdempotencyKey;
use App\Request\Domain\RequestOrigin;
use App\Request\Domain\RequestReferenceGenerator;
use App\Request\Domain\RequestReferentialRepository;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Application\FileStorageInterface;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditActor;
use App\Shared\Domain\AuditSubjectType;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationConflict;
use App\Shared\Domain\OperationNotAllowed;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Dépôt atomique d'une demande de laissez-passer (DM-099, DM-101, DM-104), public ou saisi par un agent.
 *
 * Aucun identifiant du client n'est cru : l'événement est relu sous verrou, les communes se déduisent des zones, chaque
 * zone est relue sous verrou et son appartenance à sa participation vérifiée. Tout est écrit dans UNE transaction :
 * dossier, parts, zones, véhicules, référence, empreinte d'idempotence et audits ; un échec n'en laisse aucune trace.
 *
 * Ordre des verrous (DT-024) : événement (partagé : le dépôt ne coexiste pas avec une suspension ou une clôture) →
 * participations concernées, par identifiant croissant (exclusif : ni suspension de participation ni modification de
 * zone en parallèle) → zones → compteur de références, en dernier. Les invariants du dossier restent ceux de
 * `PermitRequest::submit()` ; ce service n'ajoute que ce qui exige les dépôts (créneaux, droits, verrous, idempotence).
 */
final readonly class RequestSubmissionService
{
    /** Garde-fou technique : nombre maximal de zones d'un même dépôt (verrous et écritures bornés). */
    public const MAX_ZONES = 50;
    private const IDEMPOTENCY_KEY_PATTERN = '/^[A-Za-z0-9_\-.:]{16,128}$/';

    public function __construct(
        private EventTransactions $transactions,
        private EventLocker $locker,
        private ClockInterface $clock,
        private MunicipalityContext $context,
        private EventParticipationRepository $participations,
        private EventZoneRepository $zones,
        private RequestReferentialRepository $referentials,
        private PermitRequestRepository $requests,
        private RequestReferenceGenerator $references,
        private PhoneNumberNormalizer $phones,
        private AuditTrail $audit,
        private FileStorageInterface $files,
        private UploadedDocumentInspector $documents,
        private ProposableZones $proposable,
    ) {
    }

    /**
     * Dépôt public : pour soi-même, sans compte, sans agent créateur ni commune de saisie.
     *
     * @throws DomainRuleViolation refus métier (événement fermé, zone non proposée, saisie invalide)
     * @throws OperationConflict   conflit technique ou tentative identique concurrente : réessayer
     */
    public function submitPublic(SubmitRequestCommand $command, string $idempotencyKey): SubmittedRequest
    {
        return $this->submit(RequestOrigin::Public, null, null, $command, $idempotencyKey);
    }

    /**
     * Saisie par un agent (instructeur ou gestionnaire d'événement) de la commune `$municipalityId` (déterminée et vérifiée côté serveur) pour un tiers.
     * Les zones d'autres communes participantes peuvent être demandées ; l'agent n'y acquiert aucun droit d'instruction.
     *
     * @throws OperationNotAllowed commune inaccessible, rôle insuffisant ou participation de la commune non opérationnelle
     * @throws DomainRuleViolation refus métier
     * @throws OperationConflict   conflit technique ou tentative identique concurrente : réessayer
     */
    public function submitByAgent(User $agent, string $municipalityId, SubmitRequestCommand $command, string $idempotencyKey): SubmittedRequest
    {
        return $this->submit(RequestOrigin::Agent, $agent, $municipalityId, $command, $idempotencyKey);
    }

    private function submit(RequestOrigin $origin, ?User $agent, ?string $municipalityId, SubmitRequestCommand $command, string $idempotencyKey): SubmittedRequest
    {
        if (1 !== preg_match(self::IDEMPOTENCY_KEY_PATTERN, $idempotencyKey)) {
            throw new DomainRuleViolation('Clé d\'idempotence invalide.');
        }
        if (!Uuid::isValid($command->eventId)) {
            throw new OperationNotAllowed('Dépôt impossible.');
        }
        $zoneIds = $this->zoneIds($command);
        // Validations à blanc avant tout verrou : saisie, référentiels.
        $email = self::blank($command->email);
        $phone = self::blank($command->phone);
        $emailAddress = null === $email ? null : EmailAddress::fromString($email);
        $phoneNumber = null === $phone ? null : $this->phones->normalize($phone);
        $category = $this->referentials->findCategoryByCode($command->categoryCode) ?? throw new DomainRuleViolation('Catégorie de bénéficiaire inconnue.');
        $motif = $this->referentials->findMotifByCode($command->motifCode) ?? throw new DomainRuleViolation('Motif inconnu.');
        $keyHash = hash('sha256', $origin->value.'|'.($agent?->id()->toRfc4122() ?? '').'|'.$idempotencyKey);
        $eventId = Uuid::fromString($command->eventId);

        // Une tentative déjà enregistrée se rejoue SANS toucher aux fichiers (les temporaires ont pu être supprimés depuis).
        if ([] !== $command->attachments && null !== ($existing = $this->requests->findByIdempotencyKey($eventId, $keyHash))) {
            return new SubmittedRequest($existing->id()->toRfc4122(), $existing->reference()->value, true);
        }
        // Fichiers contrôlés sur leur contenu réel AVANT tout verrou : les verrous ne couvrent jamais de lecture de fichier.
        $inspected = $this->inspectAttachments($command);

        // Fichiers rattachés pendant la transaction : à supprimer si elle échoue (le système de fichiers n'est pas transactionnel).
        $promoted = [];
        try {
            $result = $this->persist($origin, $agent, $municipalityId, $command, $zoneIds, $emailAddress, $phoneNumber, $category, $motif, $keyHash, $eventId, $inspected, $promoted);
        } catch (\Throwable $e) {
            foreach ($promoted as $key) {
                $this->files->delete($key);
            }
            throw $e;
        }
        if (!$result->replayed) {
            // Dépôt validé : les copies temporaires ne servent plus (échec ignoré : la purge les supprimera).
            foreach ($inspected as [$temporaryKey]) {
                try {
                    $this->files->delete($temporaryKey);
                } catch (\Throwable) {
                }
            }
        }

        return $result;
    }

    /**
     * @param list<Uuid>                             $zoneIds
     * @param list<array{string, InspectedDocument}> $inspected
     * @param list<string>                           $promoted  clés définitives créées (renseigné au fil de la transaction)
     */
    private function persist(RequestOrigin $origin, ?User $agent, ?string $municipalityId, SubmitRequestCommand $command, array $zoneIds, ?EmailAddress $emailAddress, ?\App\Municipality\Domain\PhoneNumber $phoneNumber, BeneficiaryCategory $category, RequestMotif $motif, string $keyHash, Uuid $eventId, array $inspected, array &$promoted): SubmittedRequest
    {
        return $this->transactions->run(function () use ($origin, $agent, $municipalityId, $command, $zoneIds, $emailAddress, $phoneNumber, $category, $motif, $keyHash, $eventId, $inspected, &$promoted): SubmittedRequest {
            // Première instruction : verrou partagé de l'événement (relu sous verrou, jamais l'instantané).
            $event = $this->locker->sharedEvent($eventId);

            [$enteredBy, $own] = null === $agent ? [null, null] : $this->authorizeAgent($agent, (string) $municipalityId, $event);

            $existing = $this->requests->findByIdempotencyKey($event->id(), $keyHash);
            if (null !== $existing) {
                return new SubmittedRequest($existing->id()->toRfc4122(), $existing->reference()->value, true);
            }
            if (!$event->isOperational()) {
                throw new DomainRuleViolation('Les demandes ne sont pas ouvertes pour cet événement.');
            }

            $territories = $this->lockTerritories($event, $zoneIds, $own);
            // Saisie municipale : uniquement les zones de la commune de l'agent (jamais celles d'une autre commune, même valides).
            if (null !== $own) {
                foreach ($territories as $territory) {
                    if (!$territory->participation->id()->equals($own->id())) {
                        throw new DomainRuleViolation('Une saisie municipale ne peut porter que sur les zones de votre commune.');
                    }
                }
            }
            if (null !== $own && !$own->isOperational()) {
                throw new OperationNotAllowed('Saisie non autorisée : la participation de votre commune n\'est pas active.');
            }

            // Horodatage pris sous verrou (DT-038) ; compteur de références en dernier (D2).
            $now = $this->clock->now();
            $this->assertPeriodZonesAreUsable($territories, $event, $now);
            $request = PermitRequest::submit(
                $this->references->next($now, $event->timezone()),
                $event,
                $origin,
                $command->lastName,
                $command->firstName,
                $command->address,
                $emailAddress,
                $phoneNumber,
                $category,
                $motif,
                $command->motifDetail,
                $command->travelMode,
                $command->plates,
                $territories,
                $command->privacyAttested,
                $command->privacyNoticeVersion,
                $agent,
                $enteredBy,
                $now,
            );
            foreach ($inspected as [$temporaryKey, $document]) {
                $permanentKey = $this->files->promote($temporaryKey);
                $promoted[] = $permanentKey;
                $request->attach($document->originalName, $document->mimeType, $document->sizeBytes, $permanentKey, $now);
            }
            $this->requests->add($request, new RequestIdempotencyKey($event, $keyHash, $request, $now));
            $this->recordAudit($request, $agent, $enteredBy, $now);

            return new SubmittedRequest($request->id()->toRfc4122(), $request->reference()->value, false);
        }, ['uniq_request_idempotency_event_key']);
    }

    /**
     * Justificatifs : nombre borné, clés temporaires valides et distinctes, contenu réellement présent et conforme
     * (jamais les métadonnées fournies par le client).
     *
     * @return list<array{string, InspectedDocument}>
     */
    private function inspectAttachments(SubmitRequestCommand $command): array
    {
        if (\count($command->attachments) > PermitRequestAttachment::MAX_PER_REQUEST) {
            throw new DomainRuleViolation(sprintf('Une demande comporte au plus %d justificatifs.', PermitRequestAttachment::MAX_PER_REQUEST));
        }
        $inspected = [];
        $seen = [];
        foreach ($command->attachments as $attachment) {
            $key = $attachment->temporaryKey;
            if (1 !== preg_match(FileStorageInterface::TEMPORARY_KEY_PATTERN, $key) || isset($seen[$key])) {
                throw new DomainRuleViolation('Justificatif invalide : renvoyez-le.');
            }
            $seen[$key] = true;
            try {
                $stream = $this->files->readStream($key);
            } catch (\RuntimeException) {
                throw new DomainRuleViolation(sprintf('Le justificatif « %s » n\'est plus disponible : renvoyez-le.', $attachment->originalName));
            }
            $content = stream_get_contents($stream, PermitRequestAttachment::MAX_SIZE_BYTES + 1);
            fclose($stream);
            $inspected[] = [$key, $this->documents->inspect(false === $content ? '' : $content, $attachment->originalName)];
        }

        return $inspected;
    }

    /**
     * Identifiants de zones du client : valides, distincts, en nombre borné.
     *
     * @return list<Uuid>
     */
    private function zoneIds(SubmitRequestCommand $command): array
    {
        if ([] === $command->zoneIds) {
            throw new DomainRuleViolation('Choisissez au moins une commune et une zone.');
        }
        if (\count($command->zoneIds) > self::MAX_ZONES) {
            throw new DomainRuleViolation(sprintf('Une demande comporte au plus %d zones.', self::MAX_ZONES));
        }
        $ids = [];
        foreach ($command->zoneIds as $raw) {
            if (!Uuid::isValid($raw)) {
                throw new DomainRuleViolation('Une zone demandée n\'est plus proposée.');
            }
            $ids[Uuid::fromString($raw)->toRfc4122()] = Uuid::fromString($raw);
        }
        if (\count($ids) !== \count($command->zoneIds)) {
            throw new DomainRuleViolation('Une zone ne peut être demandée qu\'une seule fois.');
        }

        return array_values($ids);
    }

    /**
     * Agent instructeur d'une commune active, dont la participation à l'événement existe (son état opérationnel est
     * revérifié sous verrou). Réutilise le contexte municipal : membership effectif, commune ACTIVE en écriture.
     *
     * @return array{Municipality, EventParticipation}
     */
    private function authorizeAgent(User $agent, string $municipalityId, Event $event): array
    {
        $membership = $this->context->requireMembership($agent, $municipalityId, write: true);
        if (!AgentRequestAccess::mayEnter($membership)) {
            throw new OperationNotAllowed('Saisie non autorisée : rôle d\'administrateur communal, d\'agent instructeur ou de gestionnaire d\'événement requis.');
        }
        $municipality = $membership->municipality();
        $own = $this->participations->findByEventAndMunicipality($event->id(), $municipality->id());
        if (null === $own) {
            throw new OperationNotAllowed('Saisie non autorisée : votre commune ne participe pas à cet événement.');
        }

        return [$municipality, $own];
    }

    /**
     * Participations concernées (et celle de l'agent), verrouillées par identifiant croissant, puis zones relues sous
     * verrou et contrôlées : une zone étrangère, supprimée ou inexistante est refusée en bloc.
     *
     * @param list<Uuid> $zoneIds
     *
     * @return list<RequestedTerritory>
     */
    private function lockTerritories(Event $event, array $zoneIds, ?EventParticipation $own): array
    {
        $idsByParticipation = [];
        foreach ($zoneIds as $zoneId) {
            $zone = $this->zones->find($zoneId) ?? throw new DomainRuleViolation('Une zone demandée n\'est plus proposée.');
            $idsByParticipation[$zone->participation()->id()->toRfc4122()][] = $zoneId;
        }
        $toLock = array_keys($idsByParticipation);
        if (null !== $own) {
            $toLock[] = $own->id()->toRfc4122();
        }
        $toLock = array_values(array_unique($toLock));
        sort($toLock);

        $territories = [];
        foreach ($toLock as $participationId) {
            $participation = $this->lockParticipation(Uuid::fromString($participationId), $event);
            if (isset($idsByParticipation[$participationId])) {
                $territories[] = new RequestedTerritory($participation, $this->lockZones($idsByParticipation[$participationId], $participation));
            }
        }

        return $territories;
    }

    /** Une participation d'un autre événement (zone forgée) reçoit le même refus qu'une zone inexistante. */
    private function lockParticipation(Uuid $participationId, Event $event): EventParticipation
    {
        try {
            return $this->locker->participation($participationId, $event);
        } catch (OperationNotAllowed) {
            throw new DomainRuleViolation('Une zone demandée n\'est plus proposée.');
        }
    }

    /**
     * @param list<Uuid> $zoneIds
     *
     * @return list<EventZone>
     */
    private function lockZones(array $zoneIds, EventParticipation $participation): array
    {
        try {
            return $this->locker->zones($zoneIds, $participation);
        } catch (OperationNotAllowed) {
            throw new DomainRuleViolation('Une zone demandée n\'est plus proposée.');
        }
    }

    /**
     * Une zone en « périodes spécifiques » n'accepte une demande que si elle a un créneau utilisable (voir ProposableZones :
     * règle commune au formulaire public et au dépôt).
     *
     * @param list<RequestedTerritory> $territories
     */
    private function assertPeriodZonesAreUsable(array $territories, Event $event, \DateTimeImmutable $now): void
    {
        foreach ($territories as $territory) {
            $usable = array_map(static fn (EventZone $z): string => $z->id()->toRfc4122(), $this->proposable->withUsablePeriods($territory->zones, $event, $now));
            foreach ($territory->zones as $zone) {
                if (!\in_array($zone->id()->toRfc4122(), $usable, true)) {
                    throw new DomainRuleViolation(sprintf('La zone « %s » n\'est pas proposée aux demandes : aucune période de validité à venir ou en cours.', $zone->label()));
                }
            }
        }
    }

    /**
     * Une ligne d'audit PAR commune concernée (et par la commune de saisie de l'agent) : chaque ligne ne décrit que les
     * zones de SA commune ; aucune ne révèle les autres communes ni leurs zones. Aucune donnée personnelle ni texte libre.
     */
    private function recordAudit(PermitRequest $request, ?User $agent, ?Municipality $enteredBy, \DateTimeImmutable $now): void
    {
        $actor = null === $agent ? AuditActor::public() : AuditActor::user($agent->id());
        $batch = Uuid::v7();
        $zonesByMunicipality = [];
        foreach ($request->municipalities() as $part) {
            $zonesByMunicipality[$part->municipality()->id()->toRfc4122()] = [$part->municipality()->id(), \count($part->zones())];
        }
        if (null !== $enteredBy && !isset($zonesByMunicipality[$enteredBy->id()->toRfc4122()])) {
            $zonesByMunicipality[$enteredBy->id()->toRfc4122()] = [$enteredBy->id(), 0];
        }
        foreach ($zonesByMunicipality as [$municipalityId, $zoneCount]) {
            $this->audit->record(new AuditEvent($now, AuditAction::RequestSubmitted, AuditSubjectType::PermitRequest, $request->id(), $municipalityId, $actor, null, [
                'reference' => $request->reference()->value,
                'origin' => $request->origin()->value,
                'event' => $request->event()->id()->toRfc4122(),
                'travelMode' => $request->travelMode()->value,
                'zones' => $zoneCount,
                'vehicles' => \count($request->vehicles()),
                'enteredHere' => null !== $enteredBy && $enteredBy->id()->equals($municipalityId),
            ], $batch));
        }
    }

    private static function blank(?string $value): ?string
    {
        $value = null === $value ? '' : trim($value);

        return '' === $value ? null : $value;
    }
}
