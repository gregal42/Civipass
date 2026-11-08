<?php

declare(strict_types=1);

namespace App\Request\Infrastructure;

use App\Event\Domain\EventParticipation;
use App\Event\Domain\ParticipationStatus;
use App\Event\Domain\ZoneStatus;
use App\Request\Application\MunicipalRequestReadModel;
use App\Request\Application\Read\DownloadableAttachment;
use App\Request\Application\Read\DecisionView;
use App\Request\Application\Read\MunicipalRequestDetail;
use App\Request\Application\Read\MunicipalRequestPage;
use App\Request\Application\Read\MunicipalRequestQuery;
use App\Request\Application\Read\MunicipalRequestRow;
use App\Request\Application\Read\RequestAttachmentView;
use App\Request\Application\Read\RequestEventOption;
use App\Request\Application\Read\RequestZoneView;
use App\Request\Domain\BeneficiaryCategory;
use App\Request\Domain\PermitRequestAttachment;
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
        /** @var list<array{id: Uuid, name: string, status: \App\Event\Domain\EventStatus, timezone: string, participation: ParticipationStatus}> $rows */
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

        $items = array_map(static fn (PermitRequestMunicipality $p): MunicipalRequestRow => new MunicipalRequestRow(
            $p->request()->id()->toRfc4122(),
            $p->request()->reference()->value,
            $p->request()->lastName(),
            $p->request()->firstName(),
            $p->request()->submittedAt(),
            $p->request()->categoryLabel(),
            $zones[$p->id()->toRfc4122()] ?? [],
            $p->status(),
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
        );
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
