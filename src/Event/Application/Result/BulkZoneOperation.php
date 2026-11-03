<?php

declare(strict_types=1);

namespace App\Event\Application\Result;

/** Opérations groupées prévues par les décisions (DM-022, DM-024) ; aucune autre n'est autorisée. */
enum BulkZoneOperation: string
{
    case Suspend = 'SUSPEND';
    case Reactivate = 'REACTIVATE';
    case ApplyValidity = 'APPLY_VALIDITY';
}
