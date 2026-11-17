// PWA de contrôle (LOT 09) — point d'entrée. Activation par code, approbation par l'administrateur, synchronisation, scan, contrôle EN LIGNE (préféré) ou verdict HORS CONNEXION
// qualifié (jamais présenté comme une vérification en temps réel). Tout texte affiché passe par textContent (jamais innerHTML) : aucune donnée n'est interprétée comme du HTML.
// Aucun journal de scan ; aucun jeton conservé ; aucun chiffrement applicatif annoncé (voir store.js).
import { activate, detectPlatform, extractActivationCode, refreshStatus } from './activation.js';
import { createApi } from './api.js';
import { cameraSupported, startScan } from './camera.js';
import { sessionDriftProblem } from './clock.js';
import { IdbStore } from './idb-store.js';
import { extractToken, onlineUrl } from './scan.js';
import { MemoryStore } from './store.js';
import { enforcePurge, runSync } from './sync.js';
import { offlineVerdict } from './verification.js';
import { describe } from './verdict.js';

const root = document.getElementById('cc-app');
const verificationBase = root.dataset.verificationBase;
const api = createApi(root.dataset.apiBase);
const store = typeof indexedDB === 'undefined' ? new MemoryStore() : new IdbStore();
const SYNC_INTERVAL_MS = 15 * 60 * 1000;
const sessionStart = { wall: Date.now() / 1000, mono: performance.now() / 1000 };
let syncTimer = null;
let pollTimer = null;
let activeScan = null;
let view = '';

const nowSeconds = () => Math.floor(Date.now() / 1000);

function el(tag, className, text) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== undefined) node.textContent = text;
  return node;
}

function button(label, handler, className = 'cc-btn') {
  const node = el('button', className, label);
  node.type = 'button';
  node.addEventListener('click', handler);
  return node;
}

function header() {
  const head = el('div', 'cc-head');
  head.append(el('h1', 'cc-title', 'Contrôle Civipass'));
  return head;
}

function show(...nodes) {
  stopTimers();
  view = '';
  root.replaceChildren(header(), ...nodes);
}

function stopTimers() {
  if (pollTimer !== null) clearInterval(pollTimer);
  pollTimer = null;
  if (activeScan !== null) activeScan.stop();
  activeScan = null;
}

function notice(text, error = false) {
  return el('p', `cc-notice${error ? ' cc-notice--error' : ''}`, text);
}

function format(seconds, timezone) {
  try {
    return new Intl.DateTimeFormat('fr-FR', { timeZone: timezone || 'Europe/Paris', dateStyle: 'short', timeStyle: 'short' }).format(new Date(seconds * 1000));
  } catch {
    return new Date(seconds * 1000).toISOString();
  }
}

function age(seconds) {
  if (seconds < 90) return 'à l’instant';
  if (seconds < 5400) return `il y a ${Math.round(seconds / 60)} min`;
  return `il y a ${Math.round(seconds / 3600)} h`;
}

async function registerServiceWorker() {
  if (!('serviceWorker' in navigator)) return;
  try {
    await navigator.serviceWorker.register(root.dataset.sw, { scope: '/controle/' });
  } catch {
    // la PWA fonctionne sans Service Worker (coquille hors ligne indisponible)
  }
}

// ---- Démarrage --------------------------------------------------------------------------------------------------------------------

async function boot() {
  const credential = await store.getCredential();
  await enforcePurge({ store, now: nowSeconds(), deviceState: credential?.status ?? null });
  const fragment = new URLSearchParams(location.hash.slice(1)).get('a');
  if (fragment !== null) history.replaceState(null, '', location.pathname); // le code d'activation ne reste pas dans l'adresse
  if (credential === null) return showActivation(fragment ?? '');
  if (credential.status === 'PENDING') return showPending();
  if (credential.status === 'DEACTIVATED' || credential.status === 'EXPIRED') return showEnded(credential.status);
  return showHome();
}

// ---- Activation et approbation -----------------------------------------------------------------------------------------------------

