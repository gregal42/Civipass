// Intégrité de la dépendance embarquée (DT-057) : le fichier vendorisé doit rester identique à l'original audité.
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const sha256 = (path) => createHash('sha256').update(readFileSync(new URL(path, import.meta.url))).digest('hex');

test('jsQR 1.4.0 embarqué : empreinte conforme à l\'audit', () => {
  assert.equal(sha256('../../assets/vendor/jsqr/jsQR.js'), 'bc40c8a15196236b2314db0856f72ca0b49980cd5413b8c852a7349f5fee0859');
});

test('la licence Apache-2.0 est conservée', () => {
  assert.equal(sha256('../../assets/vendor/jsqr/LICENSE'), 'c6596eb7be8581c18be736c846fb9173b69eccf6ef94c5135893ec56bd92ba08');
});

test('jsQR n\'utilise ni eval ni new Function ni accès réseau', () => {
  const source = readFileSync(new URL('../../assets/vendor/jsqr/jsQR.js', import.meta.url), 'utf8');
  assert.doesNotMatch(source, /\beval\s*\(/);
  assert.doesNotMatch(source, /new\s+Function\s*\(/);
  assert.doesNotMatch(source, /\bfetch\s*\(|XMLHttpRequest|WebSocket|importScripts/);
});
