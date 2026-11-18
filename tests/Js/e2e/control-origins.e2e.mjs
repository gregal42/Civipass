// Parcours e2e LOCAL à DEUX ORIGINES (LOT 10, V4) : PWA sur l'origine de contrôle, gestion sur une autre origine, garde-fous G1, G2, G3 actifs. Lancé par `bin/e2e-control-origins`.
// Vérifie : liste blanche et refus génériques des deux côtés, séparation du stockage local, des cookies et des sessions, périmètre du Service Worker, API non appelable depuis
// l'origine de gestion (CORS), vérification en ligne sur l'origine de gestion, aucun cookie sur l'origine de contrôle, aucune ressource tierce.
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { launchChrome } from './cdp.mjs';

const CONTROL = process.env.E2E_CONTROL; // http://localhost:8099
const MGMT = process.env.E2E_MGMT; // http://127.0.0.1:8099
const seed = JSON.parse(process.env.E2E_SEED);
const PHP = process.env.E2E_DOCKER_PHP ?? 'saas_civipass_php-fpm';
const CHROME = process.env.CHROME_PATH ?? '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const helper = (...args) => JSON.parse(execFileSync('docker', ['exec', '-e', `DEFAULT_URI=${MGMT}`, PHP, 'php', 'tests/Support/e2e-control.php', ...args], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim().split('\n').at(-1));

const results = [];
const step = async (name, fn) => { process.stdout.write(`… ${name}\n`); await fn(); results.push(name); process.stdout.write(`✔ ${name}\n`); };

const chrome = await launchChrome({ chromePath: CHROME });
const problems = [];
const requested = new Set();
const responses = []; // { url, status, headers }
let collectProblems = true;
chrome.on((m) => {
  if (m.method === 'Runtime.exceptionThrown' && collectProblems) problems.push(`exception : ${m.params.exceptionDetails.text} ${m.params.exceptionDetails.exception?.description ?? ''}`);
  if (m.method === 'Network.requestWillBeSent') requested.add(m.params.request.url);
  if (m.method === 'Network.responseReceived') {
    responses.push({ url: m.params.response.url, status: m.params.response.status, headers: Object.fromEntries(Object.entries(m.params.response.headers).map(([k, v]) => [k.toLowerCase(), v])) });
    if (collectProblems && m.params.response.status >= 400 && !/favicon/.test(m.params.response.url) && !(m.params.response.status === 403 && /\/controle\/api\/sync$/.test(m.params.response.url))) problems.push(`HTTP ${m.params.response.status} ${m.params.response.url}`);
  }
});
const click = (text) => chrome.evaluate(`(() => { const b = [...document.querySelectorAll('button')].find((x) => x.textContent.trim() === ${JSON.stringify(text)}); if (!b) return false; b.click(); return true; })()`);
const bodyHas = (text) => `document.getElementById('cc-app')?.textContent.includes(${JSON.stringify(text)})`;
const go = async (url) => { await chrome.send('Page.navigate', { url }); await chrome.waitFor(`document.readyState === 'complete'`, { label: `chargement ${url}` }); };
const cookies = async (origin) => (await chrome.send('Network.getCookies', { urls: [origin + '/'] })).cookies;
// fetch exécuté DANS la page de l'origine courante ; renvoie statut, type de réponse et en-têtes utiles.
const probe = (path, init = {}) => chrome.evaluate(`fetch(${JSON.stringify(path)}, ${JSON.stringify({ credentials: 'include', ...init })}).then(async (r) => ({ status: r.status, csp: r.headers.get('content-security-policy'), nosniff: r.headers.get('x-content-type-options'), acao: r.headers.get('access-control-allow-origin'), text: (await r.text()).slice(0, 200) })).catch((e) => ({ error: String(e) }))`);

