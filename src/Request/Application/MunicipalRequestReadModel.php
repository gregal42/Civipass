<?php

declare(strict_types=1);

namespace App\Request\Application;

use App\Request\Application\Read\DownloadableAttachment;
use App\Request\Application\Read\MunicipalRequestDetail;
use App\Request\Application\Read\MunicipalRequestPage;
use App\Request\Application\Read\MunicipalRequestQuery;
use App\Request\Application\Read\RequestEventOption;
use Symfony\Component\Uid\Uuid;

/**
 * Lectures de la consultation municipale. Toutes sont bornées PAR REQUÊTE à la commune donnée (portion communale) et
 * excluent les participations simplement invitées ; elles ne chargent jamais de zone d'une autre commune.
 */
interface MunicipalRequestReadModel
{
    /** @return list<RequestEventOption> événements de la commune (récents d'abord), invitations en attente exclues */
    public function eventsOf(Uuid $municipalityId): array;

    /** @return list<array{code: string, label: string}> */
    public function categories(): array;

    public function search(Uuid $municipalityId, RequestEventOption $event, MunicipalRequestQuery $query): MunicipalRequestPage;

    public function detail(Uuid $municipalityId, Uuid $requestId): ?MunicipalRequestDetail;

    public function attachment(Uuid $municipalityId, Uuid $requestId, Uuid $attachmentId): ?DownloadableAttachment;
}
