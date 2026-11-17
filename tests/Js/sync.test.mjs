// Synchronisation, activation et verdict de bout en bout (LOT 09.4/09.5) avec un serveur factice : synchronisation interrompue, jeu incomplet ou corrompu, repli sur
// l'instantané complet, appareil désactivé/expiré/en attente, changement de profil, absence de fuite du jeton, expiration et suppression des données locales.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { activate, detectPlatform, extractActivationCode, refreshStatus } from '../../assets/controle/activation.js';
import { MemoryStore } from '../../assets/controle/store.js';
import { runSync } from '../../assets/controle/sync.js';
import { offlineVerdict } from '../../assets/controle/verification.js';
import { H, NOW, activeStore, dataset, fakeApi, permitLine, tombstoneLine } from './helpers.mjs';

const TOKEN = 'abcdefghijklmnopqrstuvwxyz234567';
const SECRET = 'S'.repeat(43);

async function fresh(entries, options = {}) {
  const store = new MemoryStore();
  await store.setCredential({ secret: SECRET, label: 'Barrage Nord', status: 'PENDING' });
  const ds = await dataset(entries, options);
  const api = fakeApi({ dataset: ds });
  const clock = { now: options.now ?? NOW };
  const deps = { api, store, nowSeconds: () => clock.now };

  return { store, ds, api, clock, deps };
}

test('première synchronisation : instantané complet adopté, appareil ACTIF, verdict hors ligne possible', async () => {
  const { store, deps, api } = await fresh([await permitLine(TOKEN)]);
  const result = await runSync(deps);

  assert.deepEqual({ state: result.state, mode: result.mode, count: result.count }, { state: 'synced', mode: 'full', count: 1 });
  assert.equal(api.calls[0].buckets, null, 'aucun condensat envoyé à la première synchronisation');
  assert.equal((await store.getCredential()).status, 'ACTIVE');
  const verdict = await offlineVerdict({ store, token: TOKEN, now: NOW });
  assert.equal(verdict.code, 'VALID');
  assert.equal(verdict.person.last, 'MARTIN');
});

test('synchronisation suivante : « inchangé » puis différentiel, avec vérification du condensat racine', async () => {
  const { store, deps, api, ds } = await fresh([await permitLine(TOKEN)]);
  await runSync(deps);
  assert.equal((await runSync(deps)).mode, 'unchanged');

  api.state.dataset = await dataset([await permitLine(TOKEN), await tombstoneLine('b'.repeat(32), 'R'), await permitLine('c'.repeat(32), { last: 'DURAND' })], { now: NOW + H });
  deps.nowSeconds = () => NOW + H;
  const result = await runSync(deps);
  assert.equal(result.mode, 'delta');
  assert.equal(result.count, 3);
  assert.equal((await store.getRecords()).size, 3);
  assert.notEqual(ds, null);
});

test('synchronisation interrompue (réseau) : l\'ancien jeu reste intact et utilisable', async () => {
  const { store, deps, api } = await fresh([await permitLine(TOKEN)]);
  await runSync(deps);
  const before = await store.getRecords();
  const profileBefore = await store.getProfile();
  api.state.syncResponse = { kind: 'network' };

  assert.equal((await runSync(deps)).state, 'network');
  assert.deepEqual([...(await store.getRecords())], [...before]);
  assert.equal((await store.getProfile()).syncedAt, profileBefore.syncedAt);
  assert.equal((await offlineVerdict({ store, token: TOKEN, now: NOW + H })).code, 'VALID');
});

