#!/usr/bin/env python3
"""Rejoue des requêtes contre un nginx de test et vérifie le journal d'accès RÉELLEMENT écrit (pas seulement la configuration).

  access_log_cases.py --url https://civipass.test:18643 --http-url http://civipass.test:18680 \
      --resolve civipass.test:18643:127.0.0.1 --resolve civipass.test:18680:127.0.0.1 --log /chemin/civipass-access.log
(--resolve : le serveur par défaut refuse l'hôte inconnu et les connexions sans SNI, comme en production.)

Chaque cas porte un identifiant unique dans le User-Agent ; la ligne de journal correspondante doit contenir l'URI attendue, et AUCUN secret ni donnée personnelle de
test ne doit figurer dans le journal. Code de sortie : 0 si tout est conforme, 1 sinon."""
import argparse, re, subprocess, sys, time

T = 'abcdefghijklmnopqrstuvwxyz234567'                          # jeton de vérification (32 caractères base32)
S = 'SSSSsecretBeneficiaireSSSSSSSSSSSSSSSSSSSSSSS1'              # lien bénéficiaire (43 caractères)
I = 'IIIIinvitationTokenIIIIIIIIIIIIIIIIIIIIIIIIII1'              # invitation (43 caractères)
R = 'RRRRresetPasswordTokRRRRRRRRRRRRRRRRRRRRRRRR1'               # réinitialisation (43 caractères)
PII = 'DupontMartinSentinelle'                                     # donnée personnelle dans une requête de recherche
SECRETS = [T, S, I, R, PII]
UUID = '0192f0a0-0000-7000-8000-000000000000'

