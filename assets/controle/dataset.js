// Jeu de données de contrôle (LOT 09.3/09.4) : validation, condensats et application ATOMIQUE d'une réponse de synchronisation. SPÉCIFICATION PARTAGÉE avec le serveur
// (`src/Control/Application/Dataset/DatasetCodec.php`) : mêmes calculs, vecteurs de test communs (tests/Js/fixtures/dataset-vectors.json).
//
// Une ligne = le JSON compact d'un enregistrement ; `k` = SHA-256 du jeton du QR (jamais le jeton). Lignes triées par `k`. Condensat racine = SHA-256 des lignes jointes par « \n ».
// 256 compartiments (deux premiers caractères hexadécimaux de `k`) ; condensat de compartiment = 8 premiers caractères hexadécimaux du SHA-256 de ses lignes jointes par « \n ».
// Rien n'est adopté tant que TOUT n'est pas vérifié : un jeu incomplet, tronqué, réordonné ou altéré est refusé et l'ancien jeu reste en place.
import { sha256Hex } from './crypto.js';

export const BUCKETS = 256;
export const SCHEMA_VERSION = 1;
const HEX64 = /^[0-9a-f]{64}$/;

export function bucketOf(key) {
  return parseInt(key.slice(0, 2), 16);
}

/** Lignes triées (par clé) d'une table clé → ligne. */
export function sortedLines(linesByKey) {
  return [...linesByKey.keys()].sort().map((key) => linesByKey.get(key));
}

export async function rootOf(linesByKey) {
  return sha256Hex(sortedLines(linesByKey).join('\n'));
}

/** 256 condensats de 8 caractères hexadécimaux. */
export async function bucketDigests(linesByKey) {
  const groups = Array.from({ length: BUCKETS }, () => []);
  for (const key of [...linesByKey.keys()].sort()) groups[bucketOf(key)].push(linesByKey.get(key));
  return Promise.all(groups.map(async (lines) => (await sha256Hex(lines.join('\n'))).slice(0, 8)));
}

/** Vérifie la forme d'UNE ligne ; renvoie l'enregistrement décodé ou null. Toute ligne malformée invalide le jeu entier. */
export function parseLine(line) {
  if (typeof line !== 'string' || line.length > 65536) return null;
  let record;
  try {
    record = JSON.parse(line);
  } catch {
    return null;
  }
  if (record === null || typeof record !== 'object' || Array.isArray(record)) return null;
  if (typeof record.k !== 'string' || !HEX64.test(record.k)) return null;
  if (record.t === 'R' || record.t === 'X') return Object.keys(record).length === 2 ? record : null;
  if (record.t !== 'P') return null;
  if (typeof record.r !== 'string' || typeof record.f !== 'string' || typeof record.l !== 'string') return null;
  if (!Array.isArray(record.p) || !record.p.every((p) => typeof p === 'string')) return null;
  if (!Array.isArray(record.z)) return null;
  for (const zone of record.z) {
    if (zone === null || typeof zone !== 'object' || typeof zone.l !== 'string' || !['V', 'S', 'E', 'F'].includes(zone.s) || !Array.isArray(zone.w)) return null;
    for (const w of zone.w) {
      if (!Array.isArray(w) || w.length !== 2 || !Number.isInteger(w[0]) || !Number.isInteger(w[1]) || w[1] <= w[0]) return null;
    }
  }

  return record;
}

/** Contrôle de l'enveloppe (métadonnées) : types, cohérence des instants. Renvoie une raison d'échec ou null. */
export function envelopeProblem(envelope, nowSeconds, toleranceSeconds = 120) {
  if (envelope === null || typeof envelope !== 'object') return 'envelope';
  if (envelope.v !== SCHEMA_VERSION) return 'schema-version';
  for (const field of ['gen', 'hor', 'n']) if (!Number.isInteger(envelope[field])) return 'envelope';
  if (typeof envelope.root !== 'string' || !HEX64.test(envelope.root)) return 'envelope';
  if (!Array.isArray(envelope.bk) || envelope.bk.length !== BUCKETS || !envelope.bk.every((d) => typeof d === 'string' && /^[0-9a-f]{8}$/.test(d))) return 'envelope';
  const { ev, tr, dev } = envelope;
  if (!ev || typeof ev.ref !== 'string' || typeof ev.name !== 'string' || typeof ev.closed !== 'boolean' || !(ev.end === null || Number.isInteger(ev.end))) return 'envelope';
  if (!tr || !Number.isInteger(tr.w) || !Number.isInteger(tr.h) || tr.w < 1 || tr.w > tr.h || tr.h > 86400 || !['STANDARD', 'STRICT'].includes(tr.m)) return 'envelope';
  if (!dev || !Number.isInteger(dev.exp) || typeof envelope.mu !== 'string' || typeof envelope.tz !== 'string') return 'envelope';
  if (envelope.hor <= envelope.gen) return 'horizon';
  if (envelope.gen > nowSeconds + toleranceSeconds) return 'generated-in-the-future';

  return null;
}

/**
 * Applique une réponse de synchronisation à la table courante SANS la modifier : renvoie le NOUVEAU jeu complet, ou une raison d'échec. `current` : Map clé → ligne.
 * @returns {Promise<{ok: true, lines: Map<string,string>} | {ok: false, reason: string}>}
 */
export async function applyResponse(response, current) {
  const mode = response?.mode;
  let next;
  if (mode === 'unchanged') {
    next = new Map(current);
  } else if (mode === 'full') {
    if (!Array.isArray(response.lines)) return { ok: false, reason: 'lines' };
    next = new Map();
    let previous = '';
    for (const line of response.lines) {
      const record = parseLine(line);
      if (record === null) return { ok: false, reason: 'corrupt-line' };
      if (record.k <= previous) return { ok: false, reason: 'order' };
      previous = record.k;
      next.set(record.k, line);
    }
  } else if (mode === 'delta') {
    if (response.chg === null || typeof response.chg !== 'object' || Array.isArray(response.chg)) return { ok: false, reason: 'lines' };
    next = new Map(current);
    for (const [bucket, lines] of Object.entries(response.chg)) {
      const index = Number(bucket);
      if (!Number.isInteger(index) || index < 0 || index >= BUCKETS || !Array.isArray(lines)) return { ok: false, reason: 'lines' };
      for (const key of [...next.keys()]) if (bucketOf(key) === index) next.delete(key);
      let previous = '';
      for (const line of lines) {
        const record = parseLine(line);
        if (record === null) return { ok: false, reason: 'corrupt-line' };
        if (bucketOf(record.k) !== index || record.k <= previous) return { ok: false, reason: 'order' };
        previous = record.k;
        next.set(record.k, line);
      }
    }
  } else {
    return { ok: false, reason: 'mode' };
  }
  if (next.size !== response.n) return { ok: false, reason: 'count' };
  if ((await rootOf(next)) !== response.root) return { ok: false, reason: 'root' };
  const digests = await bucketDigests(next);
  if (digests.some((digest, index) => digest !== response.bk[index])) return { ok: false, reason: 'bucket' };

  return { ok: true, lines: next };
}
