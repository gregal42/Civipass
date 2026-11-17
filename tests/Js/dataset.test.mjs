// Jeu de données (LOT 09.3/09.4) : vecteurs COMMUNS avec le serveur, intégrité, ordre, compartiments, application atomique.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { applyResponse, bucketDigests, bucketOf, envelopeProblem, parseLine, rootOf } from '../../assets/controle/dataset.js';
import { sha256Hex } from '../../assets/controle/crypto.js';
import { NOW, dataset, permitLine, tombstoneLine } from './helpers.mjs';

const vectors = JSON.parse(readFileSync(new URL('./fixtures/dataset-vectors.json', import.meta.url), 'utf8'));
const mapOf = (lines) => new Map(lines.map((line) => [JSON.parse(line).k, line]));

test('vecteurs communs : l\'empreinte du jeton, le condensat racine et les 256 compartiments sont identiques à ceux du serveur', async () => {
  for (const [i, token] of vectors.tokens.entries()) assert.equal(await sha256Hex(token), vectors.keys[i]);
  const byKey = mapOf(vectors.lines);
  assert.equal(await rootOf(byKey), vectors.root);
  assert.deepEqual(await bucketDigests(byKey), vectors.buckets);
  assert.equal(await rootOf(new Map()), vectors.emptyRoot);
});

test('les lignes des vecteurs (accents, guillemets, barres) sont acceptées telles quelles', () => {
  for (const line of vectors.lines) assert.notEqual(parseLine(line), null, line);
});

test('compartiment d\'une clé : deux premiers caractères hexadécimaux', () => {
  assert.equal(bucketOf('ab' + '0'.repeat(62)), 171);
  assert.equal(bucketOf('0'.repeat(64)), 0);
  assert.equal(bucketOf('f'.repeat(64)), 255);
});

test('parseLine refuse toute ligne malformée', () => {
  const good = JSON.parse(vectors.lines[0]);
  const bad = [
    'pas du json', '[]', 'null', '{}', JSON.stringify({ ...good, k: 'xyz' }), JSON.stringify({ ...good, t: 'Z' }),
    JSON.stringify({ k: good.k, t: 'R', extra: 1 }), JSON.stringify({ ...good, p: 'AB' }), JSON.stringify({ ...good, z: [{ l: 'x', s: 'Q', w: [] }] }),
    JSON.stringify({ ...good, z: [{ l: 'x', s: 'V', w: [[5, 3]] }] }), JSON.stringify({ ...good, f: 3 }), 'a'.repeat(70000),
  ];
  for (const line of bad) assert.equal(parseLine(line), null, line.slice(0, 40));
  assert.equal(parseLine(42), null);
});

test('réponse complète valide : adoptée', async () => {
  const ds = await dataset([await permitLine('a'.repeat(32)), await tombstoneLine('b'.repeat(32), 'R')]);
  const result = await applyResponse({ ...ds.envelope, mode: 'full', lines: ds.lines }, new Map());
  assert.equal(result.ok, true);
  assert.equal(result.lines.size, 2);
});

test('réponse incomplète, tronquée, réordonnée, altérée ou au mauvais nombre : refusée, jeu courant intact', async () => {
  const ds = await dataset([await permitLine('a'.repeat(32)), await permitLine('c'.repeat(32), { last: 'DURAND' }), await tombstoneLine('b'.repeat(32), 'R')]);
  const base = { ...ds.envelope, mode: 'full' };
  const current = new Map([['x', 'y']]);
  const cases = {
    tronqué: { ...base, lines: ds.lines.slice(0, 2) },
    'nombre erroné': { ...base, lines: ds.lines, n: 5 },
    réordonné: { ...base, lines: [...ds.lines].reverse() },
    'doublon': { ...base, lines: [ds.lines[0], ds.lines[0], ds.lines[1]] },
    'ligne altérée': { ...base, lines: ds.lines.map((l, i) => (i === 0 ? l.replace('MARTIN', 'MARTIM').replace('DURAND', 'DURANT') : l)) },
    'condensat racine faux': { ...base, lines: ds.lines, root: '0'.repeat(64) },
    'compartiments faux': { ...base, lines: ds.lines, bk: ds.envelope.bk.map(() => '00000000') },
    'ligne illisible': { ...base, lines: [...ds.lines.slice(0, 2), '{pas du json'] },
    'lignes absentes': { ...base, lines: undefined },
    'mode inconnu': { ...base, mode: 'patch', lines: ds.lines },
  };
  for (const [name, response] of Object.entries(cases)) {
    const result = await applyResponse(response, current);
    assert.equal(result.ok, false, name);
  }
  assert.deepEqual([...current.entries()], [['x', 'y']], 'le jeu courant n\'est jamais modifié');
});

