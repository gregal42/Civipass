// Synchronisation et purge (LOT 09.4/09.5, DT-056). Principes : ATOMICITÉ (le jeu courant n'est remplacé qu'après vérification complète de la réponse : nombre, condensat
// racine, condensats de compartiments, ordre, forme de chaque ligne) ; une interruption, une réponse tronquée, altérée ou rejouée laisse l'ancien jeu intact (son âge continue
// de croître). Une réponse différentielle ou « inchangée » qui ne se vérifie pas déclenche UNE demande d'instantané complet. Aucun état partiel n'est jamais écrit.
import { applyResponse, bucketDigests, envelopeProblem, parseLine } from './dataset.js';
import { keepTombstones, planPurge } from './purge.js';
import { profileFrom } from './profile.js';

/** Applique les règles de suppression locales (à l'ouverture, avant et après chaque synchronisation). */
export async function enforcePurge({ store, now, deviceState = null }) {
  const profile = await store.getProfile();
  const plan = planPurge({ now, profile, deviceState });
  if (plan === 'all') {
    await store.wipeData();
  } else if (plan === 'identifying') {
    await store.replaceRecords(keepTombstones(await store.getRecords(), parseLine));
  }

  return plan;
}

/**
 * @param {{api: object, store: object, nowSeconds: () => number}} deps
 * @returns {Promise<{state: string, [k: string]: any}>}
 *   états : unactivated | pending | deactivated | expired | unauthorized | network | throttled | server-error | integrity | refused | synced
 */
export async function runSync({ api, store, nowSeconds }) {
  const credential = await store.getCredential();
  if (credential === null) return { state: 'unactivated' };
  await enforcePurge({ store, now: nowSeconds(), deviceState: credential.status });

  const current = await store.getRecords();
  const profile = await store.getProfile();
  const buckets = profile === null ? null : await bucketDigests(current);

  let result = await attempt({ api, store, credential, current, buckets, nowSeconds });
  if (result.state === 'integrity' && buckets !== null) {
    result = await attempt({ api, store, credential, current, buckets: null, nowSeconds }); // repli : instantané complet
    result.retriedFull = true;
  }
  if (result.state === 'synced') await enforcePurge({ store, now: nowSeconds(), deviceState: 'ACTIVE' });

  return result;
}

async function attempt({ api, store, credential, current, buckets, nowSeconds }) {
  const response = await api.sync(credential.secret, buckets);
  if (response.kind === 'network') return { state: 'network' };
  if (response.status === 401) {
    await store.wipeData({ withCredential: true });
    return { state: 'unauthorized' };
  }
  if (response.status === 403) {
    const status = response.body?.status;
    if (status === 'PENDING') {
      await store.setCredential({ ...credential, status: 'PENDING' });
      return { state: 'pending' };
    }
    if (status === 'DEACTIVATED' || status === 'EXPIRED') {
      await store.wipeData();
      await store.setCredential({ ...credential, status });
      return { state: status === 'DEACTIVATED' ? 'deactivated' : 'expired' };
    }
    return { state: 'refused' };
  }
  if (response.status === 429) return { state: 'throttled', retryAfter: response.retryAfter };
  if (!response.ok || response.body === null) return { state: response.ok ? 'integrity' : 'server-error', reason: 'body' };

  const body = response.body;
  const problem = envelopeProblem(body, nowSeconds());
  if (problem !== null) return { state: 'integrity', reason: problem };
  const applied = await applyResponse(body, current);
  if (!applied.ok) return { state: 'integrity', reason: applied.reason };

  const profile = profileFrom(body, nowSeconds());
  await store.saveSync(profile, applied.lines); // atomique : profil et lignes ensemble
  if (credential.status !== 'ACTIVE') await store.setCredential({ ...credential, status: 'ACTIVE' });

  return { state: 'synced', mode: body.mode, count: body.n, clockBad: profile.clockBadAtSync };
}