test('réponse tronquée, altérée ou rejouée : refusée, ancien jeu intact', async () => {
  const { store, deps, api, ds } = await fresh([await permitLine(TOKEN), await permitLine('c'.repeat(32), { last: 'DURAND' })]);
  await runSync(deps);
  const before = [...(await store.getRecords())];
  const bad = {
    tronquée: { ...ds.envelope, mode: 'full', lines: ds.lines.slice(0, 1) },
    altérée: { ...ds.envelope, mode: 'full', lines: ds.lines.map((l) => l.replace('MARTIN', 'MARTIM').replace('DURAND', 'DURANT')) },
    'enveloppe invalide': { mode: 'full', lines: ds.lines },
    'jeu daté du futur': { ...ds.envelope, gen: NOW + 3 * H, mode: 'full', lines: ds.lines },
  };
  for (const [name, body] of Object.entries(bad)) {
    api.state.syncResponse = { kind: 'http', status: 200, ok: true, body, retryAfter: 0 };
    const result = await runSync(deps);
    assert.equal(result.state, 'integrity', name);
    assert.deepEqual([...(await store.getRecords())], before, name);
  }
  api.state.syncResponse = { kind: 'http', status: 200, ok: true, body: null, retryAfter: 0 };
  assert.equal((await runSync(deps)).state, 'integrity', 'corps illisible');
});

test('différentiel invalide : repli UNE fois sur l\'instantané complet', async () => {
  const { store, deps, api, ds } = await fresh([await permitLine(TOKEN)]);
  await runSync(deps);
  api.state.syncResponse = (buckets) => ({
    kind: 'http', status: 200, ok: true, retryAfter: 0,
    body: buckets === null ? { ...ds.envelope, mode: 'full', lines: ds.lines } : { ...ds.envelope, mode: 'delta', chg: { 0: ['{"corrompu'] } },
  });
  const result = await runSync(deps);

  assert.equal(result.state, 'synced');
  assert.equal(result.mode, 'full');
  assert.equal(result.retriedFull, true);
  assert.equal(api.calls.at(-1).buckets, null);
  assert.equal((await store.getRecords()).size, 1);
});

test('appareil désactivé : jeu supprimé immédiatement, verdict indéterminé', async () => {
  const { store, deps, api } = await fresh([await permitLine(TOKEN)]);
  await runSync(deps);
  api.state.syncResponse = { kind: 'http', status: 403, ok: false, body: { status: 'DEACTIVATED' }, retryAfter: 0 };

  assert.equal((await runSync(deps)).state, 'deactivated');
  assert.equal((await store.getRecords()).size, 0);
  assert.equal(await store.getProfile(), null);
  assert.equal((await store.getCredential()).status, 'DEACTIVATED');
  const verdict = await offlineVerdict({ store, token: TOKEN, now: NOW });
  assert.equal(verdict.code, 'INDETERMINATE');
});

test('appareil expiré côté serveur : suppression ; secret inconnu (401) : suppression complète', async () => {
  const a = await fresh([await permitLine(TOKEN)]);
  await runSync(a.deps);
  a.api.state.syncResponse = { kind: 'http', status: 403, ok: false, body: { status: 'EXPIRED' }, retryAfter: 0 };
  assert.equal((await runSync(a.deps)).state, 'expired');
  assert.equal((await a.store.getRecords()).size, 0);

  const b = await fresh([await permitLine(TOKEN)]);
  await runSync(b.deps);
  b.api.state.syncResponse = { kind: 'http', status: 401, ok: false, body: { error: 'unauthorized' }, retryAfter: 0 };
  assert.equal((await runSync(b.deps)).state, 'unauthorized');
  assert.equal(await b.store.getCredential(), null);
  assert.equal((await b.store.getRecords()).size, 0);
});

test('appareil en attente d\'approbation : aucune donnée reçue ni conservée', async () => {
  const { store, deps, api } = await fresh([await permitLine(TOKEN)]);
  api.state.syncResponse = { kind: 'http', status: 403, ok: false, body: { status: 'PENDING' }, retryAfter: 0 };

  assert.equal((await runSync(deps)).state, 'pending');
  assert.equal((await store.getRecords()).size, 0);
  assert.equal(await store.getProfile(), null);
});

test('limitation (429) et erreur serveur : rien n\'est modifié', async () => {
  const { store, deps, api } = await fresh([await permitLine(TOKEN)]);
  await runSync(deps);
  const before = [...(await store.getRecords())];
  api.state.syncResponse = { kind: 'http', status: 429, ok: false, body: { error: 'throttled' }, retryAfter: 42 };
  assert.deepEqual(await runSync(deps), { state: 'throttled', retryAfter: 42 });
  api.state.syncResponse = { kind: 'http', status: 503, ok: false, body: null, retryAfter: 0 };
  assert.equal((await runSync(deps)).state, 'server-error');
  assert.deepEqual([...(await store.getRecords())], before);
});