test('différentiel : seuls les compartiments modifiés sont remplacés et le condensat racine est reconstruit', async () => {
  const first = await dataset([await permitLine('a'.repeat(32)), await permitLine('c'.repeat(32), { last: 'DURAND' })]);
  const second = await dataset([await permitLine('a'.repeat(32)), await tombstoneLine('c'.repeat(32), 'R'), await permitLine('d'.repeat(32), { last: 'LEROY' })]);
  const changed = {};
  for (let i = 0; i < 256; i++) {
    if (first.envelope.bk[i] !== second.envelope.bk[i]) changed[String(i)] = [...second.byKey.keys()].sort().filter((k) => bucketOf(k) === i).map((k) => second.byKey.get(k));
  }
  const result = await applyResponse({ ...second.envelope, mode: 'delta', chg: changed }, first.byKey);
  assert.equal(result.ok, true);
  assert.deepEqual(await rootOf(result.lines), second.envelope.root);

  // un différentiel qui ne reconstruit pas le bon condensat est refusé
  const wrong = await applyResponse({ ...second.envelope, mode: 'delta', chg: {} }, first.byKey);
  assert.deepEqual(wrong, { ok: false, reason: 'count' });
});

test('« inchangé » n\'est accepté que si le condensat du jeu courant correspond', async () => {
  const ds = await dataset([await permitLine('a'.repeat(32))]);
  assert.equal((await applyResponse({ ...ds.envelope, mode: 'unchanged' }, ds.byKey)).ok, true);
  const other = await dataset([await permitLine('b'.repeat(32))]);
  assert.equal((await applyResponse({ ...ds.envelope, mode: 'unchanged' }, other.byKey)).ok, false);
  assert.equal((await applyResponse({ ...ds.envelope, mode: 'unchanged' }, new Map())).ok, false);
});

test('une ligne placée dans le mauvais compartiment d\'un différentiel est refusée', async () => {
  const ds = await dataset([await permitLine('a'.repeat(32))]);
  const key = [...ds.byKey.keys()][0];
  const wrongBucket = String((bucketOf(key) + 1) % 256);
  const result = await applyResponse({ ...ds.envelope, mode: 'delta', chg: { [wrongBucket]: [ds.lines[0]] } }, new Map());
  assert.equal(result.ok, false);
});

test('enveloppe : formes, horizon cohérent, instant de génération dans le futur', async () => {
  const ds = await dataset([]);
  assert.equal(envelopeProblem(ds.envelope, NOW), null);
  assert.equal(envelopeProblem(null, NOW), 'envelope');
  assert.equal(envelopeProblem({ ...ds.envelope, v: 2 }, NOW), 'schema-version');
  assert.equal(envelopeProblem({ ...ds.envelope, hor: ds.envelope.gen }, NOW), 'horizon');
  assert.equal(envelopeProblem({ ...ds.envelope, gen: NOW + 3600 }, NOW), 'generated-in-the-future');
  assert.equal(envelopeProblem({ ...ds.envelope, root: 'zz' }, NOW), 'envelope');
  assert.equal(envelopeProblem({ ...ds.envelope, bk: ['x'] }, NOW), 'envelope');
  assert.equal(envelopeProblem({ ...ds.envelope, tr: { w: 90000, h: 90000, m: 'STANDARD' } }, NOW), 'envelope', 'aucun seuil dur au-delà de 24 h');
  assert.equal(envelopeProblem({ ...ds.envelope, tr: { w: 2 * 3600, h: 3600, m: 'STANDARD' } }, NOW), 'envelope', 'avertissement ≤ seuil dur');
  assert.equal(envelopeProblem({ ...ds.envelope, tr: { w: 3600, h: 7200, m: 'LAX' } }, NOW), 'envelope');
});
