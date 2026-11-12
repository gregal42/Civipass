<?php

declare(strict_types=1);

/**
 * Processus de test (appels simultanés au registre chaîné, LOT 05.6.7) : ajoute N lignes au fichier de registre donné, sans noyau Symfony.
 * Arguments : cheminDuRegistre N étiquette.
 */

use App\Retention\Application\PurgeRegisterFile;
use App\Retention\Application\RetentionSettings;

require dirname(__DIR__, 2).'/vendor/autoload.php';

[$script, $path, $count, $label] = $argv;
$register = new PurgeRegisterFile(new RetentionSettings(30, false, $path));
for ($i = 0; $i < (int) $count; ++$i) {
    $register->appendLine(['id' => sprintf('%s-%04d', $label, $i), 'kind' => 'NOTICE']);
}
echo json_encode(['ok' => true]);
