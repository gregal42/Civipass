<?php

declare(strict_types=1);

namespace App\Request\Application;

use App\Request\Domain\PermitRequestDecision;

/**
 * Point d'intégration du LOT 05 (DT-044) : appelé par MunicipalDecisionApplier DANS la transaction de décision, pour chaque
 * approbation, après l'écriture de la décision et de l'audit.
 *
 * Contrat pour le LOT 05 : n'écrire ici que des lignes en base (laissez-passer, autorisation de la commune), de façon idempotente :
 * find-or-create du laissez-passer unique du dossier (UNIQUE(request_id)) sous verrou de la ligne du dossier, après les
 * participations et avant le compteur ; l'autorisation de la commune est unique par (laissez-passer, participation). La « première
 * approbation » n'est donc pas un drapeau fourni mais l'absence de laissez-passer au find-or-create (deux communes qui approuvent
 * simultanément ne peuvent pas toutes deux se croire premières). Aucun QR, PDF, e-mail ni appel externe dans la transaction : ces
 * effets partent après le commit (Messenger), à partir des lignes écrites ici.
 */
interface ApprovedPartListener
{
    public function partApproved(PermitRequestDecision $decision): void;
}
