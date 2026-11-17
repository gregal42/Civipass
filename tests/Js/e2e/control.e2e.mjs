// Parcours e2e LOCAL du contrôle hors connexion (LOT 09.6) : vrai Chrome (IndexedDB, Service Worker, CSP, réseau coupé), vrai serveur PHP (base de TEST), données FICTIVES.
// Lancé par `bin/qa e2e` (qui démarre le serveur local et sème les données). Variables : E2E_BASE (http://localhost:8099), E2E_SEED (JSON), CHROME_PATH, E2E_DOCKER_PHP.
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { launchChrome } from './cdp.mjs';

const BASE = process.env.E2E_BASE ?? 'http://localhost:8099';
const seed = JSON.parse(process.env.E2E_SEED);
const PHP = process.env.E2E_DOCKER_PHP ?? 'saas_civipass_php-fpm';
const CHROME = process.env.CHROME_PATH ?? '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const helper = (...args) => {
  const out = execFileSync('docker', ['exec', '-e', `DEFAULT_URI=${BASE}`, PHP, 'php', 'tests/Support/e2e-control.php', ...args], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] });
  return JSON.parse(out.trim().split('\n').at(-1));
};

const results = [];
const step = async (name, fn) => {
  process.stdout.write(`… ${name}\n`);
  await fn();
  results.push(name);
  process.stdout.write(`✔ ${name}\n`);
};

const chrome = await launchChrome({ chromePath: CHROME });
const problems = [];
const requested = new Set();
chrome.on((message) => {
  if (message.method === 'Runtime.exceptionThrown') problems.push(`exception : ${message.params.exceptionDetails.text} ${message.params.exceptionDetails.exception?.description ?? ''}`);
  if (message.method === 'Log.entryAdded' && message.params.entry.level === 'error' && !/ERR_INTERNET_DISCONNECTED|Failed to fetch|net::ERR|Failed to load resource/.test(message.params.entry.text)) problems.push(`journal : ${message.params.entry.text}`);
  if (message.method === 'Network.requestWillBeSent') requested.add(message.params.request.url);
  if (message.method === 'Network.responseReceived' && message.params.response.status >= 400 && !/favicon/.test(message.params.response.url) && !(message.params.response.status === 403 && /\/controle\/api\/sync$/.test(message.params.response.url))) problems.push(`HTTP ${message.params.response.status} ${message.params.response.url}`);
});

const click = (text) => chrome.evaluate(`(() => { const b = [...document.querySelectorAll('button')].find((x) => x.textContent.trim() === ${JSON.stringify(text)}); if (!b) return false; b.click(); return true; })()`);
const bodyHas = (text) => `document.getElementById('cc-app')?.textContent.includes(${JSON.stringify(text)})`;
const modules = {};

async function load() {
  await chrome.send('Page.enable'); await chrome.send('Runtime.enable'); await chrome.send('Log.enable'); await chrome.send('Network.enable');
  await chrome.send('Page.navigate', { url: `${BASE}/controle/` });
  await chrome.waitFor(`document.readyState === 'complete'`, { label: 'page chargée' });
}

async function importModules() {
  const list = await chrome.evaluate(`fetch('/controle/sw.js').then((r) => r.text()).then((t) => JSON.parse(t.match(/const PRECACHE = (\\[.*?\\]);/s)[1]))`);
  for (const name of ['verification', 'idb-store', 'verdict', 'sync', 'activation', 'api']) modules[name] = list.find((p) => p === `/controle/js/${name}.js`);
  assert.ok(Object.values(modules).every(Boolean), 'modules précédemment conservés');
}

const verdictOf = (token, now = 'Math.floor(Date.now()/1000)') => chrome.evaluate(`(async () => {
  const { IdbStore } = await import(${JSON.stringify(modules['idb-store'])});
  const { offlineVerdict } = await import(${JSON.stringify(modules.verification)});
  const v = await offlineVerdict({ store: new IdbStore(), token: ${JSON.stringify(token)}, now: ${now} });
  return JSON.parse(JSON.stringify(v));
})()`);

