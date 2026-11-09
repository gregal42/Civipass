<?php

declare(strict_types=1);

namespace App\Notification\Domain;

enum ProvisionOutcome: string
{
    /** Lien créé et secret chiffré enregistré, dans une seule transaction. */
    case Provisioned = 'PROVISIONED';
    /** Rejeu : le secret existe déjà, aucun nouveau lien. */
    case AlreadyProvisioned = 'ALREADY_PROVISIONED';
    /** Ligne absente, déjà traitée ou sans lien à créer (nouvelle commune). */
    case Skipped = 'SKIPPED';
    /** Clé absente ou invalide : la ligne reste en attente. */
    case CryptoUnavailable = 'CRYPTO_UNAVAILABLE';
    /** Attente de clé dépassée (7 jours) : échec définitif. */
    case CryptoExpired = 'CRYPTO_EXPIRED';
    /** Laissez-passer plus utilisable : échec définitif, aucun lien créé. */
    case NotRelevant = 'NOT_RELEVANT';
}
