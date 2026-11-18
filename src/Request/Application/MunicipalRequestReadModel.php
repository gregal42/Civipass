<?php

declare(strict_types=1);

namespace App\Request\Application;

use App\Request\Application\Read\DownloadableAttachment;
use App\Request\Application\Read\DuplicateMatch;
use App\Request\Application\Read\MunicipalRequestDetail;
use App\Request\Application\Read\MunicipalRequestPage;
use App\Request\Application\Read\MunicipalRequestQuery;
use App\Request\Application\Read\RecentDecision;
use App\Request\Application\Read\RequestEventOption;
use Symfony\Component\Uid\Uuid;

/**
 * Lectures de la consultation municipale. Toutes sont bornées PAR REQUÊTE à la commune donnée (portion communale) et
 * excluent les participations simplement invitées ; elles ne chargent jamais de zone d'une autre commune.
 */
interface MunicipalRequestReadModel
{
    public const MAX_DUPLICATES_PER_REQUEST = 5;

    /** @return list<RequestEventOption> événements de la commune (récents d'abord), invitations en attente exclues */
    public function eventsOf(Uuid $municipalityId): array;

    /** @return list<array{code: string, label: string}> */
    public function categories(): array;

    public function search(Uuid $municipalityId, RequestEventOption $event, MunicipalRequestQuery $query): MunicipalRequestPage;

    public function detail(Uuid $municipalityId, Uuid $requestId): ?MunicipalRequestDetail;

    /** @return array<string, int> demandes EN ATTENTE de la commune par identifiant d'événement non clos (invitations en attente exclues) */
    public function pendingCountsByEvent(Uuid $municipalityId): array;

    /** @return list<RecentDecision> décisions de la commune depuis `$since`, événements non clos, la plus récente d'abord */
    public function recentDecisions(Uuid $municipalityId, \DateTimeImmutable $since, int $limit): array;

    /**
     * Demandes possiblement liées (LOT 08.4) : pour chaque demande donnée (de la commune), les AUTRES demandes du MÊME événement dont la commune a une part non
     * « invitée » et qui concordent sur le nom + prénom, l'e-mail, le téléphone ou une plaque. Jamais une demande qui n'est pas visible pour la commune.
     *
     * @param list<Uuid> $requestIds
     *
     * @return array<string, list<DuplicateMatch>> identifiant de demande => correspondances (au plus `MAX_DUPLICATES_PER_REQUEST`)
     */
    public function duplicatesOf(Uuid $municipalityId, array $requestIds): array;

    public function attachment(Uuid $municipalityId, Uuid $requestId, Uuid $attachmentId): ?DownloadableAttachment;
}
