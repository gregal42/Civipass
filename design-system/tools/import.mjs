// Import versionné du Design System Civipass dans le projet Symfony.
//
//   node design-system/tools/import.mjs "<chemin de l’archive du design system>" ["<archive des écrans de prévisualisation>"]
//
// Le second argument (facultatif, défaut : la même archive) désigne l'archive dont proviennent les ÉCRANS et les données fictives
// de la galerie de développement (/dev/ui, section 5 et 6). Il permet de mettre à jour les composants sans réimporter des écrans
// de prévisualisation dont le contrat de variables a changé (UI-02 : dashboard, forbidden).
//
// Outil de DÉVELOPPEMENT, exécuté sur le poste (pas dans le conteneur PHP, pas en production) :
//  - il n'écrit rien dans l'archive (lecture seule) ;
//  - il copie, transpile (TypeScript → JavaScript ESM, sans bundle) et adapte ;
//  - chaque adaptation est une substitution EXPLICITE dont le nombre d'occurrences est vérifié : si l'archive change
//    de forme, l'import échoue au lieu d'appliquer une adaptation à côté ;
//  - il écrit design-system/provenance.json (empreintes source/import, adaptations) et design-system/PROVENANCE.md.
// esbuild est celui de l'archive (aucune dépendance Node n'entre dans Civipass).
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { createRequire } from 'node:module';
import { pathToFileURL } from 'node:url';
import { buildMonogramSvg } from './monogram.mjs';

const archive = path.resolve(process.argv[2] ?? '');
if (!process.argv[2] || !fs.existsSync(path.join(archive, 'tokens/tokens.json'))) {
  console.error('Usage : node design-system/tools/import.mjs "<archive du design system>"'); process.exit(1);
}
const project = path.resolve(path.dirname(new URL(import.meta.url).pathname), '../..');
const screensArchive = process.argv[3] ? path.resolve(process.argv[3]) : archive;
const src = (...p) => path.join(archive, ...p);
const srcScreens = (...p) => path.join(screensArchive, ...p);
const dst = (...p) => path.join(project, ...p);
const sha = (buf) => crypto.createHash('sha256').update(buf).digest('hex');
const esbuild = createRequire(src('package.json'))('esbuild');
const pkg = JSON.parse(fs.readFileSync(src('package.json'), 'utf8'));
const tokens = JSON.parse(fs.readFileSync(src('tokens/tokens.json'), 'utf8'));

const files = [];        // { source, target, sourceSha, targetSha, adapted }
const adaptations = [];  // { id, target, description, occurrences }
function put(target, content, { source = null, adapted = false, screens = false } = {}) {
  fs.mkdirSync(path.dirname(dst(target)), { recursive: true });
  fs.writeFileSync(dst(target), content);
  const buf = Buffer.isBuffer(content) ? content : Buffer.from(content);
  files.push({ source, target, sourceSha: source ? sha(fs.readFileSync((screens ? srcScreens : src)(source))) : null, targetSha: sha(buf), adapted });
}
// Substitution explicite avec nombre d'occurrences attendu.
function adapt(id, target, text, from, to, expected, description) {
  const parts = text.split(from);
  const n = parts.length - 1;
  if (n !== expected) throw new Error(`Adaptation ${id} : ${n} occurrence(s) de « ${String(from).slice(0, 60)} » dans ${target}, ${expected} attendue(s). L’archive a changé : revoir l’adaptation.`);
  adaptations.push({ id, target, description, occurrences: n });
  return parts.join(to);
}
function adaptRe(id, target, text, re, to, expected, description) {
  const n = [...text.matchAll(re)].length;
  if (n !== expected) throw new Error(`Adaptation ${id} : ${n} correspondance(s) pour ${re} dans ${target}, ${expected} attendue(s).`);
  adaptations.push({ id, target, description, occurrences: n });
  return text.replace(re, to);
}
const read = (p) => fs.readFileSync(src(p), 'utf8');
const readScreens = (p) => fs.readFileSync(srcScreens(p), 'utf8');
const list = (d, ext) => fs.readdirSync(src(d)).filter((f) => f.endsWith(ext) && !f.startsWith('.')).sort();

