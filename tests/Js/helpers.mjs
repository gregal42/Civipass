// Aides de test du contrôle hors connexion : jeux de données bien formés, serveur factice (modes full / delta / unchanged), horloge.
import { BUCKETS, bucketDigests, bucketOf, rootOf } from '../../assets/controle/dataset.js';
import { sha256Hex } from '../../assets/controle/crypto.js';
import { MemoryStore } from '../../assets/controle/store.js';

export const NOW = 1_790_000_000; // instant de référence (secondes)
export const H = 3600;

export async function keyOf(token) {
  return sha256Hex(token);
}

/** Ligne de laissez-passer (clé en premier, comme le serveur). */
export async function permitLine(token, { first = 'Camille', last = 'MARTIN', plates = ['AB-123-CD'], reference = 'LP-2026-0001', zones } = {}) {
  const k = await keyOf(token);
  const z = zones ?? [{ l: 'Centre-ville', s: 'V', w: [[NOW - 2 * H, NOW + 30 * H]] }];

  return { k, line: JSON.stringify({ k, t: 'P', r: reference, f: first, l: last, p: plates, z }) };
}

export async function tombstoneLine(token, type) {
  const k = await keyOf(token);

  return { k, line: JSON.stringify({ k, t: type }) };
}

/** Enveloppe + lignes triées d'un jeu complet. */
export async function dataset(entries, { now = NOW, warn = 6 * H, hard = 12 * H, mode = 'STANDARD', deviceExpires = now + 72 * H, eventEnd = null, closed = false, horizon } = {}) {
  const byKey = new Map(entries.map((e) => [e.k, e.line]));
  const envelope = {
    v: 1,
    gen: now,
    hor: horizon ?? Math.floor((now + hard) / H) * H + H,
    ev: { ref: 'EVT-2026-000001', name: 'Incendie du massif', end: eventEnd, closed },
    mu: 'Mairie de Test',
    tz: 'Europe/Paris',
    tr: { w: warn, h: hard, m: mode },
    dev: { exp: deviceExpires },
    n: byKey.size,
    root: await rootOf(byKey),
    bk: await bucketDigests(byKey),
  };

  return { envelope, byKey, lines: [...byKey.keys()].sort().map((k) => byKey.get(k)) };
}

/** Serveur factice : sert `current` (jeu courant) selon le protocole du serveur réel. */
export function fakeApi(state) {
  const calls = [];
  return {
    calls,
    state,
    async ping() { return { kind: 'http', status: 200, ok: true, body: { t: NOW } }; },
    async register() { return state.register ?? { kind: 'network' }; },
    async device() { return state.device ?? { kind: 'network' }; },
    async sync(secret, buckets) {
      calls.push({ secret, buckets });
      if (state.syncResponse) return typeof state.syncResponse === 'function' ? state.syncResponse(buckets, calls.length) : state.syncResponse;
      const ds = state.dataset;
      const body = { ...ds.envelope };
      if (buckets === null || buckets === undefined) {
        body.mode = 'full';
        body.lines = ds.lines;
      } else {
        const changed = [];
        for (let i = 0; i < BUCKETS; i++) if (ds.envelope.bk[i] !== buckets[i]) changed.push(i);
        if (changed.length === 0) body.mode = 'unchanged';
        else {
          body.mode = 'delta';
          body.chg = {};
          for (const i of changed) body.chg[String(i)] = [...ds.byKey.keys()].sort().filter((k) => bucketOf(k) === i).map((k) => ds.byKey.get(k));
        }
      }
      return { kind: 'http', status: 200, ok: true, body, retryAfter: 0 };
    },
  };
}

export async function activeStore({ entries, ...options } = {}) {
  const store = new MemoryStore();
  const ds = await dataset(entries ?? [], options);
  const { profileFrom } = await import('../../assets/controle/profile.js');
  await store.setCredential({ secret: 'S'.repeat(43), label: 'Barrage Nord', status: 'ACTIVE' });
  await store.saveSync(profileFrom(ds.envelope, options.now ?? NOW), ds.byKey);

  return { store, ds };
}