# (nom, méthode, cible, arguments curl, URI attendue dans le journal)
CASES = [
    ('v GET', 'GET', f'/v/{T}', [], '/v/***'),
    ('v HEAD', 'HEAD', f'/v/{T}', [], '/v/***'),
    ('v POST', 'POST', f'/v/{T}', [], '/v/***'),
    ('v PUT', 'PUT', f'/v/{T}', [], '/v/***'),
    ('v OPTIONS', 'OPTIONS', f'/v/{T}', [], '/v/***'),
    ('v slash final', 'GET', f'/v/{T}/', [], '/v/***'),
    ('v segments en trop', 'GET', f'/v/{T}/extra/seg', [], '/v/***'),
    ('v requête', 'GET', f'/v/{T}?x=1&t={T}', [], '/v/***'),
    ('v %2F', 'GET', f'/v/{T}%2Fpdf', [], '/v/***'),
    ('v /%76/ encodé', 'GET', f'/%76/{T}', [], '/v/***'),
    ('lp /%6caissez-passer/ encodé', 'GET', f'/%6caissez-passer/{S}', [], '/laissez-passer/***'),
    ('v //', 'GET', f'//v/{T}', [], '/v/***'),
    ('lp ///…//', 'GET', f'///laissez-passer//{S}', [], '/laissez-passer/***'),
    ('v /./', 'GET', f'/./v/{T}', [], '/v/***'),
    ('v /x/../', 'GET', f'/x/../v/{T}', [], '/v/***'),
    ('v majuscule', 'GET', f'/V/{T}', [], '/v/***'),
    ('v /index.php/v/', 'GET', f'/index.php/v/{T}', [], '/v/***'),
    ('inv /index.php//invitation/', 'GET', f'/index.php//invitation/{I}', [], '/invitation/***'),
    ('v ;param', 'GET', f'/v/{T};a={T}', [], '/v/***'),
    ('v URI de 9000 octets', 'GET', '/v/' + 'A' * 9000 + T, [], '/v/***'),
    ('v HTTP/1.0', 'GET', f'/v/{T}', ['--http1.0'], '/v/***'),
    ('v forme absolue', 'GET', f'/v/{T}', ['--request-target', f'http://civipass.test/v/{T}'], '/v/***'),
    ('lp', 'GET', f'/laissez-passer/{S}', [], '/laissez-passer/***'),
    ('lp pdf', 'GET', f'/laissez-passer/{S}/pdf', [], '/laissez-passer/***'),
    ('invitation', 'GET', f'/invitation/{I}', [], '/invitation/***'),
    ('reset-password', 'GET', f'/reset-password/{R}', [], '/reset-password/***'),
    ('reset-password requête', 'GET', f'/reset-password/{R}?a={R}', [], '/reset-password/***'),
    # secrets TRONQUÉS : masqués dès le premier caractère
    ('v tronqué (3 car.)', 'GET', '/v/abc', [], '/v/***'),
    ('lp tronqué (1 car.)', 'GET', '/laissez-passer/a', [], '/laissez-passer/***'),
    ('invitation tronquée (3 car.)', 'GET', '/invitation/abc', [], '/invitation/***'),
    ('invitation tronquée (11 car.)', 'GET', '/invitation/' + I[:11], [], '/invitation/***'),
    ('reset-password tronqué (5 car.)', 'GET', '/reset-password/' + R[:5], [], '/reset-password/***'),
    # Referer
    ('referer chemin + requête', 'GET', '/login', ['-H', f'Referer: https://example.org/v/{T}?x={T}'], '/login'),
    ('referer encodé', 'GET', '/login', ['-H', f'Referer: https://example.org/%76/{T}'], '/login'),
    ('referer relatif', 'GET', '/login', ['-H', f'Referer: /v/{T}'], '/login'),
    ('referer sans schéma', 'GET', '/login', ['-H', f'Referer: example.org/laissez-passer/{S}'], '/login'),
    ('referer secret en paramètre', 'GET', '/login', ['-H', f'Referer: https://example.org/page?t={T}'], '/login'),
    # routes FIXES : lisibles
    ('FIXE /invitation/delegation', 'GET', '/invitation/delegation', [], '/invitation/delegation'),
    ('FIXE /invitation/rejoindre', 'GET', '/invitation/rejoindre', [], '/invitation/rejoindre'),
    ('FIXE /invitation', 'GET', '/invitation', [], '/invitation'),
    ('FIXE /reset-password', 'GET', '/reset-password', [], '/reset-password'),
    ('FIXE /reset-password/done', 'GET', '/reset-password/done', [], '/reset-password/done'),
    ('reset-password/don (tronqué)', 'GET', '/reset-password/don', [], '/reset-password/***'),
    ('FIXE /login', 'GET', '/login', [], '/login'),
    ('FIXE /v/ (sans jeton)', 'GET', '/v/', [], '/v/'),
    ('FIXE /v', 'GET', '/v', [], '/v'),
    # requêtes (données personnelles) : jamais journalisées
    ('requête /login?next=', 'GET', '/login?next=%2Fcommunes', [], '/login?[requete-omise]'),
    ('recherche ?q=<nom>', 'GET', f'/communes/{UUID}/demandes?q={PII}&page=2', [], f'/communes/{UUID}/demandes?[requete-omise]'),
    ('recherche ?reference=', 'GET', f'/communes/{UUID}/corrections-identite?reference=DEM-2026-000001', [], f'/communes/{UUID}/corrections-identite?[requete-omise]'),
]


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--url', required=True)
    ap.add_argument('--http-url', required=True)
    ap.add_argument('--log', required=True)
    ap.add_argument('--resolve', action='append', default=[])
    a = ap.parse_args()
    results = []
    for i, (name, method, target, extra, expected) in enumerate(CASES, 1):
        ua = f'case-{i:02d}'
        cmd = ['curl', '-sk', '-o', '/dev/null', '--max-time', '10', '--path-as-is', '-A', ua, '-w', '%{http_code}'] + [x for r in a.resolve for x in ('--resolve', r)]
        cmd += ['-I'] if method == 'HEAD' else ['-X', method]
        cmd += extra
        cmd += [a.url + '/'] if '--request-target' in extra else [a.url + target]
        code = subprocess.run(cmd, capture_output=True, text=True).stdout.strip()
        results.append((ua, name, code, expected))
    # redirection HTTP : le secret n'est pas dans le journal (il est dans l'en-tête Location de la réponse, ce que nginx ne journalise pas)
    http_cases = [('http v', f'/v/{T}', '/v/***'), ('http /%76/ encodé', f'/%76/{T}', '/v/***'), ('http //laissez-passer//', f'//laissez-passer//{S}', '/laissez-passer/***')]
    for j, (name, target, expected) in enumerate(http_cases, 1):
        ua = f'http-{j:02d}'
        code = subprocess.run(['curl', '-s', '-o', '/dev/null', '--path-as-is', '-A', ua, '-w', '%{http_code}'] + [x for r in a.resolve for x in ('--resolve', r)] + [a.http_url + target], capture_output=True, text=True).stdout.strip()
        results.append((ua, name, code, expected))
    time.sleep(1.5)
    log = open(a.log, errors='replace').read()
    by_ua = {}
    for line in log.splitlines():
        m = re.search(r'"((?:case|http)-\d\d)" ', line)
        if m:
            by_ua.setdefault(m.group(1), []).append(line)
    failures = 0
    for ua, name, code, expected in results:
        lines = by_ua.get(ua, [])
        if not lines:
            print(f'KO  {ua} {code:>3} {name}: aucune ligne de journal')
            failures += 1
            continue
        m = re.search(r'\] "[A-Z]+ (\S*) HTTP/[0-9.]+" (\d+)', lines[0])
        uri = m.group(1) if m else '?'
        ok = uri.lower() == expected.lower()  # la casse du préfixe est celle de la requête (/V/ → /V/***)
        print(('ok  ' if ok else 'KO  ') + f'{ua} {code:>3} {name:<34} -> {uri}' + ('' if ok else f'   (attendu {expected})'))
        failures += 0 if ok else 1
    leaked = [s for s in SECRETS if s.lower() in log.lower()]
    partial = [s[:12] for s in SECRETS if s[:12] in log and s not in leaked and s != UUID]
    print(f'journal : {len(log.splitlines())} lignes ; secrets ou données personnelles présents : {len(leaked)} ; fragments (12 premiers caractères) : {len(partial)}')
    if leaked or partial:
        print('KO  fuite dans le journal d\'accès :', leaked + partial)
        failures += 1
    print('RESULTAT :', 'CONFORME' if failures == 0 else f'{failures} non-conformité(s)')
    return 1 if failures else 0


if __name__ == '__main__':
    sys.exit(main())
