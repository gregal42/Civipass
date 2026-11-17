// Verdict hors connexion (LOT 09.4, DM-130) : seuils de 6 h et 12 h, modes STANDARD et STRICT, jeton inconnu, révoqué, remplacé, expiré, suspendu, horloge, appareil.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { computeVerdict, describe, positiveTextsAreHonest } from '../../assets/controle/verdict.js';
import { profileFrom } from '../../assets/controle/profile.js';
import { H, NOW, dataset } from './helpers.mjs';

const WARN = 6 * H;
const HARD = 12 * H;

async function profile(overrides = {}) {
  const ds = await dataset([], overrides);
  return { ...profileFrom(ds.envelope, overrides.now ?? NOW), ...overrides.profile };
}

const zone = (s, w = [], l = 'Centre-ville') => ({ l, s, w });
const permit = (zones) => ({ kind: 'P', record: { k: 'k', t: 'P', r: 'LP-2026-0001', f: 'Camille', l: 'MARTIN', p: ['AB-123-CD'], z: zones } });
const validZone = (now = NOW) => zone('V', [[now - H, now + 20 * H]]);

test('seuils du pilote : avertissement 6 h, seuil dur 12 h', async () => {
  const p = await profile();
  const at = (offset, lookup = permit([validZone(NOW + offset)])) => computeVerdict({ now: NOW + offset, profile: p, lookup });

  assert.equal(at(0).code, 'VALID');
  assert.equal(at(WARN - 1).code, 'VALID', 'juste avant 6 h : verdict qualifié ordinaire');
  assert.equal(at(WARN).code, 'VALID_OLD', 'à 6 h : données vieillissantes, avertissement renforcé');
  assert.equal(at(HARD).code, 'VALID_OLD', 'à 12 h pile : encore positif dégradé');
  const stale = at(HARD + 1);
  assert.equal(stale.code, 'INDETERMINATE');
  assert.equal(stale.reason, 'stale', 'au-delà de 12 h : indéterminé, jamais un refus');
});

test('au-delà du seuil dur, rien de positif ni de négatif « fraîchement établi » : seules les empreintes révoquées restent affichées', async () => {
  const p = await profile();
  const late = NOW + HARD + 60;
  assert.equal(computeVerdict({ now: late, profile: p, lookup: permit([validZone(late)]) }).code, 'INDETERMINATE');
  assert.equal(computeVerdict({ now: late, profile: p, lookup: null }).reason, 'stale');
  const revoked = computeVerdict({ now: late, profile: p, lookup: { kind: 'R', record: { k: 'k', t: 'R' } } });
  assert.equal(revoked.code, 'REVOKED');
  assert.equal(revoked.stale, true);
  assert.match(describe(revoked).title, /information ancienne, non actualisée/);
});

test('mode STANDARD : un laissez-passer valide est « valide selon la dernière synchronisation », jamais vert plein', async () => {
  const p = await profile();
  const v = computeVerdict({ now: NOW, profile: p, lookup: permit([validZone()]) });
  const d = describe(v);

  assert.equal(v.code, 'VALID');
  assert.equal(d.title, 'Valide selon la dernière synchronisation');
  assert.notEqual(d.tone, 'green');
  assert.equal(v.qualifier.ageSeconds, 0);
  assert.equal(v.person.last, 'MARTIN');
});

test('aucun texte positif n\'emploie « vérifié », « confirmé », « à jour » ou « temps réel »', () => {
  assert.equal(positiveTextsAreHonest(), true);
});

test('mode STRICT : jamais de verdict positif ; l\'identité reste affichée à titre d\'information ; les cas négatifs connus restent', async () => {
  const p = await profile({ mode: 'STRICT' });
  const positive = computeVerdict({ now: NOW, profile: p, lookup: permit([validZone()]) });
  assert.equal(positive.code, 'INDETERMINATE');
  assert.equal(positive.reason, 'strict');
  assert.equal(positive.person.last, 'MARTIN');
  assert.match(describe(positive).text, /confirmation en ligne requise/);

  assert.equal(computeVerdict({ now: NOW, profile: p, lookup: permit([zone('E')]) }).code, 'NOT_VALID');
  assert.equal(computeVerdict({ now: NOW, profile: p, lookup: { kind: 'R', record: { t: 'R' } } }).code, 'REVOKED');
});

test('jeton inconnu ou hors périmètre : indéterminé, jamais un refus', async () => {
  const v = computeVerdict({ now: NOW, profile: await profile(), lookup: null });
  assert.equal(v.code, 'INDETERMINATE');
  assert.equal(v.reason, 'unknown');
  assert.match(describe(v).text, /Ce n’est pas un refus d’autorisation/);
});

test('révoqué et remplacé connus à la synchronisation', async () => {
  const p = await profile();
  assert.equal(computeVerdict({ now: NOW, profile: p, lookup: { kind: 'R', record: {} } }).code, 'REVOKED');
  assert.equal(computeVerdict({ now: NOW, profile: p, lookup: { kind: 'X', record: {} } }).code, 'REPLACED');
});

