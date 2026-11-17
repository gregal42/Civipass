// Tests de la lecture d'un QR (LOT 09.1) — node:test, aucune dépendance. Exécution : `node --test tests/Js/` (ou `bin/qa js`).
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { extractToken, onlineUrl } from '../../assets/controle/scan.js';

const BASE = 'https://civipass.example';
const TOKEN = 'abcdefghijklmnopqrstuvwxyz234567'.slice(0, 32);

test('accepte une URL de vérification de l\'origine configurée', () => {
  assert.equal(extractToken(`${BASE}/v/${TOKEN}`, BASE), TOKEN);
  assert.equal(extractToken(`  ${BASE}/v/${TOKEN}/  `, BASE), TOKEN);
});

test('normalise la casse (certains lecteurs passent le texte en majuscules)', () => {
  assert.equal(extractToken(`${BASE}/v/${TOKEN.toUpperCase()}`, BASE), TOKEN);
});

test('refuse une autre origine, un autre schéma, des identifiants dans l\'URL', () => {
  assert.equal(extractToken(`https://evil.example/v/${TOKEN}`, BASE), null);
  assert.equal(extractToken(`http://civipass.example/v/${TOKEN}`, BASE), null);
  assert.equal(extractToken(`https://user:pw@civipass.example/v/${TOKEN}`, BASE), null);
  assert.equal(extractToken(`https://civipass.example.evil.example/v/${TOKEN}`, BASE), null);
});

test('refuse tout ce qui n\'a pas la forme exacte du chemin', () => {
  for (const bad of [
    TOKEN, // jeton nu : le QR contient une URL
    `${BASE}/${TOKEN}`,
    `${BASE}/v/${TOKEN}x`,
    `${BASE}/v/${TOKEN.slice(1)}`,
    `${BASE}/v/${TOKEN}/extra`,
    `${BASE}/x/v/${TOKEN}`,
    `${BASE}/v/${'1'.repeat(32)}`, // hors alphabet base32
    '',
    '   ',
    'javascript:alert(1)',
    `${BASE}/v/${TOKEN}?${'a'.repeat(3000)}`,
  ]) {
    assert.equal(extractToken(bad, BASE), null, String(bad).slice(0, 40));
  }
});

test('ne lève jamais d\'exception sur une entrée non fiable', () => {
  for (const value of [null, undefined, 42, {}, [], '\u0000', 'http://', '://', 'https://[::1']) {
    assert.doesNotThrow(() => extractToken(value, BASE));
    assert.equal(extractToken(value, BASE), null);
  }
  assert.equal(extractToken(`${BASE}/v/${TOKEN}`, 'pas une url'), null);
  assert.equal(extractToken(`${BASE}/v/${TOKEN}`, null), null);
});

test('ignore paramètres et ancre : le jeton n\'est lu que dans le chemin', () => {
  assert.equal(extractToken(`${BASE}/v/${TOKEN}?a=b#c`, BASE), TOKEN);
});

test('construit l\'URL de contrôle en ligne sur l\'origine configurée seulement', () => {
  assert.equal(onlineUrl(TOKEN, `${BASE}/chemin/ignoré`), `${BASE}/v/${TOKEN}`);
});
