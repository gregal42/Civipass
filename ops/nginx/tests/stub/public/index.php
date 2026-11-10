<?php
// Amont minimal des vérifications nginx (aucune logique Civipass) : réponses prévisibles selon le chemin et la méthode.
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
header('Content-Type: text/plain; charset=UTF-8');
if ('/login' === $path) {
    echo 'login';
    exit;
}
if (str_starts_with($path, '/communes')) {
    header('Location: /login', true, 302);
    exit;
}
if (1 === preg_match('#^/(v|laissez-passer|invitation|reset-password)/#', $path)) {
    http_response_code('GET' === $method || 'HEAD' === $method ? 404 : 405);
    exit;
}
if (1 === preg_match('#^/invitation$|^/reset-password$#', $path)) {
    http_response_code(404);
    exit;
}
http_response_code(404);
