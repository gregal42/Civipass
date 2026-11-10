<?php

declare(strict_types=1);

namespace App\Permit\Domain;

/** Événements du laissez-passer qui donnent lieu à une notification du bénéficiaire (LOT 05.3.1, DM-117). */
enum NotificationKind: string
{
    /** Première approbation : mise à disposition du laissez-passer. */
    case PermitIssued = 'PERMIT_ISSUED';
    /** Approbation d'une commune supplémentaire. */
    case AuthorizationAdded = 'AUTHORIZATION_ADDED';
    /** Révocation d'une autorisation communale (LOT 05.5.1) : prioritaire, jamais regroupée ; porte l'identifiant de sa révision. */
    case AuthorizationRevoked = 'AUTHORIZATION_REVOKED';
    /** Prolongation ou reprise d'une autorisation (LOT 05.5.2) : ordinaire, regroupée ; porte l'identifiant de sa révision. */
    case PermitExtended = 'PERMIT_EXTENDED';
    /** Reprise d'une autorisation APRÈS EXPIRATION (LOT 05.5.2) : réactivation avec nouveau début et nouvelle échéance ; ordinaire, regroupée. */
    case PermitResumed = 'PERMIT_RESUMED';
    /** Zones ajoutées ou réintégrées (LOT 05.5.3) : ordinaire, regroupée. */
    case ZonesChanged = 'ZONES_CHANGED';
    /** Au moins un retrait de zone (LOT 05.5.3) : PRIORITAIRE, jamais regroupée (retire des droits) ; décrit aussi les ajouts et réintégrations d'une opération mixte. */
    case ZonesRemoved = 'ZONES_REMOVED';
    /** Plaques ajoutées ou réactivées (LOT 05.5.4) : ordinaire, regroupée ; niveau LAISSEZ-PASSER (aucune autorisation), aucune plaque dans le message. */
    case VehiclesChanged = 'VEHICLES_CHANGED';
    /** Au moins un retrait ou un remplacement de plaque (LOT 05.5.4) : PRIORITAIRE, jamais regroupée ; décrit aussi les ajouts et réactivations d'une opération mixte. */
    case VehiclesRemoved = 'VEHICLES_REMOVED';
    /** Remplacement du QR code (LOT 05.5.5) : PRIORITAIRE, jamais regroupée ; niveau laissez-passer ; jamais un jeton ni un lien de QR dans le message. */
    case QrReplaced = 'QR_REPLACED';
    /** Raccourcissement d'une autorisation (LOT 05.5.2) : PRIORITAIRE, jamais regroupée ni retardée (retire des droits). */
    case PermitShortened = 'PERMIT_SHORTENED';
}