test('horloge du téléphone décalée à la synchronisation : jeu reçu mais inutilisable (indéterminé)', async () => {
  const { store, deps, clock } = await fresh([await permitLine(TOKEN)]);
  clock.now = NOW + 3 * H; // téléphone en avance de 3 h sur le serveur (jeu généré à NOW)
  const result = await runSync(deps);

  assert.equal(result.state, 'synced');
  assert.equal(result.clockBad, true);
  const verdict = await offlineVerdict({ store, token: TOKEN, now: clock.now });
  assert.equal(verdict.code, 'INDETERMINATE');
  assert.equal(verdict.reason, 'clock-offset');
});

test('le jeu devient indéterminé au-delà du seuil dur et les noms sont supprimés à l\'ouverture ; les empreintes révoquées restent', async () => {
  const { store, deps } = await fresh([await permitLine(TOKEN), await tombstoneLine('b'.repeat(32), 'R')]);
  await runSync(deps);
  const late = NOW + 13 * H;

  assert.equal((await offlineVerdict({ store, token: TOKEN, now: late })).reason, 'stale');
  const lines = [...(await store.getRecords()).values()].join('');
  assert.equal(lines.includes('MARTIN'), false, 'le nom a été supprimé');
  assert.equal((await offlineVerdict({ store, token: 'b'.repeat(32), now: late })).code, 'REVOKED');
});

test('le verdict enregistre seulement la « dernière heure vue » : aucun journal de scan, aucun jeton conservé', async () => {
  const { store, deps } = await fresh([await permitLine(TOKEN)]);
  await runSync(deps);
  await offlineVerdict({ store, token: TOKEN, now: NOW + 600 });

  assert.equal((await store.getProfile()).lastSeen, NOW + 600);
  const dump = JSON.stringify({ c: await store.getCredential(), p: await store.getProfile(), r: [...(await store.getRecords())] });
  assert.equal(dump.includes(TOKEN), false, 'le jeton brut n\'est jamais conservé');
  assert.deepEqual(Object.keys(store).sort(), ['credentialValue', 'profileValue', 'recordsValue']);
});

test('une horloge reculée entre deux usages donne indéterminé ; la dérive de session aussi', async () => {
  const { store, deps } = await fresh([await permitLine(TOKEN)]);
  await runSync(deps);
  await offlineVerdict({ store, token: TOKEN, now: NOW + 4 * H });
  assert.equal((await offlineVerdict({ store, token: TOKEN, now: NOW + H })).reason, 'clock-backwards');
  assert.equal((await offlineVerdict({ store, token: TOKEN, now: NOW + 5 * H, sessionClockProblem: 'clock-changed' })).reason, 'clock-changed');
});

test('mode STRICT de bout en bout', async () => {
  const { store, deps } = await fresh([await permitLine(TOKEN)], { mode: 'STRICT' });
  await runSync(deps);
  const verdict = await offlineVerdict({ store, token: TOKEN, now: NOW });

  assert.equal(verdict.code, 'INDETERMINATE');
  assert.equal(verdict.reason, 'strict');
});

// ---- Activation -----------------------------------------------------------------------------------------------------------------

test('le code d\'activation est extrait d\'une saisie ou d\'un QR (fragment), jamais d\'autre chose', () => {
  assert.equal(extractActivationCode('abcd-efgh-jkmn'), 'ABCDEFGHJKMN');
  assert.equal(extractActivationCode('https://civipass.example/controle/#a=ABCD-EFGH-JKMN'), 'ABCDEFGHJKMN');
  assert.equal(extractActivationCode('Oi1L-0000-UUUU'), '011100 00VVVV'.replace(' ', ''), 'tolérance O/0, I/L/1, U/V');
  for (const bad of ['', 'court', 'ABCD-EFGH-JKM!', 'A'.repeat(13), null, undefined, 42, 'x'.repeat(3000)]) assert.equal(extractActivationCode(bad), null);
  assert.equal(detectPlatform('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0)'), 'IOS');
  assert.equal(detectPlatform('Mozilla/5.0 (Linux; Android 14)'), 'ANDROID');
  assert.equal(detectPlatform(''), 'OTHER');
});