/* ── 1. Styles ───────────────────────────────────────────────────────────── */
{
  let t = read('dist/tokens.css');
  t = adapt('A1', 'assets/styles/civipass/tokens.css', t, 'url("fonts/', 'url("../../fonts/', 5, 'Chemins des polices ajustés à l’emplacement AssetMapper (assets/styles/civipass → assets/fonts).');
  // Classes typographiques non préfixées (.h1, .small, .caption…) : collision possible avec Bootstrap (Sonata) et écart avec la
  // documentation (cp-h1…). Préfixées « cp- » ; .eyebrow et .plate/.plate-lg retirées (déjà .cp-eyebrow / composant .cp-plate).
  t = adaptRe('A2', 'assets/styles/civipass/tokens.css', t, /^\.(display|h1|h2|h3|body|body-strong|small|caption|ref) \{/gm, '.cp-$1 {', 9, 'Classes typographiques préfixées cp- (écart à signaler à la source : tokens.css devrait les générer préfixées).');
  t = adaptRe('A3', 'assets/styles/civipass/tokens.css', t, /^\.(eyebrow|plate|plate-lg) \{.*\n/gm, '', 3, 'Classes .eyebrow, .plate, .plate-lg retirées : doublons des composants .cp-eyebrow et .cp-plate, avec risque de collision.');
  put('assets/styles/civipass/tokens.css', t, { source: 'dist/tokens.css', adapted: true });
  put('assets/styles/civipass/civipass.css', read('dist/civipass.css'), { source: 'dist/civipass.css' });
}
for (const f of fs.readdirSync(src('fonts')).filter((x) => /\.(woff2|txt)$/.test(x))) put(`assets/fonts/${f}`, fs.readFileSync(src('fonts', f)), { source: `fonts/${f}` });

/* ── 2. Contrôleurs Stimulus : TypeScript → JavaScript ESM (sans bundle) ─── */
for (const f of list('src/controllers/cp', '.ts')) {
  const r = await esbuild.transform(read(`src/controllers/cp/${f}`), { loader: 'ts', format: 'esm', target: 'es2020', tsconfigRaw: { compilerOptions: { useDefineForClassFields: false } } });
  let js = r.code;
  const n = [...js.matchAll(/from "\.\/events"/g)].length;
  if (n) { js = js.replaceAll('from "./events"', 'from "./events.js"'); adaptations.push({ id: 'A4', target: `assets/controllers/cp/${f.replace(/\.ts$/, '.js')}`, description: 'Import relatif « ./events » → « ./events.js » (le navigateur et AssetMapper exigent l’extension).', occurrences: n }); }
  put(`assets/controllers/cp/${f.replace(/\.ts$/, '.js')}`, `// Généré depuis src/controllers/cp/${f} du design system Civipass (TypeScript → JavaScript). Ne pas modifier à la main :\n// voir design-system/PROVENANCE.md.\n${js}`, { source: `src/controllers/cp/${f}`, adapted: true });
}

/* ── 3. Gabarits Twig ────────────────────────────────────────────────────── */
const LOGOUT_FORM = (cls) => `<form class="cp-menu__form" method="post" action="{{ logout_href|default('#') }}"><input type="hidden" name="_csrf_token" value="{{ csrf_token('logout') }}"><button type="submit" class="${cls}"`;
for (const f of list('templates/components', '.twig')) {
  let t = read(`templates/components/${f}`); let adapted = false;
  if (f === 'topbar.html.twig') {
    t = adapt('A5', 'templates/components/topbar.html.twig', t, '<a class="cp-menu__action" role="menuitem" tabindex="-1" href="{{ logout_href|default(\'#\') }}">', `${LOGOUT_FORM('cp-menu__action')} role="menuitem" tabindex="-1">`, 1, 'Déconnexion : lien GET remplacé par un formulaire POST avec jeton CSRF « logout » (la déconnexion Civipass est POST uniquement).');
    t = adapt('A5', 'templates/components/topbar.html.twig', t, 'Se déconnecter</a>', 'Se déconnecter</button></form>', 1, 'Fermeture du formulaire de déconnexion.');
    adapted = true;
  }
  if (f === 'mobile_menu.html.twig') {
    t = adapt('A6', 'templates/components/mobile_menu.html.twig', t, '<a class="cp-nav__link" href="{{ logout_href }}">', `${LOGOUT_FORM('cp-nav__link cp-nav__link--button')}>`, 1, 'Déconnexion du menu mobile : formulaire POST avec jeton CSRF.');
    t = adapt('A6', 'templates/components/mobile_menu.html.twig', t, '<span>Se déconnecter</span></a>', '<span>Se déconnecter</span></button></form>', 1, 'Fermeture du formulaire de déconnexion du menu mobile.');
    adapted = true;
  }
  if (f === 'lifecycle.html.twig') {
    // Le composant décrit des fonctions des lots Demandes et Laissez-passer, pas encore développées : formulations neutres.
    const target = 'templates/components/lifecycle.html.twig'; const why = 'Formulations neutres : le composant présentait comme opérationnelles des fonctions (formulaire public, demandes, droits) qui ne sont pas encore développées.';
    t = adapt('A9', target, t, "what: 'Préparation, aucun droit émis'", "what: 'Préparation de l’événement'", 1, why);
    t = adapt('A9', target, t, "what: 'Formulaire public ouvert, demandes instruites'", "what: 'Événement en cours'", 1, why);
    t = adapt('A9', target, t, "what: 'Facultatif · droits inutilisables, réactivable'", "what: 'Facultatif · réactivable'", 1, why);
    t = adapt('A9', target, t, "what: 'Définitif · plus aucune demande ni droit'", "what: 'Définitif · aucune réactivation'", 1, why);
    adapted = true;
  }
  if (f === 'zone_card.html.twig') {
    // DM-051 : aucun statut « À confirmer » ; la zone non confirmée est un libellé calculé depuis `confirmedAt`.
    t = adapt('A10', 'templates/components/zone_card.html.twig', t, "unconfirmed: { status: 'pending', label: 'À confirmer' }", "unconfirmed: { status: 'pending', label: 'Non confirmée' }", 1, 'Libellé « Non confirmée » (calculé depuis confirmedAt) à la place de « À confirmer » : DM-051 n’introduit aucun statut « À confirmer ».');
    adapted = true;
  }
  put(`templates/components/${f}`, t, { source: `templates/components/${f}`, adapted });
}
for (const f of list('templates/layouts', '.twig')) {
  let t = read(`templates/layouts/${f}`);
  t = adapt('A7', `templates/layouts/${f}`, t, '{% block stylesheets %}{% endblock %}', "{% block head_meta %}<meta name=\"turbo-cache-control\" content=\"no-cache\">{% endblock %}\n  {% block stylesheets %}\n  <link rel=\"stylesheet\" href=\"{{ asset('styles/civipass/tokens.css') }}\">\n  <link rel=\"stylesheet\" href=\"{{ asset('styles/civipass/civipass.css') }}\">\n  <link rel=\"stylesheet\" href=\"{{ asset('styles/civipass-app.css') }}\">\n  {% endblock %}", 1, 'Feuilles de style chargées par <link> (pas par import JavaScript : pas de flash de page non stylée, fonctionne sans JS) et meta Turbo no-cache (pages à données personnelles).');
  t = adapt('A8', `templates/layouts/${f}`, t, '{% block javascripts %}{% endblock %}', "{% block javascripts %}{{ importmap('app') }}{% endblock %}", 1, 'Point d’entrée JavaScript AssetMapper (Stimulus, Turbo, contrôleurs cp--*).');
  put(`templates/layouts/${f}`, t, { source: `templates/layouts/${f}`, adapted: true });
}

/* ── 4. Exemples (galerie dev) ───────────────────────────────────────────── */
for (const f of list('examples', '.twig')) put(`templates/dev/ui/examples/${f}`, read(`examples/${f}`), { source: `examples/${f}` });

/* ── 5. Écrans du prototype (dev uniquement) : copies adaptées ───────────── */
const SCREEN = { 'dashboard.html.twig': 'dashboard', 'requests.html.twig': 'requests', 'request.html.twig': 'request', 'forbidden.html.twig': 'forbidden' };
for (const [f, name] of Object.entries(SCREEN)) {
  const target = `templates/dev/ui/screens/${name}.html.twig`;
  let t = readScreens(`prototype/templates/${f}`);
  t = adapt('P1', target, t, "{% extends 'pages/_base.html.twig' %}", "{% extends 'dev/ui/screens/_screen.html.twig' %}", 1, 'Layout de prévisualisation Civipass (layouts/app + bandeau « données fictives ») à la place du layout statique du prototype.');
  t = t.replaceAll("href: '../tableau-de-bord.html'", 'href: links.dashboard').replaceAll("href: 'tableau-de-bord.html'", 'href: links.dashboard').replaceAll('href="tableau-de-bord.html"', 'href="{{ links.dashboard }}"')
    .replaceAll("href: 'demandes.html?statut=pending'", 'href: links.requests_pending').replaceAll("action: 'demandes.html'", 'action: links.requests').replaceAll('action="demandes.html"', 'action="{{ links.requests }}"');
  adaptations.push({ id: 'P2', target, description: 'Liens relatifs du prototype (tableau-de-bord.html, demandes.html…) remplacés par les routes /dev/ui via la variable « links ».', occurrences: null });
  if (name === 'requests') {
    const T = 'templates/dev/ui/screens/requests.html.twig';
    t = adapt('P8', T, t, "embed 'components/filter_bar.html.twig' with { id: 'filters',", "embed 'components/filter_bar.html.twig' with { filters: filters, id: 'filters',", 1, 'Valeurs courantes des filtres transmises au bloc « filters » (embed … only).');
    t = adapt('P8', T, t, "submit: false, attrs: 'data-proto-filter' }", 'value: filters.q }', 1, 'Recherche : valeur courante et bouton « Rechercher » (le prototype filtrait en direct par proto.js).');
    t = adapt('P8', T, t, "{ id: 'statut', label: 'Statut', options:", "{ id: 'statut', label: 'Statut', value: filters.statut, options:", 1, 'Filtre Statut : valeur courante.');
    t = adapt('P8', T, t, "{ id: 'depot', label: 'Date de dépôt', options:", "{ id: 'depot', label: 'Date de dépôt', value: filters.depot, options:", 1, 'Filtre Date de dépôt : valeur courante.');
    t = adapt('P8', T, t, "{ id: 'tri', label: 'Trier par', options:", "{ id: 'tri', label: 'Trier par', value: filters.tri, options:", 1, 'Tri : valeur courante.');
    t = adapt('P8', T, t, "count_label: st.total ~ ' demandes', chips: []", "count_label: total ~ (total > 1 ? ' demandes' : ' demande'), chips: chips, active_count: active_count, reset_href: links.requests", 1, 'Compteur, puces de filtres actifs et « Tout effacer » calculés côté serveur.');
    t = adapt('P8', T, t, "caption: '1–20 sur ' ~ st.total ~ ' demandes · plus récentes d’abord'", 'caption: caption', 1, 'Légende du tableau calculée côté serveur.');
    t = adapt('P8', T, t, "sort: 'desc', sort_href: '?tri=old'", 'sort: sort_dir, sort_href: sort_href', 1, 'Tri par colonne « Reçue » calculé côté serveur.');
    t = adapt('P8', T, t, "{ from: 1, to: 20, total: st.total, noun: 'demandes', page: 1, pages: pages, href_pattern: '?page=__PAGE__' }", '{ from: pg.from, to: pg.to, total: total, noun: \'demandes\', page: pg.page, pages: pg.pages, href_pattern: pg.pattern }', 1, 'Pagination calculée côté serveur (filtres conservés dans les liens).');
    t = adaptRe('P8', T, t, /<div data-proto-pagination>(.*?)<\/div>/g, '{% if pg.pages > 0 %}<div>$1</div>{% endif %}', 1, 'Pagination masquée sans résultat.');
  }
  t = t.replace(/href: '#todo:[^']*'/g, "href: '#'");
  adaptations.push({ id: 'P3', target, description: 'Liens « #todo:… » (écrans prévus dans un lot ultérieur) remplacés par « # » : le prototype les signalait par un toast simulé, non repris.', occurrences: null });
  t = t.replace(/\s*data-proto-[a-z-]+(="[^"]*")?/g, '');
  adaptations.push({ id: 'P4', target, description: 'Attributs data-proto-* (simulation du serveur par proto.js) retirés.', occurrences: null });
  if (name === 'requests') {
    t = t.replace(/\n  <template id="proto-row">[\s\S]*$/, '\n{% endblock %}\n');
    adaptations.push({ id: 'P5', target, description: 'Gabarits <template> et JSON de simulation du prototype retirés ; filtres, tri et pagination sont rendus côté serveur (données fictives).', occurrences: 1 });
  }
  if (name === 'forbidden') {
    t = adapt('P9', target, t, "role_label: role }", "role_label: role, links: links }", 1, 'Lien de retour : « links » transmis au bloc de l’alerte (embed … only).');
  }
  if (name === 'request') {
    t = t.replace(/\n\{# Gabarits utilisés par la simulation[\s\S]*$/, '\n{% endblock %}\n');
    t = adapt('P6', target, t, '<div aria-live="polite"></div>', '<div aria-live="polite">{% if flash|default(false) %}{% include \'components/alert.html.twig\' with { variant: \'info\', focus: true, title: \'Prévisualisation : aucune décision n’a été enregistrée\', text: \'Ce formulaire n’écrit rien : les écrans de décision réels arrivent avec le lot Instruction.\' } only %}{% endif %}</div>', 1, 'Zone de message : alerte de prévisualisation après envoi d’un formulaire (simulation du serveur retirée).');
  }
  put(target, t, { source: `prototype/templates/${f}`, adapted: true, screens: true });
}

/* ── 6. Données fictives du prototype (JSON) ─────────────────────────────── */
{
  const data = await import(pathToFileURL(srcScreens('prototype/data.mjs')).href);
  const json = JSON.stringify({ requests: data.buildRequests(), communes: data.COMMUNES, zones: data.ZONES, user: data.USER, event: data.EVENT, draft: data.DRAFT });
  put('src/Shared/UI/DevUi/Resources/prototype.json', json, { source: 'prototype/data.mjs', adapted: true, screens: true });
  adaptations.push({ id: 'P7', target: 'src/Shared/UI/DevUi/Resources/prototype.json', description: 'Données fictives du prototype (aucune personne réelle) exportées en JSON depuis data.mjs, sans modification.', occurrences: null });
}

/* ── 6 bis. Catalogue des composants (galerie dev) ──────────────────────── */
{
  const { catalog } = await import(pathToFileURL(src('scripts/catalog.mjs')).href);
  put('src/Shared/UI/DevUi/Resources/catalog.json', JSON.stringify(catalog.map((c) => ({ name: c.name, group: c.group }))), { source: 'scripts/catalog.mjs', adapted: true });
}

/* ── 6 ter. Monogramme « Cp » (logo du back-office Sonata) ───────────────── */
{
  const color = (name) => tokens.color.tokens.find((t) => t.name === name).value.light;
  const woff = 'node_modules/@fontsource/atkinson-hyperlegible-mono/files/atkinson-hyperlegible-mono-latin-700-normal.woff';
  put('assets/images/civipass-monogram.svg', buildMonogramSvg(src(woff), { brand: color('brand'), onBrand: color('on-brand') }), { source: woff, adapted: true });
  adaptations.push({ id: 'M1', target: 'assets/images/civipass-monogram.svg', description: 'Monogramme « Cp » du design system (composant .cp-logo__mark : carré brand de 30 px, rayon 8 px, Atkinson Hyperlegible Mono Bold 14 px, couleur on-brand) en SVG vectoriel, glyphes convertis en tracés depuis la police du design system. Sert de logo au back-office Sonata, qui ne charge ni le CSS ni les polices du design system. Aucune forme nouvelle.', occurrences: null });
}

/* ── 7. Référentiel et documentation ─────────────────────────────────────── */
put('design-system/tokens.json', read('tokens/tokens.json'), { source: 'tokens/tokens.json' });
const walk = (d) => fs.readdirSync(src(d), { withFileTypes: true }).flatMap((e) => (e.name.startsWith('.') ? [] : e.isDirectory() ? walk(`${d}/${e.name}`) : [`${d}/${e.name}`]));
for (const f of walk('docs')) put(`design-system/${f}`, fs.readFileSync(src(f)), { source: f });

/* ── 8. Provenance ───────────────────────────────────────────────────────── */
const components = list('templates/components', '.twig').map((f) => f.replace('.html.twig', ''));
const provenance = {
  name: pkg.name, version: pkg.version, screensSource: path.basename(screensArchive), tokensVersion: tokens.version, tokensSource: tokens.meta?.source ?? null,
  importedAt: new Date().toISOString().slice(0, 10), tool: 'design-system/tools/import.mjs',
  counts: { componentTemplates: components.length, layouts: list('templates/layouts', '.twig').length, examples: list('examples', '.twig').length, controllers: list('src/controllers/cp', '.ts').length - 1, files: files.length },
  components, adaptations, files,
};
fs.writeFileSync(dst('design-system/provenance.json'), JSON.stringify(provenance, null, 2) + '\n');

const byId = new Map();
for (const a of adaptations) { const e = byId.get(a.id) ?? { id: a.id, targets: new Set(), description: a.description, n: 0 }; e.targets.add(a.target); e.n += a.occurrences ?? 0; byId.set(a.id, e); }
const adapted = files.filter((f) => f.adapted).length;
const md = `# Provenance du design system Civipass

> Fichier généré par \`design-system/tools/import.mjs\` — ne pas modifier à la main. Les empreintes de \`provenance.json\` sont contrôlées par un test (\`DesignSystemIntegrityTest\`) : modifier un fichier importé sans repasser par l'outil fait échouer \`make quality\`.

| | |
|---|---|
| Source | archive « ${path.basename(archive)} » (dépôt du design system, \`package.json\` : \`${pkg.name}\` ${pkg.version}) |
| Écrans et données de la galerie dev | archive « ${path.basename(screensArchive)} » |
| Référentiel de tokens | version ${tokens.version} — ${tokens.meta?.source ?? ''} |
| Date d'import | ${provenance.importedAt} |
| Contenu importé | ${provenance.counts.componentTemplates} gabarits de composants, ${provenance.counts.layouts} layouts du design system, ${provenance.counts.examples} exemples, ${provenance.counts.controllers} contrôleurs Stimulus, 2 feuilles de style, ${list('fonts', '.woff2').length} polices, ${files.length} fichiers au total dont ${adapted} adaptés |

## Où se trouve chaque chose

| Source (archive) | Destination (Civipass) | Statut |
|---|---|---|
| \`dist/tokens.css\` | \`assets/styles/civipass/tokens.css\` | adapté (A1–A3) |
| \`dist/civipass.css\` | \`assets/styles/civipass/civipass.css\` | identique |
| \`fonts/*.woff2\`, \`OFL.txt\` | \`assets/fonts/\` | identique |
| \`src/controllers/cp/*.ts\` | \`assets/controllers/cp/*.js\` | transpilé TypeScript → JavaScript (A4) |
| \`templates/components/*.twig\` | \`templates/components/\` | identique sauf \`topbar\` et \`mobile_menu\` (A5, A6) |
| \`templates/layouts/{app,public}.html.twig\` | \`templates/layouts/\` | adapté (A7, A8) |
| \`examples/*.twig\` | \`templates/dev/ui/examples/\` | identique (galerie dev) |
| \`prototype/templates/*.twig\` | \`templates/dev/ui/screens/\` | copies adaptées (P1–P9), dev uniquement |
| \`prototype/data.mjs\`, \`scripts/catalog.mjs\` | \`src/Shared/UI/DevUi/Resources/*.json\` | exportés sans modification, dev uniquement |
| \`tokens/tokens.json\`, \`docs/\` | \`design-system/\` | identique (référence) |

**Non importés** : \`node_modules/\`, \`dist/\` (sauf les deux CSS ci-dessus), \`scripts/\` de construction, \`prototype/proto.js\`, \`proto.css\`, \`viewer.html\` (simulation du serveur par le prototype), \`package*.json\`, \`tsconfig.json\`. **Créés par Civipass, hors design system** : \`assets/styles/civipass-app.css\`, \`templates/layouts/auth.html.twig\`, les pages d'authentification recomposées, \`templates/dev/ui/*\` (galerie), \`src/Shared/UI/DevUi/*\`.

## Adaptations appliquées (${adaptations.length} substitutions, vérifiées une à une à chaque import)

| Id | Fichier(s) | Description |
|---|---|---|
${[...byId.values()].map((e) => `| ${e.id} | ${[...e.targets].map((t) => '\`' + t + '\`').join(', ')} | ${e.description} |`).join('\n')}

## Écarts constatés dans l'archive (à corriger à la source)

1. **\`tokens.css\`** génère des classes typographiques **non préfixées** (\`.h1\`, \`.small\`, \`.caption\`, \`.body\`, \`.display\`, \`.eyebrow\`, \`.plate\`…) alors que la documentation parle de \`cp-h1\`, \`cp-body\`… ; \`.h1\` et \`.small\` existent dans Bootstrap (Sonata). **Adaptation temporaire A2/A3** ; correction attendue à la source : préfixer en \`cp-\` (hors \`eyebrow\`/\`plate\`, déjà composants).
2. **Chemins de polices** relatifs à \`dist/\` (\`fonts/…\`) : à ajuster selon l'emplacement (A1). Un chemin configurable à la construction éviterait l'adaptation.
3. **Déconnexion en lien GET** (\`topbar\`, \`mobile_menu\`) : incompatible avec une déconnexion POST + CSRF (A5, A6). À prévoir à la source : un paramètre de formulaire de déconnexion.
4. **Layouts sans points d'accroche d'assets** (blocs \`stylesheets\`/\`javascripts\` vides) : renseignés à l'import (A7, A8). Meta Turbo \`no-cache\` ajoutée (pages à données personnelles).
5. **Contrôleurs en TypeScript** : transpilés hors Civipass, JavaScript seul importé ; l'import \`./events\` sans extension est réécrit en \`./events.js\` (A4).
6. **5 styles en ligne** dans des gabarits de composants (\`file_upload\`, \`mobile_menu\`, \`data_table\`, \`freshness\`, \`skeleton\`) : à revoir avant une politique CSP stricte.
7. **Le prototype** utilise des liens « #todo:… » et une simulation du serveur (\`proto.js\`) : non repris ; filtres, tri et pagination de la liste sont rendus côté serveur dans la prévisualisation.
8. **Points fonctionnels non tranchés** (22, dont l'affichage des décisions des autres communes, le journal des scans, la consultation par l'administré) : voir \`design-system/docs/sections/etats-et-arbitrages.md\`. Ils ne sont pas des choix d'interface : ils relèvent des règles métier et de \`docs/security.md\`.

## Mettre à jour le design system

1. Placer la nouvelle archive hors dépôt (ex. \`DesignImport/\`, ignoré par Git).
2. \`node design-system/tools/import.mjs "<archive>"\` (poste de développement ; l'outil n'écrit rien dans l'archive).
3. Si une adaptation échoue (« n occurrence(s) … attendue(s) »), l'archive a changé de forme : revoir l'adaptation dans l'outil, jamais corriger le fichier importé.
4. \`git diff\` pour relire, \`make quality\` (compilation des 56 exemples, intégrité, auth), consulter \`/dev/ui\`.
5. Commiter l'import seul (outil, fichiers importés, \`provenance.json\`, \`PROVENANCE.md\`).
`;
fs.writeFileSync(dst('design-system/PROVENANCE.md'), md);
console.log(`import terminé : ${files.length} fichiers, ${adaptations.length} adaptations, ${components.length} gabarits de composants.`);
