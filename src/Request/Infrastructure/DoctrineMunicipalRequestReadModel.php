<?php

declare(strict_types=1);

namespace App\Request\Infrastructure;

use App\Event\Domain\EventParticipation;
use App\Event\Domain\ParticipationStatus;
use App\Event\Domain\ZoneStatus;
use App\Request\Application\MunicipalRequestReadModel;
use App\Request\Application\Read\DownloadableAttachment;
use App\Request\Application\Read\DuplicateMatch;
use App\Request\Application\Read\DecisionView;
use App\Request\Application\Read\MunicipalRequestDetail;
use App\Request\Application\Read\MunicipalRequestPage;
use App\Request\Application\Read\MunicipalRequestQuery;
use App\Request\Application\Read\MunicipalRequestRow;
use App\Request\Application\Read\RecentDecision;
use App\Request\Application\Read\RefusalNoticeView;
use App\Request\Application\Read\RequestAttachmentView;
use App\Request\Application\Read\RequestEventOption;
use App\Request\Application\Read\RequestZoneView;
use App\Request\Domain\BeneficiaryCategory;
use App\Event\Domain\EventStatus;
use App\Request\Domain\MunicipalRequestStatus;
use App\Request\Domain\PermitRequestAttachment;
use App\Request\Domain\DecisionOutcome;
use App\Request\Domain\PermitRequestDecision;
use App\Request\Domain\RefusalNotice;
use App\Request\Domain\PermitRequestMunicipality;
use App\Request\Domain\PermitRequestVehicle;
use App\Request\Domain\PermitRequestZone;
use App\Request\Domain\PlateFormat;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

/**
 * Lectures DQL de la consultation municipale. Chaque requête part de la PORTION COMMUNALE de la commune demandée
 * (`request_permit_request_municipality.municipality_id`) : un dossier sans portion dans cette commune est invisible, et les
 * zones lues sont celles de cette portion seulement. Liste : 3 requêtes au plus (total, page + dossiers, zones de la page).
 */
