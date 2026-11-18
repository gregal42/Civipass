<?php

declare(strict_types=1);

namespace App\Request\Application;

use App\Request\Domain\PermitRequestDecision;
use App\Request\Domain\PermitRequestMunicipality;
use App\Request\Domain\RefusalNotice;
use App\Request\Domain\RefusalNoticeRepository;

/**
 * Crée l'information du demandeur refusé (LOT 08.5, DM-136) DANS la transaction de la décision de refus : une ligne par décision, ou rien si la décision est annulée.
 * Sans adresse e-mail : état `NO_RECIPIENT` immédiat et définitif (visible des agents). Aucune donnée personnelle n'est copiée dans la ligne.
 */
final readonly class RefusalNoticeRecorder
{
    public function __construct(private RefusalNoticeRepository $notices)
    {
    }

    public function record(PermitRequestMunicipality $part, PermitRequestDecision $decision, \DateTimeImmutable $now): void
    {
        $email = $part->request()->email();
        $this->notices->add(RefusalNotice::forDecision($decision, $part->municipality(), null !== $email && '' !== trim($email), $now));
    }
}
