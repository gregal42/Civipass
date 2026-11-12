<?php

declare(strict_types=1);

namespace App\Archive\Domain;

enum ConsultationAction: string
{
    case DossierOpened = 'DOSSIER_OUVERT';
    case AttachmentDownloaded = 'PIECE_TELECHARGEE';
}
