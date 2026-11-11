<?php

declare(strict_types=1);

namespace App\Notification\Domain;

/** Codes courts d'anomalie d'une ligne d'outbox (jamais un message d'exception ni une donnée personnelle). */
enum OutboxErrorCode: string
{
    /** Clé de chiffrement absente ou invalide : la ligne attend, aucune tentative d'envoi n'est consommée. */
    case CryptoUnavailable = 'CRYPTO_UNAVAILABLE';
    /** Clé indisponible depuis plus de 7 jours à compter de la création de l'intention : échec définitif, aucun lien créé. */
    case CryptoUnavailableExpired = 'CRYPTO_UNAVAILABLE_EXPIRED';
    /** Le laissez-passer n'est plus utilisable : annoncer sa mise à disposition serait faux. */
    case NotRelevant = 'NOT_RELEVANT';
    /** Erreur SMTP temporaire : nouvel essai planifié. */
    case SmtpTemporary = 'SMTP_TEMPORARY';
    /** Refus définitif du serveur SMTP (adresse inexistante, message refusé). */
    case SmtpPermanent = 'SMTP_PERMANENT';
    /** Adresse du destinataire syntaxiquement invalide : jamais réessayée. */
    case AddressInvalid = 'ADDRESS_INVALID';
    /** Six tentatives épuisées. */
    case SmtpExhausted = 'SMTP_EXHAUSTED';
    /** 24 heures écoulées depuis la première tentative. */
    case SmtpWindowExpired = 'SMTP_WINDOW_EXPIRED';
    /** Le lien automatique a été révoqué avant l'envoi : le message annoncerait un lien mort. */
    case LinkRevoked = 'LINK_REVOKED';
    /** Secret chiffré absent ou illisible alors que la clé est valide : impossible d'envoyer le lien. */
    case SecretUnavailable = 'SECRET_UNAVAILABLE';
    /** Message d'extension REDONDANT : l'autorisation figurait déjà dans un message envoyé (état `SUPPRESSED`). */
    case AlreadyIncluded = 'ALREADY_INCLUDED';
    /** Message d'extension sans mise à disposition réussie : l'annoncer comme « en plus du lien reçu » serait faux (état `SUPPRESSED`). */
    case FirstNotDelivered = 'FIRST_NOT_DELIVERED';
    /** Message d'extension INEXACT : les droits concernés ont été révoqués ou ont expiré avant l'envoi (état `SUPPRESSED`). */
    case Inaccurate = 'INACCURATE';
    /** Raccourcissement ou retrait de zones DEVENU OBSOLÈTE : l'autorisation a été révoquée avant l'envoi ; la révocation, elle, est annoncée (état `SUPPRESSED`, trace conservée et auditée). */
    case SupersededByRevocation = 'SUPERSEDED_BY_REVOCATION';
    /** Secret chiffré conservé plus de 48 heures sans traitement définitif : supprimé, lien révoqué. */
    case SecretExpired = 'SECRET_EXPIRED';
    /** Événement clos (LOT 05.6.2) : aucune notification opérationnelle n'est plus émise, prioritaire ou non. */
    case EventClosed = 'EVENT_CLOSED';
}
