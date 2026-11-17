// Activation d'un appareil (LOT 09.2/09.4, DM-131) : code → enregistrement `PENDING` → approbation par l'administrateur (code d'appariement) → synchronisation.
// Un appareil n'a qu'UN profil communal : activer avec une autre commune purge le profil précédent (confirmation par l'appelant) et présente l'ancien secret au serveur,
// qui désactive l'ancien enregistrement (« fin d'utilisation »).
import { enforcePurge } from './sync.js';

/** Extrait un code d'activation de ce qui est saisi ou scanné : le code seul ou une URL dont le FRAGMENT est `#a=CODE`. Entrée non fiable : null si invalide. */
export function extractActivationCode(input) {
  if (typeof input !== 'string' || input.length > 2048) return null;
  let candidate = input.trim();
  const hashIndex = candidate.indexOf('#a=');
  if (hashIndex !== -1) candidate = candidate.slice(hashIndex + 3);
  const normalized = candidate.toUpperCase().replace(/[\s-]+/g, '').replace(/O/g, '0').replace(/[IL]/g, '1').replace(/U/g, 'V');

  return /^[0-9A-HJKMNP-TV-Z]{12}$/.test(normalized) ? normalized : null;
}

export function detectPlatform(userAgent = '') {
  if (/iPhone|iPad|iPod/i.test(userAgent)) return 'IOS';
  if (/Android/i.test(userAgent)) return 'ANDROID';

  return 'OTHER';
}

/**
 * @returns {Promise<{state: 'pending'|'refused'|'invalid'|'throttled'|'network'|'unavailable', [k: string]: any}>}
 */
export async function activate({ api, store, code, label, platform }) {
  const normalized = extractActivationCode(code);
  if (normalized === null) return { state: 'invalid', message: 'Ce code n’a pas la forme attendue (12 caractères).' };
  const previous = await store.getCredential();
  const response = await api.register({ code: normalized, label, platform, previousSecret: previous?.secret ?? null });
  if (response.kind === 'network') return { state: 'network' };
  if (response.status === 429) return { state: 'throttled', retryAfter: response.retryAfter };
  if (response.status === 403) return { state: 'refused' };
  if (response.status === 422) return { state: 'invalid', message: response.body?.message ?? 'Informations invalides.' };
  if (response.status !== 201 || typeof response.body?.secret !== 'string') return { state: 'unavailable' };

  await store.wipeData({ withCredential: true }); // un seul profil : l'ancien est purgé
  await store.setCredential({ secret: response.body.secret, label, status: 'PENDING', pairingCode: response.body.pairingCode, deadline: response.body.approvalDeadline });

  return { state: 'pending', pairingCode: response.body.pairingCode, deadline: response.body.approvalDeadline };
}

/** Interroge l'état de l'appareil (sans donnée personnelle) et met à jour le profil local en conséquence. */
export async function refreshStatus({ api, store, nowSeconds }) {
  const credential = await store.getCredential();
  if (credential === null) return { state: 'unactivated' };
  const response = await api.device(credential.secret);
  if (response.kind === 'network') return { state: 'network' };
  if (response.status === 401) {
    await store.wipeData({ withCredential: true });
    return { state: 'unauthorized' };
  }
  const status = response.body?.status;
  if (!response.ok || typeof status !== 'string') return { state: 'unavailable' };
  await store.setCredential({ ...credential, status, pairingCode: response.body.pairingCode ?? null, deadline: response.body.approvalDeadline ?? null });
  if (status === 'DEACTIVATED' || status === 'EXPIRED') await enforcePurge({ store, now: nowSeconds(), deviceState: status });

  return { state: status.toLowerCase(), body: response.body };
}
