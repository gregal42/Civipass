<?php

declare(strict_types=1);

namespace App\Request\Application;

/**
 * Accusé de réception d'un dépôt public, envoyé à l'adresse fournie par le citoyen (si elle existe). Contenu minimal : référence,
 * nom de l'événement, confirmation du dépôt ; aucun QR code, aucune donnée du dossier, aucun lien de suivi. L'envoi ne doit
 * jamais faire échouer un dépôt déjà validé : l'appelant ignore toute erreur de l'implémentation.
 */
interface ReceiptNotifier
{
    public function sendReceipt(string $email, string $reference, string $eventName): void;
}
