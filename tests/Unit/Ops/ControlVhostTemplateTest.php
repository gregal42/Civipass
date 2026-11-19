<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ops;

use App\Control\UI\Http\ControlHeadersListener;
use App\Control\UI\Http\ControlRouteAllowlist;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Vhost Nginx dédié au contrôle (LOT 10, V2) : la liste blanche du gabarit Nginx est la MÊME que celle de l'application (ControlRouteAllowlist), la CSP et les
 * en-têtes de sécurité du gabarit sont ceux de l'application, la génération refuse les configurations incohérentes et le contrôle de déploiement vérifie G1, G2 et
 * le domaine de la PWA. (Le comportement réel de nginx est vérifié par ops/nginx/tests/verify-control-vhost.sh, qui exige Docker.).
 */
final class ControlVhostTemplateTest extends TestCase
{
    private const OPS = __DIR__.'/../../../ops/nginx';

    private static function template(): string
    {
        return (string) file_get_contents(self::OPS.'/templates/20-civipass-control.conf.template');
    }

    /** @return list<string> expressions PCRE des entrées « 1 » de la carte de liste blanche */
    private static function nginxAllowedPatterns(): array
    {
        self::assertSame(1, preg_match('/map "\$request_method:\$request_uri" \$civ_ctl_allowed \{(.*?)\n\}/s', self::template(), $block), 'carte de liste blanche');
        self::assertStringContainsString('default 0;', $block[1], 'tout est refusé par défaut');
        preg_match_all('/^\s*"~(.+)"\s+1;\s*$/m', $block[1], $matches);

        return array_map(static fn (string $p): string => '#'.str_replace('#', '\#', $p).'#', $matches[1]);
    }

