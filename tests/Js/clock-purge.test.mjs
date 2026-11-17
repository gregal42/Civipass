// Horloge et purge (LOT 09.4/09.5) : garanties et limites documentées, suppression locale selon le cycle de vie.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { clockProblem, offsetProblem, sessionDriftProblem, touch } from '../../assets/controle/clock.js';
import { parseLine } from '../../assets/controle/dataset.js';
import { keepTombstones, planPurge } from '../../assets/controle/purge.js';
import { H, NOW, activeStore, permitLine, tombstoneLine } from './helpers.mjs';
import { enforcePurge } from '../../assets/controle/sync.js';

const profile = { deviceSyncedAt: NOW, lastSeen: NOW, clockBadAtSync: false, hard: 12 * H, deviceExpiresAt: NOW + 72 * H, eventEnd: null, eventClosed: false };

test('décalage à la synchronisation : au-delà de 2 minutes, jeu inutilisable', () => {
  assert.equal(offsetProblem(NOW + 119, NOW), false);
  assert.equal(offsetProblem(NOW + 121, NOW), true);
  assert.equal(offsetProblem(NOW - 121, NOW), true);
});

test('recul d\'horloge détecté ; avance NON détectable seule (limite assumée)', () => {
  assert.equal(clockProblem(NOW + 100, profile), null);
  assert.equal(clockProblem(NOW - 119, profile), null, 'dans la tolérance');
  assert.equal(clockProblem(NOW - 200, profile), 'clock-backwards');
  assert.equal(clockProblem(NOW + 20 * H, profile), null, 'une avance d\'horloge n\'est pas détectée par ce seul signal');
  assert.equal(clockProblem(NOW, { ...profile, clockBadAtSync: true }), 'clock-offset');
});

test('« dernière heure vue » ne recule jamais', () => {
  assert.equal(touch({ lastSeen: 500 }, 400).lastSeen, 500);
  assert.equal(touch({ lastSeen: 500 }, 900).lastSeen, 900);
});

test('dérive de session : l\'horloge murale et l\'horloge monotone doivent avancer ensemble', () => {
  assert.equal(sessionDriftProblem({ wall: 1000, mono: 10 }, { wall: 1060, mono: 70 }), null);
  assert.equal(sessionDriftProblem({ wall: 1000, mono: 10 }, { wall: 1060 - 3600, mono: 70 }), 'clock-changed', 'horloge reculée pendant la session');
  assert.equal(sessionDriftProblem({ wall: 1000, mono: 10 }, { wall: 1060 + 7200, mono: 70 }), 'clock-changed', 'horloge avancée pendant la session');
});

test('plan de suppression : rien, identifiants seulement (> seuil dur), tout (appareil expiré, événement terminé, désactivé)', () => {
  assert.equal(planPurge({ now: NOW + H, profile }), 'none');
  assert.equal(planPurge({ now: NOW + 12 * H, profile }), 'none');
  assert.equal(planPurge({ now: NOW + 12 * H + 1, profile }), 'identifying');
  assert.equal(planPurge({ now: NOW + 72 * H, profile }), 'all');
  assert.equal(planPurge({ now: NOW + H, profile: { ...profile, eventClosed: true } }), 'all');
  assert.equal(planPurge({ now: NOW + 600, profile: { ...profile, eventEnd: NOW + 500 } }), 'all');
  assert.equal(planPurge({ now: NOW, profile, deviceState: 'DEACTIVATED' }), 'all');
  assert.equal(planPurge({ now: NOW, profile, deviceState: 'EXPIRED' }), 'all');
  assert.equal(planPurge({ now: NOW, profile: null }), 'none');
});

test('purge « identifiants » : noms, plaques et fenêtres supprimés, empreintes révoquées/remplacées conservées sans nom', async () => {
  const { store } = await activeStore({ entries: [await permitLine('a'.repeat(32)), await tombstoneLine('b'.repeat(32), 'R'), await tombstoneLine('c'.repeat(32), 'X')] });
  assert.equal((await store.getRecords()).size, 3);

  assert.equal(await enforcePurge({ store, now: NOW + 13 * H, deviceState: 'ACTIVE' }), 'identifying');
  const kept = await store.getRecords();
  assert.equal(kept.size, 2);
  for (const line of kept.values()) assert.ok(['R', 'X'].includes(parseLine(line).t));
  assert.equal(JSON.stringify([...kept.values()]).includes('MARTIN'), false, 'plus aucun nom sur l\'appareil');
  assert.notEqual(await store.getProfile(), null, 'le profil reste pour qualifier les verdicts anciens');
});

test('purge totale : appareil expiré, événement clos ou désactivé', async () => {
  for (const [now, state] of [[NOW + 80 * H, 'ACTIVE'], [NOW, 'DEACTIVATED'], [NOW, 'EXPIRED']]) {
    const { store } = await activeStore({ entries: [await permitLine('a'.repeat(32)), await tombstoneLine('b'.repeat(32), 'R')] });
    assert.equal(await enforcePurge({ store, now, deviceState: state }), 'all');
    assert.equal((await store.getRecords()).size, 0);
    assert.equal(await store.getProfile(), null);
    assert.notEqual(await store.getCredential(), null, 'le secret est conservé pour connaître l\'état de l\'appareil');
  }
  const { store } = await activeStore({ entries: [await permitLine('a'.repeat(32))], closed: true });
  assert.equal(await enforcePurge({ store, now: NOW, deviceState: 'ACTIVE' }), 'all');
});

test('keepTombstones ne garde que R et X', async () => {
  const entries = [await permitLine('a'.repeat(32)), await tombstoneLine('b'.repeat(32), 'R')];
  const kept = keepTombstones(new Map(entries.map((e) => [e.k, e.line])), parseLine);
  assert.equal(kept.size, 1);
});