#[AsAlias(MunicipalRequestReadModel::class)]
final readonly class DoctrineMunicipalRequestReadModel implements MunicipalRequestReadModel
{
    private const MAX_TERMS = 5;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function eventsOf(Uuid $municipalityId): array
    {
        /** @var list<array{id: Uuid, name: string, status: EventStatus, timezone: string, participation: ParticipationStatus}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('e.id AS id', 'e.name AS name', 'e.status AS status', 'e.timezone AS timezone', 'p.status AS participation')
            ->from(EventParticipation::class, 'p')
            ->join('p.event', 'e')
            ->where('IDENTITY(p.municipality) = :municipality')
            ->andWhere('p.status <> :invited')
            ->orderBy('e.startsAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setParameter('municipality', $municipalityId, 'uuid')
            ->setParameter('invited', ParticipationStatus::Invited)
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $r): RequestEventOption => new RequestEventOption($r['id']->toRfc4122(), $r['name'], $r['status'], $r['timezone'], $r['participation']), $rows);
    }

    public function categories(): array
    {
        /** @var list<array{code: string, label: string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('c.code AS code', 'c.label AS label')
            ->from(BeneficiaryCategory::class, 'c')
            ->orderBy('c.position', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function search(Uuid $municipalityId, RequestEventOption $event, MunicipalRequestQuery $query): MunicipalRequestPage
    {
        $total = (int) $this->filtered($municipalityId, $event, $query)->select('COUNT(prm.id)')->getQuery()->getSingleScalarResult();
        $page = max(1, min($query->page, (int) ceil($total / MunicipalRequestQuery::PAGE_SIZE)));
        /** @var list<PermitRequestMunicipality> $portions */
        $portions = $this->filtered($municipalityId, $event, $query)
            ->select('prm', 'r')
            ->orderBy('r.submittedAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setFirstResult(($page - 1) * MunicipalRequestQuery::PAGE_SIZE)
            ->setMaxResults(MunicipalRequestQuery::PAGE_SIZE)
            ->getQuery()
            ->getResult();

        $zones = [];
        if ([] !== $portions) {
            /** @var list<PermitRequestZone> $requested */
            $requested = $this->entityManager->createQueryBuilder()
                ->select('z')
                ->from(PermitRequestZone::class, 'z')
                ->where('IDENTITY(z.requestMunicipality) IN (:portions)')
                ->orderBy('z.zoneLabel', 'ASC')
                ->setParameter('portions', array_map(static fn (PermitRequestMunicipality $p): string => $p->id()->toBinary(), $portions), ArrayParameterType::BINARY)
                ->getQuery()
                ->getResult();
            foreach ($requested as $zone) {
                $zones[$zone->requestMunicipality()->id()->toRfc4122()][] = $zone->zoneLabel();
            }
        }

        $duplicates = $this->duplicatesOf($municipalityId, array_map(static fn (PermitRequestMunicipality $p): Uuid => $p->request()->id(), $portions));
        $items = array_map(static fn (PermitRequestMunicipality $p): MunicipalRequestRow => new MunicipalRequestRow(
            $p->request()->id()->toRfc4122(),
            $p->request()->reference()->value,
            $p->request()->lastName(),
            $p->request()->firstName(),
            $p->request()->submittedAt(),
            $p->request()->categoryLabel(),
            $zones[$p->id()->toRfc4122()] ?? [],
            $p->status(),
            \count($duplicates[$p->request()->id()->toRfc4122()] ?? []),
        ), $portions);

        return new MunicipalRequestPage($items, $total, $page);
    }

    public function detail(Uuid $municipalityId, Uuid $requestId): ?MunicipalRequestDetail
    {
        $portion = $this->portion($municipalityId, $requestId);
        if (null === $portion) {
            return null;
        }
        $request = $portion->request();
        $event = $request->event();
        $zones = array_map(static fn (PermitRequestZone $z): string => $z->zoneLabel(), $portion->zones());
        sort($zones);
        $zoneViews = array_map(static fn (PermitRequestZone $z): RequestZoneView => new RequestZoneView($z->zoneLabel(), $z->zone()->validityMode(), ZoneStatus::Suspended === $z->zone()->status()), $portion->zones());
        usort($zoneViews, static fn (RequestZoneView $a, RequestZoneView $b): int => strcmp($a->label, $b->label));
        $decision = $portion->decision();

        return new MunicipalRequestDetail(
            $request->id()->toRfc4122(),
            $request->reference()->value,
            $event->id()->toRfc4122(),
            $event->name(),
            $event->timezone()->identifier(),
            $request->submittedAt(),
            $portion->status(),
            $portion->participation()->status(),
            $request->lastName(),
            $request->firstName(),
            $request->address(),
            $request->email(),
            $request->phone(),
            $request->categoryLabel(),
            $request->motifLabel(),
            $request->motifDetail(),
            $zones,
            $request->travelMode(),
            array_map(static fn (PermitRequestVehicle $v): string => self::plate($v), $request->vehicles()),
            array_map(static fn (PermitRequestAttachment $a): RequestAttachmentView => RequestAttachmentView::of($a->id()->toRfc4122(), $a->originalName(), $a->mimeType(), $a->sizeBytes()), $request->attachments()),
            $event->status(),
            $event->endsAt(),
            $zoneViews,
            null === $decision ? null : new DecisionView(
                $decision->outcome(),
                $decision->path(),
                $decision->decidedAt(),
                trim($decision->decidedBy()->firstName().' '.$decision->decidedBy()->lastName()),
                $decision->durationMode(),
                $decision->validFrom(),
                $decision->validUntil(),
                $decision->refusalReasonLabel(),
                $decision->refusalComment(),
            ),
            $this->duplicatesOf($municipalityId, [$request->id()])[$request->id()->toRfc4122()] ?? [],
            $this->noticeView($decision),
        );
    }

    private function noticeView(?PermitRequestDecision $decision): ?RefusalNoticeView
    {
        if (null === $decision || DecisionOutcome::Refused !== $decision->outcome()) {
            return null;
        }
        $notice = $this->entityManager->getRepository(RefusalNotice::class)->findOneBy(['decision' => $decision]);

        return $notice instanceof RefusalNotice ? new RefusalNoticeView($notice->status(), $notice->processedAt(), $notice->attempts(), $notice->manualChannel(), $notice->lastErrorCode()) : null;
    }

    public function duplicatesOf(Uuid $municipalityId, array $requestIds): array
    {
        if ([] === $requestIds) {
            return [];
        }
        // Une seule requête pour la page, en QUATRE jointures d'ÉGALITÉ réunies (UNION ALL) plutôt qu'une jointure à conditions « OU » : chaque critère est une
        // jointure d'égalité que MySQL résout par table de hachage, SANS index (une passe sur les demandes de l'événement au lieu d'un balayage par demande de la page).
        // Les autres demandes sont limitées à celles VISIBLES pour la commune (part non « invitée »). Égalités sur les valeurs normalisées au dépôt (plaque,
        // téléphone E.164) ; nom et e-mail comparés sans casse ni accents (collation de la base).
        $visible = <<<'SQL'
            JOIN request_permit_request_municipality pm ON pm.request_id = b.id AND pm.municipality_id = :municipality
            JOIN event_participation ep ON ep.id = pm.participation_id AND ep.status <> 'INVITED'
            SQL;
        $columns = 'a.id AS request_id, b.id AS other_id, b.public_reference AS other_reference, b.submitted_at AS other_submitted_at, pm.status AS other_status';
        $sql = <<<SQL
            SELECT {$columns}, 'email' AS criterion FROM request_permit_request a JOIN request_permit_request b ON b.event_id = a.event_id AND b.email = a.email AND b.id <> a.id {$visible}
              WHERE a.id IN (:ids) AND a.email IS NOT NULL
            UNION ALL
            SELECT {$columns}, 'phone' FROM request_permit_request a JOIN request_permit_request b ON b.event_id = a.event_id AND b.phone = a.phone AND b.id <> a.id {$visible}
              WHERE a.id IN (:ids) AND a.phone IS NOT NULL
            UNION ALL
            SELECT {$columns}, 'name' FROM request_permit_request a JOIN request_permit_request b ON b.event_id = a.event_id AND b.last_name = a.last_name AND b.first_name = a.first_name AND b.id <> a.id {$visible}
              WHERE a.id IN (:ids)
            UNION ALL
            SELECT {$columns}, 'plate' FROM request_permit_request a
              JOIN request_permit_request_vehicle va ON va.request_id = a.id
              JOIN request_permit_request_vehicle vb ON vb.registration = va.registration AND vb.request_id <> a.id
              JOIN request_permit_request b ON b.id = vb.request_id AND b.event_id = a.event_id {$visible}
              WHERE a.id IN (:ids)
            ORDER BY request_id, other_submitted_at DESC, other_id DESC
            LIMIT 4000
            SQL;
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            $sql,
            ['municipality' => $municipalityId->toBinary(), 'ids' => array_map(static fn (Uuid $id): string => $id->toBinary(), $requestIds)],
            ['municipality' => \Doctrine\DBAL\ParameterType::BINARY, 'ids' => ArrayParameterType::BINARY],
        );
        /** @var array<string, array<string, array{reference: string, at: string, status: string, criteria: list<string>}>> $grouped */
        $grouped = [];
        foreach ($rows as $row) {
            $key = Uuid::fromBinary((string) $row['request_id'])->toRfc4122();
            $other = Uuid::fromBinary((string) $row['other_id'])->toRfc4122();
            $grouped[$key][$other] ??= ['reference' => (string) $row['other_reference'], 'at' => (string) $row['other_submitted_at'], 'status' => (string) $row['other_status'], 'criteria' => []];
            $grouped[$key][$other]['criteria'][] = (string) $row['criterion'];
        }
        $matches = [];
        foreach ($grouped as $key => $others) {
            foreach ($others as $otherId => $o) {
                if (\count($matches[$key] ?? []) >= self::MAX_DUPLICATES_PER_REQUEST) {
                    break;
                }
                $matches[$key][] = new DuplicateMatch(
                    $otherId,
                    $o['reference'],
                    new \DateTimeImmutable($o['at'], new \DateTimeZone('UTC')),
                    MunicipalRequestStatus::from($o['status']),
                    array_values(array_unique($o['criteria'])),
                );
            }
        }

        return $matches;
    }

    public function pendingCountsByEvent(Uuid $municipalityId): array
    {
        /** @var list<array{event: Uuid|string, n: int|string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(r.event) AS event', 'COUNT(prm.id) AS n')
            ->from(PermitRequestMunicipality::class, 'prm')
            ->join('prm.request', 'r')
            ->join('r.event', 'e')
            ->join('prm.participation', 'p')
            ->where('IDENTITY(prm.municipality) = :municipality')
            ->andWhere('prm.status = :pending')
            ->andWhere('p.status <> :invited')
            ->andWhere('e.status <> :closed')
            ->groupBy('r.event')
            ->setParameter('municipality', $municipalityId, 'uuid')
            ->setParameter('pending', MunicipalRequestStatus::Pending)
            ->setParameter('invited', ParticipationStatus::Invited)
            ->setParameter('closed', EventStatus::Closed)
            ->getQuery()
            ->getResult();
        $counts = [];
        foreach ($rows as $row) {
            $id = $row['event'] instanceof Uuid ? $row['event']->toRfc4122() : (Uuid::isValid((string) $row['event']) ? Uuid::fromString((string) $row['event'])->toRfc4122() : Uuid::fromBinary((string) $row['event'])->toRfc4122());
            $counts[$id] = (int) $row['n'];
        }

        return $counts;
    }

    public function recentDecisions(Uuid $municipalityId, \DateTimeImmutable $since, int $limit): array
    {
        /** @var list<PermitRequestDecision> $decisions */
        $decisions = $this->entityManager->createQueryBuilder()
            ->select('d', 'prm', 'r', 'e')
            ->from(PermitRequestDecision::class, 'd')
            ->join('d.requestMunicipality', 'prm')
            ->join('prm.request', 'r')
            ->join('r.event', 'e')
            ->where('IDENTITY(d.municipality) = :municipality')
            ->andWhere('d.decidedAt >= :since')
            ->andWhere('e.status <> :closed')
            ->orderBy('d.decidedAt', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->setParameter('municipality', $municipalityId, 'uuid')
            ->setParameter('since', $since)
            ->setParameter('closed', EventStatus::Closed)
            ->getQuery()
            ->getResult();

        return array_map(static fn (PermitRequestDecision $d): RecentDecision => new RecentDecision(
            $d->requestMunicipality()->request()->id()->toRfc4122(),
            $d->requestMunicipality()->request()->reference()->value,
            $d->requestMunicipality()->request()->lastName(),
            $d->requestMunicipality()->request()->firstName(),
            $d->outcome(),
            $d->decidedAt(),
            $d->requestMunicipality()->request()->event()->name(),
        ), $decisions);
    }

    public function attachment(Uuid $municipalityId, Uuid $requestId, Uuid $attachmentId): ?DownloadableAttachment
    {
        $portion = $this->portion($municipalityId, $requestId);
        if (null === $portion) {
            return null;
        }
        foreach ($portion->request()->attachments() as $attachment) {
            if ($attachment->id()->equals($attachmentId)) {
                return new DownloadableAttachment($attachment->storageKey(), $attachment->mimeType(), RequestAttachmentView::displayName($attachment->originalName()), $attachment->sizeBytes());
            }
        }

        return null;
    }

    /** Plaque normalisée en base, présentée comme à la saisie : AB-123-CD (SIV) ou 1234 AB 56 (FNI). */
    private static function plate(PermitRequestVehicle $vehicle): string
    {
        $value = $vehicle->registration();

        return (PlateFormat::Siv === $vehicle->format()
            ? preg_replace('/^([A-Z]{2})([0-9]{3})([A-Z]{2})$/', '$1-$2-$3', $value)
            : preg_replace('/^([0-9]{1,4})([A-Z]{1,3})([0-9]{2}|2A|2B)$/', '$1 $2 $3', $value)) ?? $value;
    }

    private function portion(Uuid $municipalityId, Uuid $requestId): ?PermitRequestMunicipality
    {
        $portion = $this->entityManager->createQueryBuilder()
            ->select('prm', 'r', 'p', 'e')
            ->from(PermitRequestMunicipality::class, 'prm')
            ->join('prm.request', 'r')
            ->join('prm.participation', 'p')
            ->join('r.event', 'e')
            ->where('IDENTITY(prm.municipality) = :municipality')
            ->andWhere('r.id = :request')
            ->andWhere('p.status <> :invited')
            ->setParameter('municipality', $municipalityId, 'uuid')
            ->setParameter('request', $requestId, 'uuid')
            ->setParameter('invited', ParticipationStatus::Invited)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $portion instanceof PermitRequestMunicipality ? $portion : null;
    }

    private function filtered(Uuid $municipalityId, RequestEventOption $event, MunicipalRequestQuery $query): QueryBuilder
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->from(PermitRequestMunicipality::class, 'prm')
            ->join('prm.request', 'r')
            ->join('prm.participation', 'p')
            ->where('IDENTITY(prm.municipality) = :municipality')
            ->andWhere('IDENTITY(r.event) = :event')
            ->andWhere('p.status <> :invited')
            ->setParameter('municipality', $municipalityId, 'uuid')
            ->setParameter('event', Uuid::fromString($event->eventId), 'uuid')
            ->setParameter('invited', ParticipationStatus::Invited);
        if (null !== $query->status) {
            $qb->andWhere('prm.status = :status')->setParameter('status', $query->status);
        }
        if (null !== $query->categoryCode) {
            $qb->join('r.category', 'c')->andWhere('c.code = :category')->setParameter('category', $query->categoryCode);
        }
        $terms = \array_slice(preg_split('/\s+/u', trim($query->search), -1, \PREG_SPLIT_NO_EMPTY) ?: [], 0, self::MAX_TERMS);
        foreach ($terms as $i => $term) {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $qb->andWhere(sprintf('(r.publicReference LIKE :ref%1$d OR r.lastName LIKE :txt%1$d OR r.firstName LIKE :txt%1$d)', $i))
                ->setParameter('ref'.$i, mb_strtoupper($like))
                ->setParameter('txt'.$i, $like);
        }

        return $qb;
    }
}
