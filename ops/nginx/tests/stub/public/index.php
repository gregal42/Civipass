<?php
// Amont minimal des vérifications nginx (aucune logique Civipass) : réponses prévisibles selon le chemin et la méthode.
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
header('Content-Type: text/plain; charset=UTF-8');
// --- Routes de la PWA de contrôle (vérifications du vhost dédié, verify-control-vhost.sh) : mêmes en-têtes que l'application, AUCUN cookie. ---
if (str_starts_with($path, '/controle')) {
    $csp = "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; manifest-src 'self'; worker-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'";
    foreach (['Referrer-Policy: no-referrer', 'X-Content-Type-Options: nosniff', 'X-Frame-Options: DENY', 'Permissions-Policy: camera=(self), microphone=(), geolocation=(), payment=(), usb=()', 'Content-Security-Policy: '.$csp, 'X-Robots-Tag: noindex, nofollow, noarchive'] as $h) {
        header($h);
    }
    $api = str_starts_with($path, '/controle/api/');
    header('Cache-Control: '.($api ? 'no-store, private' : 'no-cache, private'));
    $routes = [
        'GET /controle/' => [200, 'text/html; charset=UTF-8', '<title>Contrôle</title>'],
        'GET /controle/manifest.webmanifest' => [200, 'application/manifest+json', '{}'],
        'GET /controle/sw.js' => [200, 'text/javascript; charset=utf-8', '// sw'],
        'GET /controle/js/app.js' => [200, 'text/javascript; charset=utf-8', '// app'],
        'GET /controle/js/idb-store.js' => [200, 'text/javascript; charset=utf-8', '// idb'],
        'GET /controle/api/ping' => [200, 'application/json', '{"ok":true}'],
        'GET /controle/api/device' => [401, 'application/json', '{"error":"refused"}'],
        'POST /controle/api/activation' => [400, 'application/json', '{"error":"bad_request"}'],
        'POST /controle/api/sync' => [401, 'application/json', '{"error":"refused"}'],
    ];
    $key = ('HEAD' === $method ? 'GET' : $method).' '.$path;
    if (isset($routes[$key])) {
        [$status, $type, $body] = $routes[$key];
        http_response_code($status);
        header('Content-Type: '.$type);
        if ('/controle/sw.js' === $path) {
            header('Service-Worker-Allowed: /controle/');
        }
        echo $body;
        exit;
    }
    http_response_code(404);
    exit;
}
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