test('zones : active, à venir, suspendue, expirée, hors période, futures au-delà de l\'horizon', async () => {
  const p = await profile();
  const run = (zones) => computeVerdict({ now: NOW, profile: p, lookup: permit(zones) });

  assert.equal(run([validZone()]).code, 'VALID');
  const partial = run([validZone(), zone('S', [], 'Nord')]);
  assert.equal(partial.code, 'VALID');
  assert.equal(partial.partial, true, 'seules certaines zones sont autorisées');
  assert.match(describe(partial).text, /Certaines zones seulement/);

  assert.equal(run([zone('V', [[NOW + 2 * H, NOW + 5 * H]])]).cause, 'UPCOMING');
  assert.equal(run([zone('F')]).cause, 'UPCOMING');
  assert.equal(run([zone('V', [[NOW - 5 * H, NOW - H]])]).cause, 'UPCOMING', 'hors période, plus aucune fenêtre dans l\'horizon : aucun droit actif');
  assert.equal(run([zone('S')]).cause, 'SUSPENDED');
  assert.equal(run([zone('E'), zone('E', [], 'B')]).cause, 'EXPIRED');
  assert.equal(run([]).cause, 'NONE');
  assert.equal(run([zone('V', [[NOW - 5 * H, NOW - H]]), zone('S', [], 'B')]).code, 'NOT_VALID');
});

test('une zone dont la fenêtre se termine pile à l\'instant n\'est plus active (fin exclusive)', async () => {
  const p = await profile();
  assert.equal(computeVerdict({ now: NOW, profile: p, lookup: permit([zone('V', [[NOW - H, NOW]])]) }).code, 'NOT_VALID');
  assert.equal(computeVerdict({ now: NOW, profile: p, lookup: permit([zone('V', [[NOW, NOW + H]])]) }).code, 'VALID');
});

test('événement terminé (planifié ou clos) : message neutre sans donnée personnelle', async () => {
  const closed = computeVerdict({ now: NOW, profile: await profile({ eventClosed: true, profile: { eventClosed: true } }), lookup: permit([validZone()]) });
  assert.equal(closed.code, 'EVENT_ENDED');
  assert.equal(closed.person, undefined);
  const ended = computeVerdict({ now: NOW + 1000, profile: await profile({ profile: { eventEnd: NOW + 500 } }), lookup: permit([validZone(NOW + 1000)]) });
  assert.equal(ended.code, 'EVENT_ENDED');
});

test('aucun jeu valide, appareil en attente, désactivé ou expiré : indéterminé', async () => {
  const p = await profile();
  const lookup = permit([validZone()]);
  assert.equal(computeVerdict({ now: NOW, profile: null, lookup }).reason, 'no-dataset');
  assert.equal(computeVerdict({ now: NOW, profile: p, lookup, deviceState: 'PENDING' }).reason, 'device-pending');
  assert.equal(computeVerdict({ now: NOW, profile: p, lookup, deviceState: 'DEACTIVATED' }).reason, 'device-deactivated');
  assert.equal(computeVerdict({ now: NOW, profile: p, lookup, deviceState: 'EXPIRED' }).reason, 'device-expired');
  const expired = await profile({ deviceExpires: NOW + 100 });
  assert.equal(computeVerdict({ now: NOW + 100, profile: expired, lookup }).reason, 'device-expired');
});

test('horloge incohérente : indéterminé', async () => {
  const lookup = permit([validZone()]);
  const p = await profile({ profile: { lastSeen: NOW + 2 * H } });
  assert.equal(computeVerdict({ now: NOW + H, profile: p, lookup }).reason, 'clock-backwards');
  const before = await profile({ profile: { deviceSyncedAt: NOW + 3 * H, lastSeen: NOW } });
  assert.equal(computeVerdict({ now: NOW + H, profile: before, lookup }).reason, 'clock-before-sync');
  const bad = await profile({ profile: { clockBadAtSync: true } });
  assert.equal(computeVerdict({ now: NOW, profile: bad, lookup }).reason, 'clock-offset');
  assert.match(describe({ code: 'INDETERMINATE', reason: 'clock-offset' }).text, /Réglez l’heure/);
});

test('l\'horizon du jeu borne la confiance même sous le seuil dur', async () => {
  const p = await profile({ horizon: NOW + H });
  const v = computeVerdict({ now: NOW + 2 * H, profile: p, lookup: permit([validZone(NOW + 2 * H)]) });
  assert.equal(v.code, 'INDETERMINATE');
  assert.equal(v.reason, 'stale');
});

test('seuil dur raccourci par la commune (4 h, avertissement 2 h)', async () => {
  const p = await profile({ warn: 2 * H, hard: 4 * H });
  const run = (offset) => computeVerdict({ now: NOW + offset, profile: p, lookup: permit([validZone(NOW + offset)]) });
  assert.equal(run(H).code, 'VALID');
  assert.equal(run(2 * H).code, 'VALID_OLD');
  assert.equal(run(4 * H + 1).code, 'INDETERMINATE');
});

test('tout verdict est qualifié par l\'âge et l\'instant de synchronisation', async () => {
  const p = await profile();
  const v = computeVerdict({ now: NOW + 22000, profile: p, lookup: permit([validZone(NOW + 22000)]) });
  assert.equal(v.qualifier.ageSeconds, 22000);
  assert.equal(v.qualifier.syncedAt, NOW);
  assert.equal(v.qualifier.band, 'old');
});
