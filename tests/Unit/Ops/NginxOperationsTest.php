<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ops;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Fichiers d'exploitation nginx de production (`ops/nginx/`) : propriétés de sécurité des modèles, génération paramétrée, filtre du journal d'erreur, crochet de rotation
 * (échecs compris) et contrôle de l'environnement de déploiement. Les contrôles qui exigent Docker (vrai nginx, vrai logrotate) sont dans ops/nginx/tests/*.sh.
 */
final class NginxOperationsTest extends TestCase
{
    private const T = 'abcdefghijklmnopqrstuvwxyz234567';
    private const S = 'SSSSsecretBeneficiaireSSSSSSSSSSSSSSSSSSSSSSS1';

    private string $ops;
    /** @var list<string> */
    private array $cleanup = [];

    protected function setUp(): void
    {
        $this->ops = \dirname(__DIR__, 3).'/ops/nginx';
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            if (is_dir($path)) {
                $this->exec(['rm', '-rf', $path]);
            } elseif (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * @param list<string>          $command
     * @param array<string, string> $env
     *
     * @return array{int, string, string}
     */
    private function exec(array $command, array $env = [], ?string $stdin = null): array
    {
        $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null, $env + ['PATH' => (string) getenv('PATH')]);
        self::assertIsResource($process);
        fwrite($pipes[0], $stdin ?? '');
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out, $err];
    }

    private function template(string $name): string
    {
        return (string) file_get_contents($this->ops.'/templates/'.$name);
    }

    private function temp(string $suffix = ''): string
    {
        $path = sys_get_temp_dir().'/civipass-ops-'.bin2hex(random_bytes(6)).$suffix;
        $this->cleanup[] = $path;

        return $path;
    }

    // ------------------------------------------------------------------ modèles

    public function testTheAccessLogFormatNeverLogsTheRequestLineNorForwardedFor(): void
    {
        $logging = $this->template('00-civipass-logging.conf.template');

        preg_match('/log_format\s+civipass\s+(.*?);/s', $logging, $format);
        self::assertNotEmpty($format, 'log_format civipass');
        self::assertStringNotContainsString('$request ', $format[1].' ');
        self::assertStringNotContainsString('$request"', $format[1]);
        self::assertStringNotContainsString('$http_referer', $format[1], 'le Referer passe par $civ_referer (origine seulement)');
        self::assertStringNotContainsString('x_forwarded_for', strtolower($format[1]), 'en-tête fourni par un client : jamais journalisé');
        self::assertStringContainsString('$civ_log_uri', $format[1]);
        self::assertStringContainsString('$civ_referer', $format[1]);
        self::assertStringContainsString('access_log __LOG_DIR__/civipass-access.log civipass;', $logging);
        self::assertSame(1, preg_match_all('/^access_log\s/m', $logging), 'un seul access_log, hérité par tous les serveurs');
    }

    public function testQueryStringsAreNeverLogged(): void
    {
        $logging = $this->template('00-civipass-logging.conf.template');

        self::assertMatchesRegularExpression('/map \$request_uri \$civ_uri_plain/', $logging);
        self::assertStringContainsString('[requete-omise]', $logging);
        self::assertMatchesRegularExpression('/map "\$civ_route_decoded\$civ_route_raw" \$civ_log_uri \{\s*default \$civ_uri_plain;/', $logging, 'le journal ne reçoit jamais $request_uri (avec requête) directement');
    }

    public function testSitesNeverBuildARedirectFromTheClientHostNorUseLimitReq(): void
    {
        foreach (['10-civipass-site.conf.template', '00-civipass-logging.conf.template', 'nginx.conf.template'] as $file) {
            $conf = $this->template($file);
            self::assertDoesNotMatchRegularExpression('/return\s+30[1-8]\s+[^;]*\$host/', $conf, $file);
            self::assertDoesNotMatchRegularExpression('/limit_req(_zone)?\s/', $conf, $file.' : pas de limitation nginx en V1');
        }
        self::assertStringContainsString('return 301 https://__CIVIPASS_DOMAIN__$request_uri;', $this->template('10-civipass-site.conf.template'));
    }

