// Lecture d'un QR de laissez-passer (LOT 09.1) : fonction PURE, sans accès réseau ni DOM, testée avec node:test.
// Une valeur lue par la caméra est une entrée NON FIABLE : seule une URL de l'origine de vérification configurée, de la forme
// `{origine}/v/{jeton}` (jeton de 32 caractères base32), est acceptée. Aucune exception : `null` si la valeur n'est pas reconnue.
// Le jeton est un secret de consultation (DM-113) : il n'est jamais journalisé ni affiché.

const TOKEN = /^[a-z2-7]{32}$/;
const MAX_LENGTH = 2048;

/**
 * @param {string} scanned     texte décodé du QR
 * @param {string} verificationBase  URL de base de la vérification publique (configuration serveur), ex. « https://civipass.example »
 * @returns {string|null} le jeton en minuscules, ou null
 */
export function extractToken(scanned, verificationBase) {
  if (typeof scanned !== 'string' || typeof verificationBase !== 'string') return null;
  const text = scanned.trim();
  if (text.length === 0 || text.length > MAX_LENGTH) return null;
  let url;
  let base;
  try {
    url = new URL(text);
    base = new URL(verificationBase);
  } catch {
    return null;
  }
  if (url.origin !== base.origin || url.origin === 'null') return null;
  if (url.username !== '' || url.password !== '') return null;
  const match = /^\/v\/([A-Za-z2-7]{32})\/?$/.exec(url.pathname);
  if (match === null) return null;
  const token = match[1].toLowerCase();

  return TOKEN.test(token) ? token : null;
}

/** URL de la page de contrôle EN LIGNE (temps réel) d'un jeton reconnu. */
export function onlineUrl(token, verificationBase) {
  return `${new URL(verificationBase).origin}/v/${token}`;
}
