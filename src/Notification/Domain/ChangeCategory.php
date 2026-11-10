<?php

declare(strict_types=1);

namespace App\Notification\Domain;

/**
 * Catégorie d'un message, qui fixe son ordonnancement (D5, LOT 05.3.4) : la mise à disposition passe en premier et sans attente ; un changement
 * ordinaire est regroupé et ne part jamais avant elle ; une révocation et un raccourcissement (qui retirent des droits) sont prioritaires et jamais retardés.
 */
enum ChangeCategory: string
{
    case FirstIssuance = 'FIRST_ISSUANCE';
    case Ordinary = 'ORDINARY';
    case Revocation = 'REVOCATION';
}