function showActivation(prefill = '', message = null) {
  const card = el('section', 'cc-card');
  card.append(el('h2', '', 'Activer cet appareil'));
  card.append(el('p', 'cc-meta', 'Demandez à l’administrateur de votre commune un code d’activation, puis saisissez-le ou scannez son QR code. Il devra ensuite approuver votre téléphone.'));
  const codeLabel = el('label', 'cc-label', 'Code d’activation');
  codeLabel.htmlFor = 'cc-code';
  const code = el('input', 'cc-field');
  Object.assign(code, { id: 'cc-code', type: 'text', value: prefill, autocomplete: 'off', autocapitalize: 'characters', spellcheck: false, placeholder: 'XXXX-XXXX-XXXX' });
  const nameLabel = el('label', 'cc-label', 'Nom de cet appareil (visible par l’administrateur)');
  nameLabel.htmlFor = 'cc-name';
  const name = el('input', 'cc-field');
  Object.assign(name, { id: 'cc-name', type: 'text', maxLength: 60, autocomplete: 'off', placeholder: 'Barrage nord' });
  const status = el('p', 'cc-meta', '');
  const submit = button('Activer', async () => {
    submit.disabled = true;
    status.textContent = 'Activation…';
    const result = await activate({ api, store, code: code.value, label: name.value, platform: detectPlatform(navigator.userAgent) });
    submit.disabled = false;
    const texts = {
      refused: 'Code invalide, expiré ou déjà utilisé.',
      throttled: `Trop de tentatives. Réessayez dans ${result.retryAfter ?? 60} secondes.`,
      network: 'Pas de connexion : l’activation exige le réseau.',
      unavailable: 'Le service est momentanément indisponible. Réessayez.',
      invalid: result.message,
    };
    if (result.state === 'pending') return showPending();
    status.textContent = texts[result.state] ?? 'Activation impossible.';
    return undefined;
  });
  const scan = button('Scanner le QR code d’activation', () => scanActivation(code), 'cc-btn cc-btn--secondary');
  scan.disabled = !cameraSupported();
  card.append(codeLabel, code, nameLabel, name, el('p', 'cc-meta', ''), submit, scan, status);
  show(...(message ? [notice(message)] : []), card);
}

async function scanActivation(codeField) {
  clearAndScan('Scannez le QR code affiché par votre administrateur.', async (text) => {
    const code = extractActivationCode(text);
    if (code === null) return false;
    showActivation(code);
    return true;
  }, () => showActivation(codeField.value));
}

async function showPending() {
  const credential = await store.getCredential();
  const card = el('section', 'cc-card');
  card.append(el('h2', '', 'En attente d’approbation'));
  card.append(el('p', 'cc-meta', 'Montrez ce code à l’administrateur de votre commune, devant lui. Aucune donnée n’est reçue avant son approbation.'));
  card.append(el('p', 'cc-pairing', credential?.pairingCode ?? '······'));
  const status = el('p', 'cc-meta', 'Vérification régulière de l’état de l’appareil…');
  card.append(status);
  card.append(button('Annuler et recommencer', async () => { await store.wipeData({ withCredential: true }); showActivation(); }, 'cc-btn cc-btn--secondary'));
  show(card);
  const poll = async () => {
    const result = await refreshStatus({ api, store, nowSeconds });
    if (result.state === 'active') {
      status.textContent = 'Approuvé. Synchronisation…';
      await doSync();
    } else if (result.state === 'deactivated' || result.state === 'expired') {
      showEnded(result.state.toUpperCase());
    } else if (result.state === 'unauthorized') {
      showActivation('', 'Cet appareil n’est plus reconnu. Activez-le de nouveau.');
    } else if (result.state === 'network') {
      status.textContent = 'Pas de connexion : en attente du réseau…';
    }
  };
  pollTimer = setInterval(poll, 4000);
  poll();
}

function showEnded(state) {
  const card = el('section', 'cc-card');
  card.append(el('h2', '', state === 'DEACTIVATED' ? 'Appareil désactivé' : 'Autorisation terminée'));
  card.append(el('p', 'cc-meta', state === 'DEACTIVATED' ? 'Cet appareil a été désactivé et ses données ont été supprimées. Ne l’utilisez plus pour contrôler.' : 'L’autorisation de cet appareil est terminée et ses données ont été supprimées. Demandez à votre administrateur de la renouveler ou activez l’appareil de nouveau.'));
  card.append(button('Activer avec un nouveau code', async () => { await store.wipeData({ withCredential: true }); showActivation(); }));
  show(card);
}

// ---- Accueil, synchronisation --------------------------------------------------------------------------------------------------------

async function doSync() {
  const result = await runSync({ api, store, nowSeconds });
  if (result.state === 'pending') return showPending();
  if (result.state === 'deactivated' || result.state === 'expired') return showEnded(result.state.toUpperCase());
  if (result.state === 'unauthorized') return showActivation('', 'Cet appareil n’est plus reconnu. Activez-le de nouveau.');
  return showHome(result);
}

