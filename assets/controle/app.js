// PWA de contrôle (LOT 09) — point d'entrée. 09.1 : coquille, Service Worker, scan dans l'application et ouverture du contrôle EN LIGNE.
// Tout texte affiché passe par textContent (jamais innerHTML) : aucune donnée n'est interprétée comme du HTML.
import { cameraSupported, startScan } from './camera.js';
import { extractToken, onlineUrl } from './scan.js';

const root = document.getElementById('cc-app');
const verificationBase = root.dataset.verificationBase;
const apiBase = root.dataset.apiBase;

function el(tag, className, text) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== undefined) node.textContent = text;
  return node;
}

function clear() {
  root.replaceChildren();
}

async function registerServiceWorker() {
  if (!('serviceWorker' in navigator)) return;
  try {
    await navigator.serviceWorker.register(root.dataset.sw, { scope: '/controle/' });
  } catch {
    // la PWA fonctionne sans Service Worker (mode hors ligne indisponible)
  }
}

/** Sonde de connectivité : réponse du serveur en moins de 3 s ? (renvoie l'heure serveur ou null) */
async function ping(timeoutMs = 3000) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  try {
    const response = await fetch(`${apiBase}/ping`, { cache: 'no-store', credentials: 'omit', signal: controller.signal });
    if (!response.ok) return null;
    const body = await response.json();
    return typeof body.t === 'number' ? body.t : null;
  } catch {
    return null;
  } finally {
    clearTimeout(timer);
  }
}

function header() {
  const head = el('div', 'cc-head');
  head.append(el('h1', 'cc-title', 'Contrôle Civipass'));
  return head;
}

function showHome(message) {
  clear();
  root.append(header());
  if (message) root.append(el('p', `cc-notice${message.error ? ' cc-notice--error' : ''}`, message.text));
  const card = el('section', 'cc-card');
  card.append(el('h2', '', 'Scanner un laissez-passer'));
  card.append(el('p', 'cc-meta', 'Dirigez la caméra vers le QR code. Avec du réseau, la page de contrôle en ligne (temps réel) s’ouvre.'));
  const button = el('button', 'cc-btn', 'Scanner');
  button.type = 'button';
  button.disabled = !cameraSupported();
  button.addEventListener('click', showScanner);
  card.append(button);
  if (!cameraSupported()) card.append(el('p', 'cc-notice cc-notice--error', 'La caméra n’est pas disponible sur cet appareil ou ce navigateur.'));
  root.append(card);
}

async function showScanner() {
  clear();
  root.append(header());
  const video = el('video', 'cc-video');
  video.muted = true;
  const cancel = el('button', 'cc-btn cc-btn--secondary', 'Annuler');
  cancel.type = 'button';
  root.append(video, cancel);
  let session = null;
  let handled = false;
  const finish = async (message) => {
    if (session !== null) session.stop();
    showHome(message);
  };
  cancel.addEventListener('click', () => finish(null));
  try {
    session = await startScan(video, async (text) => {
      if (handled) return;
      const token = extractToken(text, verificationBase);
      if (token === null) {
        handled = true;
        await finish({ error: true, text: 'QR code non reconnu : ce n’est pas un laissez-passer Civipass. Ce n’est pas une validation.' });
        return;
      }
      handled = true;
      session.stop();
      await openOnline(token);
    });
  } catch {
    await finish({ error: true, text: 'Impossible d’utiliser la caméra. Autorisez l’accès à la caméra puis réessayez.' });
  }
}

async function openOnline(token) {
  clear();
  root.append(header(), el('p', 'cc-notice', 'Vérification en cours…'));
  if ((await ping()) !== null) {
    // Mode préféré : la page de contrôle EN LIGNE (droits recalculés à l'instant). Le jeton n'est écrit nulle part ailleurs que dans cette adresse.
    window.location.assign(onlineUrl(token, verificationBase));
    return;
  }
  showHome({ error: true, text: 'Pas de connexion : le contrôle hors ligne n’est pas encore disponible sur cet appareil. Appliquez la procédure de terrain. Ce n’est pas un refus d’autorisation.' });
}

registerServiceWorker();
showHome(null);