    public function testHstsIsExactAndNeverLostThroughLocationInheritance(): void
    {
        $site = $this->template('10-civipass-site.conf.template');

        self::assertSame(1, preg_match_all('/add_header\s+Strict-Transport-Security\s+"max-age=31536000"\s+always;/', $site));
        self::assertStringNotContainsString('includeSubDomains', $site);
        self::assertStringNotContainsString('preload', $site);
        // Aucun add_header dans un `location` : il supprimerait l'en-tête hérité du `server`.
        preg_match_all('/location\s[^{]*\{(.*?)\n    \}/s', $site, $blocks);
        foreach ($blocks[1] as $block) {
            self::assertStringNotContainsString('add_header', $block);
        }
    }

    public function testUnknownHostsAreRefusedAndForwardedHeadersAreNeverHandedToPhp(): void
    {
        $site = $this->template('10-civipass-site.conf.template');

        self::assertStringContainsString('return 444;', $site);
        self::assertStringContainsString('ssl_reject_handshake on;', $site);
        self::assertStringContainsString('ssl_protocols TLSv1.2 TLSv1.3;', $site);
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_HOST', 'HTTP_X_FORWARDED_PROTO', 'HTTP_X_FORWARDED_PORT', 'HTTP_X_FORWARDED_PREFIX', 'HTTP_FORWARDED', 'HTTP_X_REAL_IP'] as $header) {
            self::assertStringContainsString('fastcgi_param '.$header.' "";', $site);
        }
        self::assertStringContainsString('location ~ /\.(?!well-known/)', $site);
        self::assertStringContainsString('internal;', $site, 'le contrôleur frontal n\'est jamais appelé directement');
        self::assertStringContainsString('ssl_session_tickets off;', $site);
        self::assertStringNotContainsString('SSLv3', $site);
        self::assertStringNotContainsString('AES128-SHA', preg_replace('/ECDHE|DHE/', '', $site) ?? '', 'aucun chiffrement CBC sans AEAD');
    }

    public function testTheMaskCoversTheFourSecretPrefixesAndTheOriginalPathGuardIsInEveryServer(): void
    {
        $logging = $this->template('00-civipass-logging.conf.template');
        $site = $this->template('10-civipass-site.conf.template');

        foreach (['v', 'laissez-passer', 'invitation', 'reset-password'] as $prefix) {
            self::assertStringContainsString($prefix.'(?=', $logging);
        }
        self::assertStringContainsString('(?!(?:delegation|rejoindre)(?:/|$))', $logging, 'liste blanche des routes fixes sous /invitation/');
        // Un `set $civ_path` gardé dans CHAQUE serveur qui répond (hors serveurs par défaut qui ne répondent rien) : sans lui, `/%76/<jeton>` n'était pas masqué.
        self::assertSame(2, substr_count($site, 'if ($civ_path = "") { set $civ_path $uri; }'));
    }

    public function testNoServerOverridesTheMaskedLogs(): void
    {
        foreach (['10-civipass-site.conf.template', 'nginx.conf.template'] as $file) {
            self::assertDoesNotMatchRegularExpression('/^\s*(access_log|error_log)\s+(?!off)/m', preg_replace('/^\s*error_log \/var\/log\/nginx\/error\.log notice;$/m', '', $this->template($file)) ?? '', $file);
        }
        self::assertStringContainsString('error_log __LOG_DIR__/civipass-error.log error;', $this->template('00-civipass-logging.conf.template'), 'niveau error, jamais crit : les vraies erreurs restent visibles');
    }

    public function testTheLogrotateTemplateRotatesHourlyFiltersAndDoesNotKeepRawCopies(): void
    {
        $rotate = $this->template('logrotate-civipass-nginx.conf.template');

        self::assertMatchesRegularExpression('#__LOG_DIR__/civipass-error\.log \{.*?hourly.*?rotate __ERROR_LOG_RETENTION_HOURS__.*?\n\}#s', $rotate);
        self::assertMatchesRegularExpression('#__LOG_DIR__/civipass-access\.log \{.*?daily.*?rotate __ACCESS_LOG_RETENTION_DAYS__.*?\n\}#s', $rotate);
        foreach (['copytruncate', '(?<!no)dateext', 'olddir', 'mail ', 'shred'] as $forbidden) {
            self::assertDoesNotMatchRegularExpression('/'.$forbidden.'/', preg_replace('/^#.*$/m', '', $rotate) ?? '', 'pas de copie ni d\'archive du journal brut ('.$forbidden.')');
        }
        self::assertStringContainsString('prerotate', $rotate, 'rattrapage d\'un fichier tourné resté brut');
        self::assertStringContainsString('delaycompress', $rotate, 'le fichier tourné est filtré avant d\'être compressé');
        self::assertStringContainsString('create 0640 __NGINX_USER__ __ADMIN_GROUP__', $rotate);
    }

    public function testTheLoggingTemplateAllowListMatchesTheFilterAllowList(): void
    {
        $nginx = $this->template('00-civipass-logging.conf.template');
        $filter = (string) file_get_contents($this->ops.'/bin/civipass-sanitize-nginx-error');

        self::assertSame(1, preg_match('/invitation\(\?=\/\+\(\?!\(\?:([a-z|]+)\)\(\?:\/\|\$\)\)/', $nginx, $invitationNginx));
        self::assertSame(1, preg_match('/reset-password\(\?=\/\+\(\?!\(\?:([a-z|]+)\)\(\?:\/\|\$\)\)/', $nginx, $resetNginx));
        self::assertSame(1, preg_match('/\$fixed_invitation\s*=\s*qr\{\(\?:([a-z|]+)\)/', $filter, $invitationFilter));
        self::assertSame(1, preg_match('/\$fixed_reset\s*=\s*qr\{\(\?:([a-z|]+)\)/', $filter, $resetFilter));

        self::assertSame('delegation|rejoindre', $invitationNginx[1]);
        self::assertSame('done', $resetNginx[1]);
        self::assertSame($invitationNginx[1], $invitationFilter[1], 'liste blanche /invitation/ identique dans nginx et dans le filtre');
        self::assertSame($resetNginx[1], $resetFilter[1], 'liste blanche /reset-password/ identique dans nginx et dans le filtre');
    }

    public function testThePhpFpmPoolExampleLogsNothingAboutUrlsAndKeepsErrorsInADedicatedFile(): void
    {
        $pool = (string) file_get_contents($this->ops.'/php-fpm/civipass-pool.conf.example');
        $active = preg_replace('/^\s*;.*$/m', '', $pool) ?? '';

        self::assertDoesNotMatchRegularExpression('/^\s*access\.(log|format)\s*=/m', $active, 'aucun journal d\'accès PHP-FPM actif');
        self::assertMatchesRegularExpression('/^catch_workers_output = yes$/m', $active);
        self::assertMatchesRegularExpression('/^php_admin_flag\[display_errors\] = off$/m', $active);
        self::assertMatchesRegularExpression('/^php_admin_value\[zend\.exception_ignore_args\] = On$/m', $active);
        self::assertMatchesRegularExpression('/^php_admin_value\[error_log\] = \/var\/log\/civipass\/php\//m', $active);
        self::assertMatchesRegularExpression('/^user = civipass$/m', $active, 'utilisateur distinct de celui de nginx');
    }

    // ------------------------------------------------------------------ génération

    public function testRenderingProducesEveryFileWithoutLeftoverPlaceholders(): void
    {
        $out = $this->temp();

        [$code, , $err] = $this->exec(['bash', $this->ops.'/bin/render.sh', '--env', $this->ops.'/civipass.env.example', '--out', $out, '--allow-example']);

        self::assertSame(0, $code, $err);
        foreach (['nginx.conf', 'conf.d/00-civipass-logging.conf', 'conf.d/10-civipass-site.conf', 'logrotate.conf', 'systemd/civipass-nginx-logrotate.service', 'systemd/civipass-nginx-logrotate.timer'] as $file) {
            self::assertFileExists($out.'/'.$file);
            self::assertDoesNotMatchRegularExpression('/__[A-Z][A-Z0-9_]*__/', (string) file_get_contents($out.'/'.$file), $file);
        }
        self::assertStringContainsString('server_name civipass.example;', (string) file_get_contents($out.'/conf.d/10-civipass-site.conf'));
        self::assertStringContainsString('rotate 168', (string) file_get_contents($out.'/logrotate.conf'), 'rétention du journal d\'erreur : 7 jours, rotation horaire');
        self::assertStringContainsString('rotate 30', (string) file_get_contents($out.'/logrotate.conf'), 'rétention du journal d\'accès : 30 jours');
    }

    public function testTheExampleDomainIsRefusedForARealDeployment(): void
    {
        [$code, , $err] = $this->exec(['bash', $this->ops.'/bin/render.sh', '--env', $this->ops.'/civipass.env.example', '--out', $this->temp()]);

        self::assertNotSame(0, $code);
        self::assertStringContainsString('EXEMPLE', $err);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidParameters(): iterable
    {
        yield 'domaine en majuscules' => ["CIVIPASS_DOMAIN=Civipass.Exemple.FR\n"];
        yield 'domaine sans point' => ["CIVIPASS_DOMAIN=civipass\n"];
        yield 'injection nginx dans le domaine' => ["CIVIPASS_DOMAIN=a.fr; return 200\n"];
        yield 'injection dans un chemin' => ["DOCROOT=/var/www;/etc\n"];
        yield 'chemin relatif' => ["TLS_CERTIFICATE=cert.pem\n"];
        yield 'rétention nulle' => ["ACCESS_LOG_RETENTION_DAYS=0\n"];
        yield 'rétention non numérique' => ["ERROR_LOG_RETENTION_HOURS=7j\n"];
        yield 'paramètre inconnu' => ["PARAMETRE_INCONNU=1\n"];
        yield 'amont invalide' => ["PHP_FPM_UPSTREAM=unix:/run/php.sock; fastcgi_param X y\n"];
    }

    #[DataProvider('invalidParameters')]
    public function testInvalidParametersAreRefused(string $override): void
    {
        $env = $this->temp('.env');
        file_put_contents($env, "CIVIPASS_DOMAIN=civipass.exemple.fr\nTLS_CERTIFICATE=/etc/c.pem\nTLS_CERTIFICATE_KEY=/etc/k.pem\nDOCROOT=/var/www/public\nPHP_FPM_UPSTREAM=unix:/run/php.sock\n".$override);

        [$code] = $this->exec(['bash', $this->ops.'/bin/render.sh', '--env', $env, '--out', $this->temp()]);

        self::assertNotSame(0, $code);
    }

    public function testARealDomainAndCustomRetentionsAreRendered(): void
    {
        $env = $this->temp('.env');
        file_put_contents($env, "CIVIPASS_DOMAIN=civipass.exemple.fr\nTLS_CERTIFICATE=/etc/c.pem\nTLS_CERTIFICATE_KEY=/etc/k.pem\nDOCROOT=/var/www/public\nPHP_FPM_UPSTREAM=127.0.0.1:9000\nACCESS_LOG_RETENTION_DAYS=45\nERROR_LOG_RETENTION_HOURS=72\n");
        $out = $this->temp();

        [$code, , $err] = $this->exec(['bash', $this->ops.'/bin/render.sh', '--env', $env, '--out', $out]);

        self::assertSame(0, $code, $err);
        self::assertStringContainsString('return 301 https://civipass.exemple.fr$request_uri;', (string) file_get_contents($out.'/conf.d/10-civipass-site.conf'));
        self::assertStringContainsString('rotate 45', (string) file_get_contents($out.'/logrotate.conf'));
        self::assertStringContainsString('rotate 72', (string) file_get_contents($out.'/logrotate.conf'));
    }

    // ------------------------------------------------------------------ filtre du journal d'erreur

    private function filter(string $input): string
    {
        [$code, $out, $err] = $this->exec(['perl', $this->ops.'/bin/civipass-sanitize-nginx-error'], [], $input);
        self::assertSame(0, $code, $err);

        return $out;
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function errorLines(): iterable
    {
        $head = '2026/11/10 11:00:00 [error] 20#20: *1 connect() failed (111: Connection refused) while connecting to upstream, client: 203.0.113.7, server: civipass.test, ';
        $tail = ', upstream: "fastcgi://unix:/run/php/php8.4-fpm.sock:", host: "civipass.test"';
        yield 'jeton de vérification' => [$head.'request: "GET /v/'.self::T.' HTTP/1.1"'.$tail, $head.'request: "GET /v/*** HTTP/1.1"'.$tail];
        yield 'lien bénéficiaire et pdf' => [$head.'request: "GET /laissez-passer/'.self::S.'/pdf HTTP/2.0"'.$tail, $head.'request: "GET /laissez-passer/***/pdf HTTP/2.0"'.$tail];
        yield 'invitation' => [$head.'request: "GET /invitation/'.self::S.' HTTP/1.1"'.$tail, $head.'request: "GET /invitation/*** HTTP/1.1"'.$tail];
        yield 'réinitialisation' => [$head.'request: "POST /reset-password/'.self::S.' HTTP/1.1"'.$tail, $head.'request: "POST /reset-password/*** HTTP/1.1"'.$tail];
        yield 'secret tronqué (3 caractères)' => [$head.'request: "GET /v/abc HTTP/1.1"'.$tail, $head.'request: "GET /v/*** HTTP/1.1"'.$tail];
        yield 'invitation tronquée (3 caractères)' => [$head.'request: "GET /invitation/abc HTTP/1.1"'.$tail, $head.'request: "GET /invitation/*** HTTP/1.1"'.$tail];
        yield 'doubles slashs' => [$head.'request: "GET //v/'.self::T.' HTTP/1.1"'.$tail, $head.'request: "GET //v/*** HTTP/1.1"'.$tail];
        yield 'préfixe encodé (jeton opaque)' => [$head.'request: "GET /%76/'.self::T.' HTTP/1.1"'.$tail, $head.'request: "GET /%76/*** HTTP/1.1"'.$tail];
        yield 'requête (donnée personnelle)' => [$head.'request: "GET /communes/0192f0a0-0000-7000-8000-000000000000/demandes?q=Dupont+Martin&page=2 HTTP/1.1"'.$tail, $head.'request: "GET /communes/0192f0a0-0000-7000-8000-000000000000/demandes?[requete-omise] HTTP/1.1"'.$tail];
        yield 'requête sur une route secrète' => [$head.'request: "GET /v/'.self::T.'?x='.self::T.' HTTP/1.1"'.$tail, $head.'request: "GET /v/***?[requete-omise] HTTP/1.1"'.$tail];
        yield 'route fixe d\'invitation lisible' => [$head.'request: "POST /invitation/delegation HTTP/1.1"'.$tail, $head.'request: "POST /invitation/delegation HTTP/1.1"'.$tail];
        yield 'route fixe lisible (rejoindre)' => [$head.'request: "POST /invitation/rejoindre HTTP/1.1"'.$tail, $head.'request: "POST /invitation/rejoindre HTTP/1.1"'.$tail];
        yield 'route fixe de réinitialisation lisible' => [$head.'request: "GET /reset-password/done HTTP/1.1"'.$tail, $head.'request: "GET /reset-password/done HTTP/1.1"'.$tail];
        yield 'réinitialisation tronquée (don)' => [$head.'request: "GET /reset-password/don HTTP/1.1"'.$tail, $head.'request: "GET /reset-password/*** HTTP/1.1"'.$tail];
        yield 'routes sans segment lisibles' => [$head.'request: "GET /reset-password HTTP/1.1"'.$tail, $head.'request: "GET /reset-password HTTP/1.1"'.$tail];
        yield 'UUID conservé' => [$head.'request: "GET /communes/0192f0a0-0000-7000-8000-000000000000/evenements HTTP/1.1"'.$tail, $head.'request: "GET /communes/0192f0a0-0000-7000-8000-000000000000/evenements HTTP/1.1"'.$tail];
        yield 'referrer avec jeton et requête' => [$head.'request: "GET /login HTTP/1.1"'.$tail.', referrer: "https://civipass.test/v/'.self::T.'?r=1"', $head.'request: "GET /login HTTP/1.1"'.$tail.', referrer: "https://civipass.test/v/***?[requete-omise]"'];
        yield 'referrer encodé' => [$head.'request: "GET /login HTTP/1.1"'.$tail.', referrer: "https://civipass.test/%76/'.self::T.'"', $head.'request: "GET /login HTTP/1.1"'.$tail.', referrer: "https://civipass.test/%76/***"'];
        yield 'chemin cité hors des champs' => ['2026/11/10 11:00:00 [error] 1#1: open() "/var/www/public/v/'.self::T.'" failed (2: No such file)', '2026/11/10 11:00:00 [error] 1#1: open() "/var/www/public/v/***" failed (2: No such file)'];
        yield 'ligne sans requête inchangée' => ['2026/11/10 11:00:00 [notice] 1#1: signal process started', '2026/11/10 11:00:00 [notice] 1#1: signal process started'];
    }

    #[DataProvider('errorLines')]
    public function testTheFilterMasksSecretsAndQueriesAndKeepsTheDiagnostic(string $line, string $expected): void
    {
        self::assertSame($expected."\n", $this->filter($line."\n"));
    }

    public function testTheFilterIsIdempotentAndKeepsEveryLine(): void
    {
        $input = '';
        foreach (self::errorLines() as [$line]) {
            $input .= $line."\n";
        }
        $input .= "\n\nligne vide au-dessus\n";

        $once = $this->filter($input);
        $twice = $this->filter($once);

        self::assertSame($once, $twice);
        self::assertSame(substr_count($input, "\n"), substr_count($once, "\n"), 'une ligne en entrée, une ligne en sortie');
        self::assertStringNotContainsString(self::T, $once);
        self::assertStringNotContainsString(self::S, $once);
        self::assertStringNotContainsString('Dupont', $once);
    }

    public function testDocumentedLimitOfTheOpaqueTokenRule(): void
    {
        // Règle R3 : un jeton opaque de 28 caractères ou plus. Un segment de 27 caractères derrière un préfixe NON sensible reste lisible (aucun secret de l'application n'a cette forme).
        $line = '2026/11/10 11:00:00 [error] 1#1: x, request: "GET /autre/'.str_repeat('a', 27).' HTTP/1.1"';

        self::assertSame($line."\n", $this->filter($line."\n"));
        self::assertStringContainsString('/autre/***', $this->filter(str_replace(str_repeat('a', 27), str_repeat('a', 28), $line)."\n"));
    }

    // ------------------------------------------------------------------ crochet de rotation

    private function rawLog(int $lines): string
    {
        $path = $this->temp('.log.1');
        $content = '';
        for ($i = 1; $i <= $lines; ++$i) {
            $content .= sprintf("2026/11/10 11:00:%02d [error] 20#20: *%d connect() failed, client: 203.0.113.7, request: \"GET /v/%s HTTP/1.1\", host: \"civipass.test\"\n", $i % 60, $i, self::T);
        }
        file_put_contents($path, $content);

        return $path;
    }

    public function testTheHookFiltersARotatedFileAndKeepsEveryLine(): void
    {
        $file = $this->rawLog(25);

        [$code, , $err] = $this->exec(['bash', $this->ops.'/bin/civipass-nginx-log-hook', 'sweep', $file], ['CIVIPASS_SANITIZER' => $this->ops.'/bin/civipass-sanitize-nginx-error']);

        self::assertSame(0, $code, $err);
        $content = (string) file_get_contents($file);
        self::assertSame(25, substr_count($content, "\n"));
        self::assertStringNotContainsString(self::T, $content);
        self::assertStringContainsString('/v/***', $content);
        self::assertSame('0640', substr(sprintf('%o', fileperms($file)), -4));
    }

    public function testWhenTheMainFilterFailsTheHookFallsBackToACoarseMaskAndSaysSo(): void
    {
        $file = $this->rawLog(10);
        $failing = $this->temp('.sh');
        file_put_contents($failing, "#!/bin/sh\nexit 1\n");
        chmod($failing, 0755);

        [$code, , $err] = $this->exec(['bash', $this->ops.'/bin/civipass-nginx-log-hook', 'sweep', $file], ['CIVIPASS_SANITIZER' => $failing]);

        self::assertNotSame(0, $code, 'jamais un échec silencieux : code de sortie non nul');
        self::assertStringContainsString('filtre principal en échec', $err);
        $content = (string) file_get_contents($file);
        self::assertSame(10, substr_count($content, "\n"), 'diagnostic partiel conservé : une ligne par ligne');
        self::assertStringNotContainsString(self::T, $content);
        self::assertStringContainsString('request: "[masque]"', $content);
        self::assertStringContainsString('connect() failed', $content);
    }

    public function testWhenEverythingFailsTheRawContentIsNeverKeptAndTheLossIsExplicit(): void
    {
        $file = $this->rawLog(7);
        $failing = $this->temp('.sh');
        file_put_contents($failing, "#!/bin/sh\nexit 1\n");
        chmod($failing, 0755);
        mkdir($file.'.tmp'); // le fichier temporaire est bloqué : ni le filtre principal ni le repli ne peuvent écrire
        $this->cleanup[] = $file.'.tmp';

        [$code, , $err] = $this->exec(['bash', $this->ops.'/bin/civipass-nginx-log-hook', 'sweep', $file], ['CIVIPASS_SANITIZER' => $failing]);

        self::assertNotSame(0, $code);
        self::assertStringContainsString('7 ligne(s) brute(s) supprimée(s)', $err);
        $content = is_file($file) ? (string) file_get_contents($file) : '';
        self::assertStringNotContainsString(self::T, $content, 'aucun secret conservé');
        self::assertStringContainsString('SUPPRIME', $content, 'la perte est écrite dans le fichier lui-même, pas silencieuse');
        self::assertStringContainsString('7 lignes perdues', $content);
        self::assertSame(1, substr_count($content, "\n"));
    }

    public function testAFilterThatSucceedsWithoutMaskingAnythingIsRejected(): void
    {
        $file = $this->rawLog(5);
        $identity = $this->temp('.sh');
        file_put_contents($identity, "#!/bin/sh\ncat\n");
        chmod($identity, 0755);

        [$code, , $err] = $this->exec(['bash', $this->ops.'/bin/civipass-nginx-log-hook', 'sweep', $file], ['CIVIPASS_SANITIZER' => $identity]);

        self::assertNotSame(0, $code, 'un filtre « réussi » qui ne masque rien est une panne');
        self::assertStringContainsString('filtre principal en échec', $err);
        self::assertStringNotContainsString(self::T, (string) file_get_contents($file));
    }

    public function testTheHookRefusesUnknownCommands(): void
    {
        [$code] = $this->exec(['bash', $this->ops.'/bin/civipass-nginx-log-hook', 'nimporte']);

        self::assertSame(2, $code);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function shellScripts(): iterable
    {
        foreach (['bin/render.sh', 'bin/civipass-nginx-log-hook', 'bin/check-deployment.sh', 'bin/check-rotation.sh', 'tests/verify-nginx.sh', 'tests/verify-rotation.sh', 'tests/rotation-scenarios.sh'] as $script) {
            yield $script => [$script];
        }
    }

    #[DataProvider('shellScripts')]
    public function testShellScriptsHaveValidSyntaxAndAreExecutable(string $script): void
    {
        [$code, , $err] = $this->exec(['bash', '-n', $this->ops.'/'.$script]);

        self::assertSame(0, $code, $err);
        self::assertTrue(is_executable($this->ops.'/'.$script), $script.' doit être exécutable');
    }

    // ------------------------------------------------------------------ environnement de déploiement

    /**
     * @param list<string> $lines
     *
     * @return array{int, string}
     */
    private function deployment(array $lines, string $domain = 'civipass.exemple.fr'): array
    {
        $env = $this->temp('.env');
        file_put_contents($env, implode("\n", $lines)."\n");
        [$code, $out] = $this->exec(['bash', $this->ops.'/bin/check-deployment.sh', '--domain', $domain, $env]);

        return [$code, $out];
    }

    public function testAConformingProductionEnvironmentPasses(): void
    {
        [$code, $out] = $this->deployment(['DEFAULT_URI=https://civipass.exemple.fr', 'APP_ENV=prod', 'APP_DEBUG=0', 'PUBLIC_VERIFICATION_ENABLED=0']);

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('contrôle public fermé', $out);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function nonConformingEnvironments(): iterable
    {
        yield 'DEFAULT_URI en http' => [['DEFAULT_URI=http://civipass.exemple.fr', 'APP_ENV=prod']];
        yield 'DEFAULT_URI sur localhost (valeur par défaut de .env)' => [['DEFAULT_URI=http://localhost', 'APP_ENV=prod']];
        yield 'DEFAULT_URI sur un autre domaine' => [['DEFAULT_URI=https://autre.exemple.fr', 'APP_ENV=prod']];
        yield 'DEFAULT_URI absent' => [['APP_ENV=prod']];
        yield 'APP_ENV=dev' => [['DEFAULT_URI=https://civipass.exemple.fr', 'APP_ENV=dev']];
        yield 'APP_DEBUG actif' => [['DEFAULT_URI=https://civipass.exemple.fr', 'APP_ENV=prod', 'APP_DEBUG=1']];
        yield 'proxy de confiance déclaré' => [['DEFAULT_URI=https://civipass.exemple.fr', 'APP_ENV=prod', 'SYMFONY_TRUSTED_PROXIES=127.0.0.1']];
    }

    /**
     * @param list<string> $lines
     */
    #[DataProvider('nonConformingEnvironments')]
    public function testNonConformingEnvironmentsAreRejected(array $lines): void
    {
        [$code, $out] = $this->deployment($lines);

        self::assertSame(1, $code, $out);
        self::assertStringContainsString('KO', $out);
    }

    public function testAnOpenPublicVerificationIsReportedLoudlyButNotDecidedByTheScript(): void
    {
        [$code, $out] = $this->deployment(['DEFAULT_URI=https://civipass.exemple.fr', 'APP_ENV=prod', 'PUBLIC_VERIFICATION_ENABLED=1']);

        self::assertSame(0, $code);
        self::assertStringContainsString('le contrôle public est OUVERT', $out);
    }

    public function testTheLastEnvFileWinsAndTheRealProjectEnvIsNotConformingByDesign(): void
    {
        $base = $this->temp('.env');
        $local = $this->temp('.env');
        file_put_contents($base, "DEFAULT_URI=http://localhost\nAPP_ENV=dev\n");
        file_put_contents($local, "DEFAULT_URI=https://civipass.exemple.fr\nAPP_ENV=prod\n");

        [$ok] = $this->exec(['bash', $this->ops.'/bin/check-deployment.sh', '--domain', 'civipass.exemple.fr', $base, $local]);
        [$ko] = $this->exec(['bash', $this->ops.'/bin/check-deployment.sh', '--domain', 'civipass.exemple.fr', \dirname($this->ops, 2).'/.env']);

        self::assertSame(0, $ok);
        self::assertSame(1, $ko, '.env du dépôt (valeurs de développement) : le contrôle doit refuser DEFAULT_URI=http://localhost et APP_ENV=dev');
    }

    // ------------------------------------------------------------------ supervision de la rotation

    /**
     * @return array{int, string}
     */
    private function rotationCheck(string $firstLine, int $maxHours = 2): array
    {
        [$probe] = $this->exec(['date', '-d', '2026-01-01 00:00:00', '+%s']);
        if (0 !== $probe) {
            self::markTestSkipped('GNU date requis (Linux).');
        }
        $file = $this->temp('.log');
        file_put_contents($file, $firstLine);
        [$code, $out] = $this->exec(['bash', $this->ops.'/bin/check-rotation.sh', $file, (string) $maxHours]);

        return [$code, $out];
    }

    public function testRotationSupervisionAcceptsAFreshRawLog(): void
    {
        [$code, $out] = $this->rotationCheck(date('Y/m/d H:i:s', time() - 600)." [error] 1#1: *1 connect() failed\n");

        self::assertSame(0, $code, $out);
    }

    public function testRotationSupervisionRaisesAnAlertWhenTheRawLogIsTooOld(): void
    {
        [$code, $out] = $this->rotationCheck(date('Y/m/d H:i:s', time() - 5 * 3600)." [error] 1#1: *1 connect() failed\n");

        self::assertSame(1, $code);
        self::assertStringContainsString('la rotation horaire ne tourne pas', $out);
    }

    public function testRotationSupervisionAcceptsAnEmptyLogAndRejectsAnUnreadableOne(): void
    {
        [$empty] = $this->rotationCheck('');
        [$garbage, $out] = $this->rotationCheck("pas une ligne nginx\n");

        self::assertSame(0, $empty);
        self::assertSame(1, $garbage, $out);
    }
}