test('activation : enregistrement en attente, code d\'appariement conservé, aucune donnée', async () => {
  const store = new MemoryStore();
  const api = fakeApi({ register: { kind: 'http', status: 201, ok: true, body: { secret: SECRET, pairingCode: '482913', status: 'PENDING', approvalDeadline: NOW + 3600 }, retryAfter: 0 } });
  const result = await activate({ api, store, code: 'ABCD-EFGH-JKMN', label: 'Barrage Nord', platform: 'ANDROID' });

  assert.deepEqual(result, { state: 'pending', pairingCode: '482913', deadline: NOW + 3600 });
  assert.equal((await store.getCredential()).status, 'PENDING');
  assert.equal((await store.getRecords()).size, 0);
});

test('activation refusée, limitée, invalide ou hors réseau', async () => {
  const run = async (register, code = 'ABCD-EFGH-JKMN') => activate({ api: fakeApi({ register }), store: new MemoryStore(), code, label: 'X', platform: 'IOS' });
  assert.equal((await run({ kind: 'http', status: 403, body: {} })).state, 'refused');
  assert.deepEqual(await run({ kind: 'http', status: 429, body: {}, retryAfter: 30 }), { state: 'throttled', retryAfter: 30 });
  assert.equal((await run({ kind: 'http', status: 422, body: { message: 'Indiquez un nom' } })).state, 'invalid');
  assert.equal((await run({ kind: 'network' })).state, 'network');
  assert.equal((await run({ kind: 'http', status: 500, body: null })).state, 'unavailable');
  assert.equal((await run({}, 'pas un code')).state, 'invalid');
});

test('changement de commune : le profil précédent est purgé et l\'ancien secret est présenté au serveur', async () => {
  const { store } = await activeStore({ entries: [await permitLine(TOKEN)] });
  let sent = null;
  const api = { register: async (payload) => { sent = payload; return { kind: 'http', status: 201, ok: true, body: { secret: 'N'.repeat(43), pairingCode: '111222', status: 'PENDING', approvalDeadline: NOW + 3600 }, retryAfter: 0 }; } };
  await activate({ api, store, code: 'ABCD-EFGH-JKMN', label: 'Nouvelle commune', platform: 'ANDROID' });

  assert.equal(sent.previousSecret, SECRET, 'l\'ancien secret permet de désactiver l\'ancien enregistrement');
  assert.equal((await store.getRecords()).size, 0, 'aucune donnée de l\'ancienne commune ne subsiste');
  assert.equal(await store.getProfile(), null);
  assert.equal((await store.getCredential()).secret, 'N'.repeat(43));
});

test('état de l\'appareil : approbation, désactivation (purge), secret inconnu', async () => {
  const { store } = await activeStore({ entries: [await permitLine(TOKEN)] });
  const clock = () => NOW;
  const api = (response) => ({ device: async () => response });

  assert.equal((await refreshStatus({ api: api({ kind: 'http', status: 200, ok: true, body: { status: 'ACTIVE', expiresAt: NOW + 3600 } }), store, nowSeconds: clock })).state, 'active');
  assert.equal((await refreshStatus({ api: api({ kind: 'network' }), store, nowSeconds: clock })).state, 'network');
  assert.equal((await refreshStatus({ api: api({ kind: 'http', status: 200, ok: true, body: { status: 'DEACTIVATED' } }), store, nowSeconds: clock })).state, 'deactivated');
  assert.equal((await store.getRecords()).size, 0, 'désactivé : données supprimées');
  assert.equal((await refreshStatus({ api: api({ kind: 'http', status: 401, ok: false, body: {} }), store, nowSeconds: clock })).state, 'unauthorized');
  assert.equal(await store.getCredential(), null);
});
