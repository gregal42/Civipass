// Contrôle hors connexion d'un jeton scanné (LOT 09.4) : empreinte → ligne locale → verdict. Le jeton n'est jamais conservé ni journalisé ; seule son empreinte est cherchée.
// Aucun journal de scan (DM-127, DM-132) : rien n'est écrit, hors la « dernière heure locale vue » (détection d'un recul d'horloge).
import { parseLine } from './dataset.js';
import { sha256Hex } from './crypto.js';
import { touch } from './clock.js';
import { computeVerdict } from './verdict.js';
import { enforcePurge } from './sync.js';

export async function lookupToken(store, token) {
  const line = await store.getRecord(await sha256Hex(token));
  if (line === null) return null;
  const record = parseLine(line);

  return record === null ? null : { kind: record.t, record };
}

/**
 * @param {{store: object, token: string, now: number, sessionClockProblem?: string|null}} input
 */
export async function offlineVerdict({ store, token, now, sessionClockProblem = null }) {
  const credential = await store.getCredential();
  const deviceState = credential?.status ?? null;
  await enforcePurge({ store, now, deviceState });
  const profile = await store.getProfile();
  if (sessionClockProblem !== null && profile !== null) return { code: 'INDETERMINATE', reason: sessionClockProblem };
  const lookup = profile === null ? null : await lookupToken(store, token);
  const verdict = computeVerdict({ now, profile, lookup, deviceState });
  if (profile !== null) await store.setProfile(touch(profile, now)); // « dernière heure locale vue » : ne recule jamais

  return verdict;
}