    private static function nginxAllows(string $method, string $uri): bool
    {
        foreach (self::nginxAllowedPatterns() as $pattern) {
            if (1 === preg_match($pattern, $method.':'.$uri)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, array{string, string}> */
    public static function corpus(): array
    {
        $cases = [];
        foreach (ControlRouteAllowlist::EXACT as [$method, $path]) {
            $cases[$method.' '.$path] = [$method, $path];
            $cases[$method.' '.$path.' (requête)'] = [$method, $path.'?x=1'];
        }
        foreach (['GET /controle/js/app.js', 'GET /controle/js/idb-store.js', 'GET /controle/js/App.js', 'GET /controle/js/a/b.js', 'GET /controle/js/../x.js', 'GET /controle/js/.js',
            'GET /assets/styles/civipass/tokens-RHoYLDS.css', 'GET /assets/vendor/jsqr/jsQR-VM34fds.js', 'GET /assets/images/control-icon-192-I8oNt_R.png', 'GET /assets/fonts/AtkinsonHyperlegibleNext-Bold.woff2',
            'GET /assets/images/other.png', 'GET /assets/app.js', 'GET /assets/', 'GET /assets/styles/../../x', 'GET /assets/styles/%2e%2e/x', 'GET /assets/fonts/../../index.php',
            'GET /login', 'GET /communes', 'GET /v/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'GET /', 'GET /controle', 'GET /controle/inconnu', 'GET //controle/', 'GET /controle//api/ping',
            'POST /controle/', 'POST /controle/api/ping', 'GET /controle/api/activation', 'GET /controle/api/sync', 'PUT /controle/api/sync', 'DELETE /controle/api/device', 'OPTIONS /controle/api/ping',
            'POST /assets/styles/x.css', 'GET /index.php', 'GET /favicon.ico', 'HEAD /controle/', 'HEAD /controle/api/sync'] as $spec) {
            [$method, $path] = explode(' ', $spec, 2);
            $cases[$spec] = [$method, $path];
        }

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('corpus')]
    public function testTheNginxAllowlistAndTheApplicationAllowlistAgreeOnEveryRequest(string $method, string $uri): void
    {
        $path = (string) parse_url($uri, \PHP_URL_PATH);

        self::assertSame(ControlRouteAllowlist::allows($method, $path), self::nginxAllows($method, $uri), $method.' '.$uri.' : listes blanches divergentes');
    }

    public function testTheTemplateCspAndSecurityHeadersAreTheOnesOfTheApplication(): void
    {
        $template = self::template();

        self::assertStringContainsString('add_header Content-Security-Policy "'.ControlHeadersListener::CSP.'" always;', $template, 'CSP identique à ControlHeadersListener::CSP');
        foreach (['Referrer-Policy "no-referrer"', 'X-Content-Type-Options "nosniff"', 'X-Frame-Options "DENY"', 'Permissions-Policy "camera=(self), microphone=(), geolocation=(), payment=(), usb=()"', 'Strict-Transport-Security "max-age=31536000"'] as $header) {
            self::assertStringContainsString('add_header '.$header.' always;', $template);
        }
        foreach (['X-Content-Type-Options', 'Referrer-Policy', 'X-Frame-Options', 'Permissions-Policy', 'Content-Security-Policy'] as $hidden) {
            self::assertStringContainsString('fastcgi_hide_header '.$hidden.';', $template, 'une seule instance : l’en-tête de l’application est retiré');
        }
        self::assertStringNotContainsString('includeSubDomains', $template);
        self::assertStringNotContainsString('preload', $template);
        self::assertStringNotContainsString('limit_except', $template, 'refus par la carte : 404 générique, jamais 403');
        self::assertMatchesRegularExpression('#\n    location \^~ /assets/ \{\n        if \(\$civ_ctl_allowed = 0\) \{ return 404; \}#', $template);
        self::assertStringContainsString('rewrite ^ /index.php last;', $template, 'contrôleur frontal par réécriture : try_files servirait index.php en statique');
        self::assertDoesNotMatchRegularExpression('#location (?!@civ_)[^\n]*\{[^}]*add_header#', $template, 'aucun add_header dans un location ordinaire (seuls les emplacements nommés @civ_* générés par nginx les répètent)');
        self::assertStringContainsString('error_page 404 = @civ_ctl_denied;', $template, '404 générés par nginx : neutres et non mis en cache');
        self::assertStringContainsString('if ($civ_sni_mismatch) { return 421; }', $template, 'politique SNI / Host');
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function renderedFiles(array $overrides = [], bool $control = true): array
    {
        $env = ['CIVIPASS_DOMAIN' => 'civipass.test', 'CONTROL_DOMAIN' => 'controle.civipass.test', 'TLS_CERTIFICATE' => '/etc/nginx/tls/cert.pem', 'TLS_CERTIFICATE_KEY' => '/etc/nginx/tls/key.pem',
            'TLS_CONTROL_CERTIFICATE' => '/etc/nginx/tls/ctl-cert.pem', 'TLS_CONTROL_CERTIFICATE_KEY' => '/etc/nginx/tls/ctl-key.pem', 'DOCROOT' => '/var/www/html/public', 'PHP_FPM_UPSTREAM' => 'php:9000',
            'LOG_DIR' => '/var/log/civipass/nginx', 'NGINX_USER' => 'nginx'];
        if (!$control) {
            unset($env['CONTROL_DOMAIN'], $env['TLS_CONTROL_CERTIFICATE'], $env['TLS_CONTROL_CERTIFICATE_KEY']);
        }
        $env = $overrides + $env;
        $dir = sys_get_temp_dir().'/civipass-render-'.bin2hex(random_bytes(4));
        $file = $dir.'.env';
        file_put_contents($file, implode("\n", array_map(static fn (string $k, string $v): string => $k.'='.$v, array_keys($env), $env))."\n");
        $process = new Process(['bash', self::OPS.'/bin/render.sh', '--env', $file, '--out', $dir, '--allow-example']);
        $process->mustRun();
        $files = [];
        foreach (['nginx.conf', 'conf.d/00-civipass-logging.conf', 'conf.d/10-civipass-site.conf', 'conf.d/05-civipass-control-maps.conf', 'conf.d/20-civipass-control.conf'] as $name) {
            if (is_file($dir.'/'.$name)) {
                $files[$name] = (string) file_get_contents($dir.'/'.$name);
            }
        }
        (new \Symfony\Component\Filesystem\Filesystem())->remove([$dir, $file]);

        return $files;
    }

    /** « Aucun changement pour une installation sans domaine de contrôle dédié » : les fichiers générés sont identiques, à l'octet, à ceux d'avant le garde-fou. */
    public function testWithoutAControlDomainTheGeneratedFilesAreByteIdenticalToTheReferenceFiles(): void
    {
        $files = $this->renderedFiles(control: false);

        self::assertSame(['nginx.conf', 'conf.d/00-civipass-logging.conf', 'conf.d/10-civipass-site.conf'], array_keys($files), 'aucun fichier de contrôle');
        self::assertStringEqualsFile(self::OPS.'/tests/golden/10-civipass-site.nocontrol.conf', $files['conf.d/10-civipass-site.conf']);
        self::assertStringEqualsFile(self::OPS.'/tests/golden/nginx.nocontrol.conf', $files['nginx.conf']);
        self::assertStringEqualsFile(self::OPS.'/tests/golden/00-civipass-logging.nocontrol.conf', $files['conf.d/00-civipass-logging.conf']);
        self::assertStringNotContainsString('controle', $files['conf.d/10-civipass-site.conf']);
        self::assertStringNotContainsString('CONTROL_', $files['conf.d/10-civipass-site.conf']);
    }

    public function testWithAControlDomainTheManagementVhostRefusesControlRoutesAndTheSniHostPolicyApplies(): void
    {
        $files = $this->renderedFiles();
        $site = $files['conf.d/10-civipass-site.conf'];

        self::assertStringContainsString('map "$ssl_server_name:$host" $civ_sni_mismatch', $files['conf.d/05-civipass-control-maps.conf']);
        self::assertStringContainsString('if ($civ_sni_mismatch) { return 421; }', $site, 'gestion : SNI / Host incohérents → 421');
        self::assertStringContainsString('location = /controle {', $site);
        self::assertStringContainsString('location ^~ /controle/ {', $site);
        self::assertStringContainsString('return 421 "Misdirected Request";', $site, 'serveur par défaut : hôte inconnu derrière un SNI valide → 421 neutre');
        self::assertStringNotContainsString('CONTROL_GUARD', $site);
        self::assertSame(2, substr_count($site, 'location @civ_'), 'deux emplacements nommés (refus et 421)');
        self::assertLessThan(strpos($site, 'location ~* "\.(?:jpg'), strpos($site, 'location ^~ /controle/'), 'le refus précède les statiques (le préfixe ^~ l\'emporte de toute façon)');
        // Le fichier des cartes est chargé AVANT les vhosts qui utilisent la variable (nginx exige la définition préalable).
        self::assertSame(-1, strcmp('05-civipass-control-maps.conf', '10-civipass-site.conf'));
    }

    public function testEveryNginxGeneratedResponseCarriesTheSameSecurityHeadersAndNoStore(): void
    {
        $files = $this->renderedFiles();
        $control = $files['conf.d/20-civipass-control.conf'];
        $serverHeaders = ['Strict-Transport-Security "max-age=31536000"', 'X-Content-Type-Options "nosniff"', 'Referrer-Policy "no-referrer"', 'X-Frame-Options "DENY"',
            'Permissions-Policy "camera=(self), microphone=(), geolocation=(), payment=(), usb=()"', 'Content-Security-Policy "'.ControlHeadersListener::CSP.'"'];
        foreach (['@civ_ctl_denied', '@civ_ctl_misdirected'] as $named) {
            self::assertSame(1, preg_match('/location '.preg_quote($named, '/').' \{(.*?)\n    \}/s', $control, $m), $named);
            foreach ([...$serverHeaders, 'Cache-Control "no-store, private"'] as $header) {
                self::assertStringContainsString('add_header '.$header.' always;', $m[1], $named.' : '.$header);
            }
            self::assertStringContainsString('types { }', $m[1], 'type de contenu forcé (jamais celui de l\'extension demandée)');
            self::assertStringContainsString('default_type text/plain;', $m[1]);
        }
        foreach (['@civ_denied', '@civ_misdirected'] as $named) {
            self::assertSame(1, preg_match('/location '.preg_quote($named, '/').' \{(.*?)\n    \}/s', $files['conf.d/10-civipass-site.conf'], $m), $named);
            foreach (['Strict-Transport-Security "max-age=31536000"', 'Cache-Control "no-store, private"', 'X-Content-Type-Options "nosniff"', 'Referrer-Policy "no-referrer"'] as $header) {
                self::assertStringContainsString('add_header '.$header.' always;', $m[1], 'gestion '.$named.' : '.$header);
            }
        }
        self::assertStringNotContainsString('includeSubDomains', implode("\n", $files));
        self::assertStringNotContainsString('limit_except', implode("\n", $files));
    }

    /**
     * @param array<string, string|null> $overrides
     *
     * @return array{int, string}
     */
    private function render(array $overrides): array
    {
        $env = ['CIVIPASS_DOMAIN' => 'civipass.test', 'CONTROL_DOMAIN' => 'controle.civipass.test', 'TLS_CERTIFICATE' => '/etc/c1.pem', 'TLS_CERTIFICATE_KEY' => '/etc/k1.pem',
            'TLS_CONTROL_CERTIFICATE' => '/etc/c2.pem', 'TLS_CONTROL_CERTIFICATE_KEY' => '/etc/k2.pem', 'DOCROOT' => '/var/www/public', 'PHP_FPM_UPSTREAM' => 'php:9000'];
        $env = array_filter($overrides + $env, static fn (?string $v): bool => null !== $v);
        $dir = sys_get_temp_dir().'/civipass-render-'.bin2hex(random_bytes(4));
        $file = $dir.'.env';
        file_put_contents($file, implode("\n", array_map(static fn (string $k, string $v): string => $k.'='.$v, array_keys($env), $env))."\n");
        $process = new Process(['bash', self::OPS.'/bin/render.sh', '--env', $file, '--out', $dir, '--allow-example']);
        $process->run();
        $control = is_file($dir.'/conf.d/20-civipass-control.conf') ? 'control' : 'nocontrol';
        $output = $process->getOutput().$process->getErrorOutput();
        (new \Symfony\Component\Filesystem\Filesystem())->remove([$dir, $file]);

        return [$process->getExitCode() ?? 1, $control.'|'.$output];
    }

    public function testTheRenderGeneratesTheControlVhostOnlyWhenTheControlDomainIsSet(): void
    {
        [$code, $out] = $this->render([]);
        self::assertSame(0, $code, $out);
        self::assertStringStartsWith('control|', $out);

        [$code, $out] = $this->render(['CONTROL_DOMAIN' => null, 'TLS_CONTROL_CERTIFICATE' => null, 'TLS_CONTROL_CERTIFICATE_KEY' => null]);
        self::assertSame(0, $code, $out);
        self::assertStringStartsWith('nocontrol|', $out, 'sans CONTROL_DOMAIN : aucun vhost de contrôle');
    }

    public function testTheRenderRefusesIncoherentControlSettings(): void
    {
        foreach ([
            'même domaine que la gestion' => ['CONTROL_DOMAIN' => 'civipass.test'],
            'même certificat que la gestion' => ['TLS_CONTROL_CERTIFICATE' => '/etc/c1.pem'],
            'certificat de contrôle absent' => ['TLS_CONTROL_CERTIFICATE' => null],
            'clé de contrôle absente' => ['TLS_CONTROL_CERTIFICATE_KEY' => null],
            'domaine invalide' => ['CONTROL_DOMAIN' => 'Controle_Civipass'],
            'certificat sans domaine' => ['CONTROL_DOMAIN' => null],
        ] as $label => $override) {
            [$code, $out] = $this->render($override);
            self::assertNotSame(0, $code, $label.' doit être refusé : '.$out);
            self::assertStringContainsString('paramètre invalide', $out, $label);
        }
    }

    /**
     * @param list<string> $lines
     *
     * @return array{int, string}
     */
    private function check(array $lines, bool $control = true): array
    {
        $file = tempnam(sys_get_temp_dir(), 'civipass-env-') ?: self::fail('fichier temporaire');
        file_put_contents($file, implode("\n", $lines)."\n");
        $args = ['bash', self::OPS.'/bin/check-deployment.sh', '--domain', 'civipass.exemple.fr'];
        if ($control) {
            array_push($args, '--control-domain', 'controle.civipass.exemple.fr');
        }
        $process = new Process([...$args, $file]);
        $process->run();
        @unlink($file);

        return [$process->getExitCode() ?? 1, $process->getOutput()];
    }

    private const SECRETS = ['APP_SECRET=0123456789abcdef0123456789abcdef0123456789abcdef', 'DATABASE_URL=mysql://civipass:s3cr3t-v4lue@127.0.0.1:3306/civipass?serverVersion=8.4.0', 'MAILER_DSN=smtp://relais.exemple.fr:587', 'NOTIFICATION_KEYRING=k1:AAAA', 'NOTIFICATION_ACTIVE_KEY_ID=k1', 'OPS_ALERT_EMAIL=exploitation@exemple.fr', 'BACKUP_AGE_RECIPIENTS=age1qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq'];

    /** @return list<string> */
    private static function goodEnv(): array
    {
        return [...self::SECRETS, 'DEFAULT_URI=https://civipass.exemple.fr', 'APP_ENV=prod', 'APP_DEBUG=0', 'CONTROL_HOST=controle.civipass.exemple.fr',
            'TRUSTED_HOSTS=^(civipass\.exemple\.fr|controle\.civipass\.exemple\.fr)$', 'CONTROL_PWA_BASE_URL=https://controle.civipass.exemple.fr/controle/'];
    }

    public function testTheDeploymentCheckAcceptsAConsistentControlConfigurationAndRefusesTheOthers(): void
    {
        [$code, $out] = $this->check(self::goodEnv());
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('TRUSTED_HOSTS limité aux deux domaines', $out);

        $bad = [
            'CONTROL_HOST absent' => array_map(static fn (string $l): string => str_starts_with($l, 'CONTROL_HOST=') ? 'CONTROL_HOST=' : $l, self::goodEnv()),
            'TRUSTED_HOSTS inactif' => array_map(static fn (string $l): string => str_starts_with($l, 'TRUSTED_HOSTS=') ? 'TRUSTED_HOSTS=^.*$' : $l, self::goodEnv()),
            'TRUSTED_HOSTS trop large' => array_map(static fn (string $l): string => str_starts_with($l, 'TRUSTED_HOSTS=') ? 'TRUSTED_HOSTS=^(civipass\.exemple\.fr|controle\.civipass\.exemple\.fr|.*)$' : $l, self::goodEnv()),
            'PWA sur la gestion' => array_map(static fn (string $l): string => str_starts_with($l, 'CONTROL_PWA_BASE_URL=') ? 'CONTROL_PWA_BASE_URL=https://civipass.exemple.fr/controle/' : $l, self::goodEnv()),
            'DEFAULT_URI sur le contrôle' => array_map(static fn (string $l): string => str_starts_with($l, 'DEFAULT_URI=') ? 'DEFAULT_URI=https://controle.civipass.exemple.fr' : $l, self::goodEnv()),
        ];
        foreach ($bad as $label => $lines) {
            [$code, $out] = $this->check($lines);
            self::assertSame(1, $code, $label.' : '.$out);
            self::assertStringContainsString('KO', $out, $label);
        }
    }

    public function testWithoutTheControlDomainTheDeploymentCheckIsUnchanged(): void
    {
        [$code, $out] = $this->check([...self::SECRETS, 'DEFAULT_URI=https://civipass.exemple.fr', 'APP_ENV=prod', 'APP_DEBUG=0'], control: false);

        self::assertSame(0, $code, $out);
        self::assertStringNotContainsString('CONTROL_HOST', $out);
    }
}
