<?php

declare(strict_types=1);

namespace App\Municipality\Application;

/** La source du référentiel est injoignable ou sa réponse est inexploitable : rien n'est modifié. */
final class NationalCommuneSourceUnavailable extends \RuntimeException
{
}