async function showHome(syncResult = null) {
  const profile = await store.getProfile();
  const now = nowSeconds();
  const messages = [];
  if (syncResult !== null) {
    const texts = {
      synced: syncResult.clockBad ? 'Synchronisé, mais l’heure du téléphone est incorrecte : réglez l’heure en automatique puis synchronisez de nouveau.' : 'Synchronisation réussie.',
      network: 'Pas de connexion : synchronisation impossible. Les données précédentes restent utilisables dans leur durée de confiance.',
      throttled: 'Trop de synchronisations. Réessayez dans quelques instants.',
      'server-error': 'Le service est momentanément indisponible. Réessayez.',
      integrity: 'Données reçues incomplètes ou altérées : elles ont été refusées, les données précédentes sont conservées.',
      refused: 'Synchronisation refusée.',
    };
    messages.push(notice(texts[syncResult.state] ?? 'Synchronisation terminée.', syncResult.state !== 'synced' || syncResult.clockBad === true));
  }
  const card = el('section', 'cc-card');
  card.append(el('h2', '', profile === null ? 'Aucune donnée sur cet appareil' : `${profile.municipality} · ${profile.eventName}`));
  if (profile === null) {
    card.append(el('p', 'cc-meta', 'Synchronisez cet appareil avec le réseau pour pouvoir contrôler hors connexion.'));
  } else {
    const elapsed = Math.max(0, now - profile.deviceSyncedAt);
    const old = elapsed >= profile.warn;
    const stale = elapsed > profile.hard;
    card.append(el('p', `cc-banner${old ? ' cc-banner--old' : ''}`, stale
      ? 'Données trop anciennes : aucune validation hors connexion. Synchronisez.'
      : `Données synchronisées le ${format(profile.syncedAt, profile.timezone)} (${age(elapsed)}).${old ? ' Données vieillissantes : synchronisez dès que possible.' : ''}`));
    card.append(el('p', 'cc-meta', `Mode ${profile.mode === 'STRICT' ? 'strict' : 'standard'} · confiance maximale ${Math.round(profile.hard / 3600)} h · appareil autorisé jusqu’au ${format(profile.deviceExpiresAt, profile.timezone)}.`));
  }
  card.append(button('Scanner un laissez-passer', startControl));
  card.append(button('Synchroniser', doSync, 'cc-btn cc-btn--secondary'));
  const wipe = button('Effacer les données de cet appareil', async () => {
    if (!confirm('Effacer toutes les données de contrôle de cet appareil ? Il faudra le synchroniser de nouveau.')) return;
    await store.wipeData();
    showHome();
  }, 'cc-btn cc-btn--secondary');
  card.append(wipe);
  show(...messages, card);
  view = 'home';
  scheduleSync();
}

function scheduleSync() {
  if (syncTimer !== null) clearInterval(syncTimer);
  // Synchronisation toutes les 15 minutes TANT QUE l'application est ouverte (aucune synchronisation d'arrière-plan, notamment sur iPhone).
  syncTimer = setInterval(async () => {
    if (document.visibilityState !== 'visible' || activeScan !== null) return;
    const result = await runSync({ api, store, nowSeconds });
    if (result.state === 'synced' && view === 'home') showHome(result);
  }, SYNC_INTERVAL_MS);
}

// ---- Scan et verdict -------------------------------------------------------------------------------------------------------------------

function clearAndScan(prompt, onText, onCancel) {
  stopTimers();
  const video = el('video', 'cc-video');
  video.muted = true;
  const cancel = button('Annuler', () => { stopTimers(); onCancel(); }, 'cc-btn cc-btn--secondary');
  show(notice(prompt), video, cancel);
  let handled = false;
  startScan(video, async (text) => {
    if (handled) return;
    if (await onText(text)) handled = true;
  }).then((session) => { activeScan = session; }).catch(() => {
    show(notice('Impossible d’utiliser la caméra. Autorisez l’accès à la caméra puis réessayez.', true), button('Retour', onCancel));
  });
}

function startControl() {
  clearAndScan('Dirigez la caméra vers le QR code du laissez-passer.', async (text) => {
    const token = extractToken(text, verificationBase);
    if (token === null) {
      show(notice('QR code non reconnu : ce n’est pas un laissez-passer Civipass. Ce n’est pas une validation.', true), button('Scanner de nouveau', startControl), button('Retour', () => showHome(), 'cc-btn cc-btn--secondary'));
      return true;
    }
    stopTimers();
    await control(token);
    return true;
  }, () => showHome());
}