try {
  await chrome.send('Page.enable'); await chrome.send('Runtime.enable'); await chrome.send('Network.enable'); await chrome.send('Log.enable');

  // ═══ 1. L'application de contrôle vit sur son origine, par la liste blanche G1 ═══
  await step('origine de contrôle : la coquille, le manifeste et le Service Worker (périmètre /controle/) se chargent', async () => {
    await go(`${CONTROL}/controle/`);
    assert.equal(await chrome.evaluate('document.title'), 'Contrôle · Civipass');
    await chrome.waitFor(bodyHas('Activer cet appareil'), { label: 'écran d\'activation' });
    await chrome.waitFor(`navigator.serviceWorker.ready.then((r) => !!r.active)`, { label: 'service worker actif' });
    assert.equal(await chrome.evaluate(`navigator.serviceWorker.getRegistration().then((r) => r.scope)`), `${CONTROL}/controle/`, 'périmètre du Service Worker');
    const cached = await chrome.evaluate(`caches.keys().then(async (ks) => { const c = await caches.open(ks[0]); return (await c.keys()).map((r) => new URL(r.url).pathname); })`);
    assert.ok(cached.includes('/controle/') && cached.some((p) => /jsQR/.test(p)) && cached.some((p) => /controle-.*\.css$/.test(p)), 'coquille, décodeur et styles conservés (la précache passe la liste blanche)');
    const manifest = await probe('/controle/manifest.webmanifest');
    assert.equal(manifest.status, 200);
    assert.deepEqual(await cookies(CONTROL), [], 'aucun cookie sur l\'origine de contrôle après le chargement de la PWA');
  });

  await step('activation, approbation et synchronisation sur l\'origine de contrôle (API sous liste blanche)', async () => {
    await chrome.evaluate(`(() => { const set = (id, v) => { const e = document.getElementById(id); e.value = v; e.dispatchEvent(new Event('input', { bubbles: true })); }; set('cc-code', ${JSON.stringify(seed.code)}); set('cc-name', 'Barrage origines'); })()`);
    assert.ok(await click('Activer'));
    await chrome.waitFor(bodyHas('En attente d’approbation'), { label: 'attente d\'approbation' });
    helper('approve', 'Barrage origines');
    await chrome.waitFor(bodyHas(seed.municipality), { timeoutMs: 30000, label: 'données reçues' });
    const rows = await chrome.evaluate(`new Promise((resolve) => { const r = indexedDB.open('civipass-control'); r.onsuccess = () => { const g = r.result.transaction('rec').objectStore('rec').count(); g.onsuccess = () => resolve(g.result); }; })`);
    assert.equal(rows, 3);
    assert.deepEqual(await cookies(CONTROL), [], 'toujours aucun cookie : l\'appareil s\'authentifie par un secret en en-tête, sans session');
  });

  await step('la vérification en ligne s\'ouvre sur l\'origine de GESTION (hors périmètre de la PWA), sans cookie', async () => {
    const online = await chrome.evaluate(`import('/controle/js/scan.js').then((m) => m.onlineUrl(${JSON.stringify(seed.tokens.MARTIN)}, document.getElementById('cc-app').dataset.verificationBase))`);
    assert.equal(online, `${MGMT}/v/${seed.tokens.MARTIN}`);
    const before = responses.length;
    await go(online);
    const body = await chrome.evaluate('document.body.innerText');
    assert.match(body, /Droits actifs|MARTIN/, 'page de vérification servie par l\'origine de gestion');
    const verification = responses.slice(before).find((r) => r.url === online);
    assert.equal(verification?.status, 200);
    assert.equal(verification?.headers['set-cookie'], undefined, 'aucun cookie posé par la vérification en ligne');
    assert.deepEqual((await cookies(MGMT)).filter((c) => /SESS/i.test(c.name)), [], 'aucune session créée par /v');
  });

  // ═══ 2. Séparation des deux origines (phase de refus volontaires : les 404 attendus ne sont plus des « problèmes ») ═══
  collectProblems = false;
  await step('stockage local séparé : l\'origine de gestion ne voit ni la base, ni le Service Worker, ni les caches de la PWA', async () => {
    await go(`${MGMT}/login`);
    const state = await chrome.evaluate(`(async () => ({ dbs: (await indexedDB.databases()).map((d) => d.name), sw: (await navigator.serviceWorker.getRegistrations()).length, caches: await caches.keys() }))()`);
    assert.deepEqual(state.dbs, [], 'aucune base IndexedDB sur l\'origine de gestion');
    assert.equal(state.sw, 0, 'aucun Service Worker sur l\'origine de gestion');
    assert.deepEqual(state.caches, [], 'aucun cache sur l\'origine de gestion');
  });

  await step('cookies séparés : la gestion a sa session, le contrôle n\'en a aucune et n\'en reçoit aucune', async () => {
    const mgmt = await cookies(MGMT);
    assert.ok(mgmt.some((c) => /SESS/i.test(c.name)), 'la page de connexion de la gestion démarre une session (cookie propre à son origine)');
    assert.deepEqual(await cookies(CONTROL), [], 'ce cookie n\'existe pas sur l\'origine de contrôle');
    await go(`${CONTROL}/controle/`);
    const ping = await probe('/controle/api/ping', { headers: { Cookie: 'PHPSESSID=forge' } });
    assert.equal(ping.status, 200);
    assert.deepEqual(await cookies(CONTROL), [], 'toujours aucun cookie après un appel de l\'API');
  });

  await step('G1 : sur l\'origine de contrôle, tout ce qui n\'est pas dans la liste blanche répond le 404 générique protégé', async () => {
    await go(`${CONTROL}/controle/`);
    for (const [method, path] of [['GET', '/login'], ['GET', '/communes'], ['GET', '/admin/dashboard'], ['GET', `/v/${seed.tokens.MARTIN}`], ['GET', '/demandes/EVT-2026-000001/identite'], ['GET', '/'], ['GET', '/controle/inconnu'], ['POST', '/controle/api/ping'], ['GET', '/controle/api/activation'], ['GET', '/assets/secret.txt'], ['GET', '/controle']]) {
      const r = await probe(path, { method });
      assert.equal(r.status, 404, `${method} ${path}`);
      assert.match(r.csp ?? '', /default-src 'none'/, `politique de protection sur le 404 ${path}`);
      assert.equal(r.nosniff, 'nosniff');
    }
  });

  await step('G3 : sur l\'origine de gestion, la PWA, son Service Worker et son API répondent le même 404', async () => {
    await go(`${MGMT}/login`);
    for (const [method, path] of [['GET', '/controle/'], ['GET', '/controle/sw.js'], ['GET', '/controle/manifest.webmanifest'], ['GET', '/controle/js/app.js'], ['GET', '/controle/api/ping'], ['POST', '/controle/api/sync']]) {
      const r = await probe(path, { method });
      assert.equal(r.status, 404, `${method} ${path}`);
      assert.doesNotMatch(r.text, /cc-app|Contrôle/, 'jamais la coquille de la PWA');
    }
    assert.equal((await probe('/login')).status, 200, 'la gestion, elle, fonctionne');
  });

  await step('l\'API de contrôle n\'est pas appelable depuis l\'origine de gestion (CORS), ni la gestion depuis le contrôle', async () => {
    const cross = await probe(`${CONTROL}/controle/api/ping`, { mode: 'cors' });
    assert.ok(cross.error, 'appel inter-origines refusé par le navigateur (aucun en-tête CORS)');
    await go(`${CONTROL}/controle/`);
    const back = await probe(`${MGMT}/login`, { mode: 'cors' });
    assert.ok(back.error, 'la gestion n\'est pas non plus lisible depuis l\'origine de contrôle');
  });

  await step('G2 : un hôte forgé (même serveur, autre nom) est refusé (400), les deux origines légitimes sont servies', async () => {
    // Le navigateur interdit de forger l'en-tête Host : l'hôte forgé est présenté au serveur par curl, depuis le conteneur.
    const code = execFileSync('docker', ['exec', PHP, 'sh', '-c', `curl -s -o /dev/null -w '%{http_code}' -H 'Host: evil.example' http://saas_civipass_e2e_origins:8099/login`], { encoding: 'utf8' }).trim();
    assert.equal(code, '400', 'hôte forgé refusé avant le routage');
    assert.equal((await probe('/controle/api/ping')).status, 200);
  });

  await step('retour sur l\'origine de contrôle : la PWA fonctionne toujours hors connexion, périmètre du Service Worker respecté', async () => {
    await go(`${CONTROL}/controle/`);
    await chrome.send('Network.emulateNetworkConditions', { offline: true, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
    await chrome.send('Page.reload');
    await chrome.waitFor(bodyHas(seed.municipality), { timeoutMs: 20000, label: 'coquille et données hors ligne' });
    await chrome.send('Network.emulateNetworkConditions', { offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
    assert.deepEqual(await cookies(CONTROL), [], 'aucun cookie sur toute la durée du parcours');
  });

  await step('aucune requête vers un tiers, aucune erreur ni violation de CSP pendant le parcours de la PWA', async () => {
    const foreign = [...requested].filter((u) => !u.startsWith(CONTROL) && !u.startsWith(MGMT) && !u.startsWith('data:') && !u.startsWith('about:') && !u.startsWith('chrome'));
    assert.deepEqual(foreign, [], 'ressources tierces');
    assert.deepEqual(problems, [], 'erreurs de page pendant la phase de la PWA');
  });
} catch (error) {
  console.error(`✖ ${error.message}`);
  if (problems.length > 0) console.error(problems.join('\n'));
  try { console.error('Page :', (await chrome.evaluate('document.body.innerText')).slice(0, 600)); } catch { /* page indisponible */ }
  await chrome.close();
  process.exit(1);
}
await chrome.close();
console.log(`\nParcours e2e à deux origines réussi : ${results.length} étapes.`);