try {
  await step('la coquille se charge sans erreur ni violation de CSP, sans ressource tierce', async () => {
    await load();
    assert.equal(await chrome.evaluate('document.title'), 'Contrôle · Civipass');
    await chrome.waitFor(bodyHas('Activer cet appareil'), { label: 'écran d\'activation' });
  });

  await step('le Service Worker est actif et la coquille est conservée', async () => {
    await chrome.waitFor(`navigator.serviceWorker.ready.then((r) => !!r.active)`, { label: 'service worker actif' });
    const cached = await chrome.evaluate(`caches.keys().then(async (ks) => { const c = await caches.open(ks[0]); return (await c.keys()).map((r) => new URL(r.url).pathname); })`);
    assert.ok(cached.includes('/controle/'), 'coquille conservée');
    assert.ok(cached.some((p) => /jsQR/.test(p)), 'décodeur conservé');
    await importModules();
  });

  await step('le décodeur jsQR embarqué lit le QR code d\'un laissez-passer (rendu réel de l\'application)', async () => {
    const text = await chrome.evaluate(`(async () => {
      const img = new Image(); img.src = 'data:image/png;base64,${seed.qrPng}'; await img.decode();
      const c = document.createElement('canvas'); c.width = img.width; c.height = img.height;
      const x = c.getContext('2d'); x.drawImage(img, 0, 0);
      const d = x.getImageData(0, 0, c.width, c.height);
      const t0 = performance.now(); const r = jsQR(d.data, d.width, d.height); return { data: r && r.data, ms: performance.now() - t0 };
    })()`);
    assert.equal(text.data, `${BASE}/v/${seed.tokens.MARTIN}`);
    assert.ok(text.ms < 2000, `décodage en ${Math.round(text.ms)} ms`);
  });

  await step('activation : code saisi → en attente d\'approbation avec un code d\'appariement, aucune donnée', async () => {
    await chrome.evaluate(`(() => { const set = (id, v) => { const e = document.getElementById(id); e.value = v; e.dispatchEvent(new Event('input', { bubbles: true })); }; set('cc-code', ${JSON.stringify(seed.code)}); set('cc-name', 'Barrage e2e'); })()`);
    assert.ok(await click('Activer'));
    await chrome.waitFor(bodyHas('En attente d’approbation'), { label: 'écran d\'attente' });
    const pairing = await chrome.evaluate(`document.querySelector('.cc-pairing').textContent`);
    assert.match(pairing, /^[0-9]{6}$/);
    const rows = await chrome.evaluate(`new Promise((resolve) => { const r = indexedDB.open('civipass-control'); r.onsuccess = () => { const db = r.result; const g = db.transaction('rec').objectStore('rec').count(); g.onsuccess = () => resolve(g.result); }; r.onerror = () => resolve(-1); })`);
    assert.equal(rows, 0, 'aucune donnée personnelle avant approbation');
  });

  await step('approbation par l\'administrateur → synchronisation automatique → données reçues', async () => {
    helper('approve', 'Barrage e2e');
    await chrome.waitFor(bodyHas(seed.municipality), { timeoutMs: 30000, label: 'accueil avec données' });
    const rows = await chrome.evaluate(`new Promise((resolve) => { const r = indexedDB.open('civipass-control'); r.onsuccess = () => { const g = r.result.transaction('rec').objectStore('rec').getAll(); g.onsuccess = () => resolve(g.result); }; })`);
    assert.equal(rows.length, 3);
    const dump = JSON.stringify(rows);
    for (const token of Object.values(seed.tokens)) assert.equal(dump.includes(token), false, 'aucun jeton brut sur l\'appareil');
    for (const forbidden of ['e2e@exemple.fr', '0611223344', 'Lilas']) assert.equal(dump.includes(forbidden), false, `donnée interdite : ${forbidden}`);
    assert.ok(dump.includes('MARTIN'));
  });

  await step('le téléphone perd le réseau : la page se recharge depuis le Service Worker', async () => {
    await chrome.send('Network.emulateNetworkConditions', { offline: true, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
    await chrome.send('Page.reload');
    await chrome.waitFor(bodyHas(seed.municipality), { timeoutMs: 20000, label: 'coquille et données hors ligne' });
    assert.equal(await chrome.evaluate('document.title'), 'Contrôle · Civipass');
  });

  await step('hors connexion : valide selon la dernière synchronisation (orange, qualifié), jeton inconnu = indéterminé', async () => {
    const valid = await verdictOf(seed.tokens.MARTIN);
    assert.equal(valid.code, 'VALID');
    assert.equal(valid.person.last, 'MARTIN');
    assert.equal(valid.qualifier.band, 'recent');
    assert.equal((await verdictOf('z'.repeat(31) + '2')).reason, 'unknown');
  });

  await step('horloge : avance de 7 h = données vieillissantes ; 13 h = indéterminé ; recul = indéterminé', async () => {
    assert.equal((await verdictOf(seed.tokens.MARTIN, 'Math.floor(Date.now()/1000) + 7*3600')).code, 'VALID_OLD');
    assert.equal((await verdictOf(seed.tokens.DURAND, 'Math.floor(Date.now()/1000) + 13*3600')).reason, 'stale');
    assert.equal((await verdictOf(seed.tokens.LEROY, 'Math.floor(Date.now()/1000) - 3*3600')).reason, 'clock-backwards');
  });

  await step('retour du réseau : révocation côté serveur → synchronisation → révoqué hors connexion', async () => {
    await chrome.send('Network.emulateNetworkConditions', { offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
    helper('revoke', seed.references.DURAND);
    assert.ok(await click('Synchroniser'));
    await chrome.waitFor(bodyHas('Synchronisation réussie'), { timeoutMs: 20000, label: 'synchronisation' });
    await chrome.send('Network.emulateNetworkConditions', { offline: true, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
    assert.equal((await verdictOf(seed.tokens.DURAND)).code, 'REVOKED');
    assert.equal((await verdictOf(seed.tokens.MARTIN)).code, 'VALID');
  });

  await step('l\'administrateur désactive l\'appareil : à la reconnexion, refus et suppression des données locales', async () => {
    await chrome.send('Network.emulateNetworkConditions', { offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
    helper('deactivate', 'Barrage e2e');
    assert.ok(await click('Synchroniser'));
    await chrome.waitFor(bodyHas('Appareil désactivé'), { timeoutMs: 20000, label: 'appareil désactivé' });
    const rows = await chrome.evaluate(`new Promise((resolve) => { const r = indexedDB.open('civipass-control'); r.onsuccess = () => { const g = r.result.transaction('rec').objectStore('rec').count(); g.onsuccess = () => resolve(g.result); }; })`);
    assert.equal(rows, 0);
    assert.equal((await verdictOf(seed.tokens.MARTIN)).code, 'INDETERMINATE');
  });

  await step('aucune requête vers un tiers, aucune erreur ni violation de CSP pendant tout le parcours', async () => {
    const foreign = [...requested].filter((u) => !u.startsWith(BASE) && !u.startsWith('data:') && !u.startsWith('about:') && !u.startsWith('chrome'));
    assert.deepEqual(foreign, [], 'ressources tierces');
    assert.deepEqual(problems, [], 'erreurs de page');
  });
} catch (error) {
  console.error(`✖ ${error.message}`);
  if (problems.length > 0) console.error(problems.join('\n'));
  try { console.error('Page :', (await chrome.evaluate('document.body.innerText')).slice(0, 600)); } catch { /* page indisponible */ }
  await chrome.close();
  process.exit(1);
}
await chrome.close();
console.log(`\nParcours e2e réussi : ${results.length} étapes.`);