async function control(token) {
  show(notice('Contrôle en cours…'));
  // Mode préféré : le contrôle EN LIGNE (droits recalculés à l'instant). Hors connexion (ou réseau trop lent) : repli sur le verdict local, toujours qualifié.
  if ((await ping()) !== null) {
    window.location.assign(onlineUrl(token, verificationBase));
    return;
  }
  const drift = sessionDriftProblem(sessionStart, { wall: Date.now() / 1000, mono: performance.now() / 1000 });
  const verdict = await offlineVerdict({ store, token, now: nowSeconds(), sessionClockProblem: drift });
  await showVerdict(verdict, token);
}

async function ping() {
  const response = await api.ping();
  return response.kind === 'http' && response.ok && typeof response.body?.t === 'number' ? response.body.t : null;
}

const ZONE_LABELS = { ACTIVE: 'Autorisée maintenant', UPCOMING: 'À venir', OUTSIDE: 'Hors période', SUSPENDED: 'Suspendue', EXPIRED: 'Expirée' };

async function showVerdict(verdict, token) {
  const profile = await store.getProfile();
  const text = describe(verdict);
  const tone = { VALID: 'valid', VALID_OLD: 'old', REVOKED: 'invalid', REPLACED: 'invalid', NOT_VALID: 'invalid', EVENT_ENDED: 'grey', INDETERMINATE: 'indeterminate' }[verdict.code] ?? 'indeterminate';
  const nodes = [];
  if (verdict.qualifier !== undefined && profile !== null) {
    nodes.push(el('p', `cc-banner${verdict.qualifier.band !== 'recent' ? ' cc-banner--old' : ''}`, `Vérification hors connexion — données du ${format(profile.syncedAt, profile.timezone)} (${age(verdict.qualifier.ageSeconds)}). Elles ne reflètent pas les modifications récentes.`));
  } else {
    nodes.push(el('p', 'cc-banner', 'Vérification hors connexion — aucune donnée de contrôle exploitable sur cet appareil.'));
  }
  const box = el('section', `cc-verdict cc-verdict--${tone}`);
  box.setAttribute('role', 'status');
  box.append(el('h2', '', text.title), el('p', '', text.text));
  nodes.push(box);
  if (verdict.person !== undefined) {
    const person = el('section', 'cc-card');
    person.append(el('p', 'cc-person', `${verdict.person.first} ${verdict.person.last.toUpperCase()}`));
    if (verdict.person.plates.length > 0) {
      const plates = el('div', 'cc-plates');
      for (const plate of verdict.person.plates) plates.append(el('span', 'cc-plate', plate));
      person.append(plates);
    } else {
      person.append(el('p', 'cc-meta', 'Aucun véhicule associé.'));
    }
    person.append(el('p', 'cc-meta', 'Rapprochez le nom et les plaques de la personne et du véhicule. Ce QR code ne prouve pas l’identité.'));
    person.append(el('p', 'cc-meta', `Référence ${verdict.person.reference}`));
    if (verdict.zones !== undefined) {
      const list = el('ul', 'cc-zones');
      for (const zone of verdict.zones) {
        const item = el('li', 'cc-zone');
        item.append(el('strong', '', zone.label), document.createTextNode(ZONE_LABELS[zone.status] ?? zone.status));
        if (zone.status === 'ACTIVE' || zone.status === 'UPCOMING') {
          const next = zone.windows.find(([start, end]) => end > nowSeconds());
          if (next !== undefined) item.append(el('span', 'cc-meta', ` · ${format(next[0], profile?.timezone)} → ${format(next[1], profile?.timezone)}`));
        }
        list.append(item);
      }
      person.append(list);
    }
    nodes.push(person);
  }
  nodes.push(button('Contrôler en ligne', async () => {
    if ((await ping()) !== null) window.location.assign(onlineUrl(token, verificationBase));
    else showVerdict(verdict, token).then(() => root.prepend(notice('Toujours pas de connexion. Appliquez la procédure de terrain. Ce n’est pas un refus d’autorisation.', true)));
  }, 'cc-btn cc-btn--secondary'));
  nodes.push(button('Scanner un autre laissez-passer', startControl));
  nodes.push(button('Retour', () => showHome(), 'cc-btn cc-btn--secondary'));
  show(...nodes);
}

registerServiceWorker();
boot();
