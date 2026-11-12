<?php

declare(strict_types=1);

namespace App\Archive\Application;

use App\Archive\Application\Read\ArchiveDossierLine;
use App\Archive\Application\Read\ArchiveDossierView;
use App\Archive\Application\Read\ArchiveEventLine;
use App\Archive\Domain\ArchiveConsultation;
use App\Archive\Domain\ArchiveConsultationRepository;
use App\Archive\Domain\RequestLegalHoldRepository;
use App\Event\Domain\EventParticipationRepository;
use App\Identity\Domain\User;
use App\Municipality\Domain\MunicipalityRole;
use App\Permit\Domain\PermitRepository;
use App\Permit\Domain\PermitRevisionRepository;
use App\Request\Domain\MunicipalRequestStatus;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Lectures de l'espace d'archives (LOT 05.6.3), TOUJOURS après `ArchiveAccessPolicy`. Listes SANS donnée personnelle ; dossier = données communes + propres à la commune,
 * autres communes en décompte neutre. Aucune écriture.
 */
final readonly class ArchiveDossierReader
{
    public function __construct(
        private Connection $connection,
        private ArchiveAccessPolicy $policy,
        private EventParticipationRepository $participations,
        private RequestLegalHoldRepository $holds,
        private ArchiveConsultationRepository $consultations,
        private PermitRepository $permits,
        private PermitRevisionRepository $revisions,
        private ArchiveSettings $settings,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Événements clos que l'utilisateur peut consulter dans SA commune (administrateur : tous ; gestionnaire : ceux pour lesquels il est désigné). Aucune fermeture à échéance (correction A3).
     *
     * @return list<ArchiveEventLine>
     */
    public function events(User $user, string $municipalityId): array
    {
        $membership = $this->policy->readMembership($user, $municipalityId);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT p.id AS participation_id, e.id AS event_id, e.name, e.public_reference, e.closed_at FROM event_participation p JOIN event_event e ON e.id = p.event_id'
            .' WHERE p.municipality_id = :m AND p.activated_at IS NOT NULL AND e.status = \'CLOSED\' AND e.closed_at IS NOT NULL ORDER BY e.closed_at DESC, e.id DESC LIMIT 200',
            ['m' => $membership->municipality()->id()->toBinary()],
        );
        $lines = [];
        foreach ($rows as $row) {
            $participation = $this->participations->find(Uuid::fromBinary((string) $row['participation_id']));
            $via = null === $participation ? null : $this->policy->grant($user, $membership, $participation);
            if (null === $via) {
                continue;
            }
            $closedAt = new \DateTimeImmutable((string) $row['closed_at'], new \DateTimeZone('UTC'));
            $lines[] = new ArchiveEventLine(Uuid::fromBinary((string) $row['event_id'])->toRfc4122(), (string) $row['name'], (string) $row['public_reference'], $closedAt, $this->settings->purgeEligibleAt($closedAt), $via);
        }

        return $lines;
    }

    /**
     * @return array{archiveEvent: \App\Event\Domain\Event, viaDesignation: bool, dossiers: list<ArchiveDossierLine>, canManage: bool}
     */
    public function dossiers(User $user, string $municipalityId, string $eventId): array
    {
        [$event, $participation, $via] = $this->policy->event($user, $municipalityId, $eventId);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT rm.id AS dossier_id, r.public_reference, c.label AS category, rm.status, d.decided_at FROM request_permit_request_municipality rm'
            .' JOIN request_permit_request r ON r.id = rm.request_id JOIN request_beneficiary_category c ON c.id = r.category_id'
            .' LEFT JOIN request_permit_request_decision d ON d.request_municipality_id = rm.id'
            .' WHERE r.event_id = :e AND rm.municipality_id = :m ORDER BY r.submitted_at DESC, rm.id DESC LIMIT 1000',
            ['e' => $event->id()->toBinary(), 'm' => $participation->municipality()->id()->toBinary()],
        );
        $ids = array_map(static fn (array $r): Uuid => Uuid::fromBinary((string) $r['dossier_id']), $rows);
        $holds = $this->holds->activeAmong($ids);
        $now = $this->clock->now();
        $eligibleAt = $this->settings->purgeEligibleAt($event->closedAt() ?? $now);
        $lines = [];
        foreach ($rows as $row) {
            $id = Uuid::fromBinary((string) $row['dossier_id'])->toRfc4122();
            $hold = $holds[$id] ?? null;
            $lines[] = new ArchiveDossierLine(
                $id,
                (string) $row['public_reference'],
                (string) $row['category'],
                MunicipalRequestStatus::from((string) $row['status']),
                null === $row['decided_at'] ? null : new \DateTimeImmutable((string) $row['decided_at'], new \DateTimeZone('UTC')),
                null === $hold && $now >= $eligibleAt,
                null !== $hold,
                null !== $hold && $hold->isOverdue($now),
            );
        }

        return ['archiveEvent' => $event, 'viaDesignation' => $via, 'dossiers' => $lines, 'canManage' => !$via];
    }

    public function view(User $user, ArchiveConsultation $consultation, ArchiveAccess $access, \DateTimeImmutable $validUntil): ArchiveDossierView
    {
        $part = $access->dossier;
        $request = $part->request();
        $decision = $part->decision();
        $own = $part->municipality()->id();
        $permit = $this->permits->findByRequest($request->id());
        $authorization = null;
        foreach (null === $permit ? [] : $permit->authorizations() as $candidate) {
            if ($candidate->municipality()->id()->equals($own)) {
                $authorization = $candidate;
            }
        }
        $history = [];
        $others = 0;
        foreach (null === $permit ? [] : $this->revisions->forPermit($permit->id()) as $revision) {
            if ($revision->municipality()->id()->equals($own)) {
                $history[] = ['type' => $revision->type()->value, 'at' => $revision->occurredAt()];
            } else {
                ++$others;
            }
        }
        $plates = array_map(static fn ($v): string => $v->registration(), $request->vehicles());
        $attachments = array_map(static fn ($a): array => ['id' => $a->id()->toRfc4122(), 'name' => $a->originalName(), 'size' => $a->sizeBytes(), 'mime' => $a->mimeType()], $request->attachments());
        $hold = $access->hold;

        return new ArchiveDossierView(
            $consultation->id()->toRfc4122(),
            $part->id()->toRfc4122(),
            $validUntil,
            $request->reference()->value,
            $request->event()->name(),
            $request->categoryLabel(),
            $request->motifLabel(),
            $request->travelMode()->value,
            $request->submittedAt(),
            $request->lastName(),
            $request->firstName(),
            $request->address(),
            $request->email(),
            $request->phone(),
            $plates,
            $attachments,
            $part->status(),
            $decision?->decidedAt(),
            $decision?->outcome()->value,
            $decision?->refusalReasonLabel(),
            $decision?->refusalComment(),
            array_map(static fn ($z): string => $z->zoneLabel(), $part->zones()),
            $permit?->reference()->value,
            $authorization?->isRevoked(),
            $decision?->validFrom(),
            $decision?->validUntil(),
            $history,
            $others,
            $access->purgeEligibleAt,
            null !== $hold,
            null === $hold ? '' : $hold->reason()->label(),
            $hold?->reviewDueAt(),
            $access->membership->hasRole(MunicipalityRole::MunicipalityAdmin),
            $this->mentions($part->id()),
        );
    }

    /** @return list<array{fields: string, text: string, at: \DateTimeImmutable}> */
    private function mentions(Uuid $dossierId): array
    {
        $labels = [];
        foreach (\App\Rights\Domain\RectificationField::cases() as $field) {
            $labels[$field->value] = $field->label();
        }
        $out = [];
        foreach ($this->connection->fetchAllAssociative('SELECT fields, mention, created_at FROM archive_rectification_mention WHERE request_municipality_id = :d ORDER BY created_at, id', ['d' => $dossierId->toBinary()]) as $row) {
            $out[] = [
                'fields' => implode(', ', array_map(static fn (string $c): string => $labels[$c] ?? $c, explode(',', (string) $row['fields']))),
                'text' => (string) $row['mention'],
                'at' => new \DateTimeImmutable((string) $row['created_at'], new \DateTimeZone('UTC')),
            ];
        }

        return $out;
    }

    /**
     * Journal des consultations des dossiers de CETTE commune (administrateur seulement).
     *
     * @return list<ArchiveConsultation>
     */
    public function journal(User $user, string $municipalityId, int $limit = 200): array
    {
        $membership = $this->policy->administrator($user, $municipalityId, write: false);

        return $this->consultations->forMunicipality($membership->municipality()->id(), $limit);
    }
}
