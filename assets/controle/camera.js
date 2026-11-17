// Scan dans l'application (LOT 09.1) : caméra arrière via getUserMedia ; `BarcodeDetector` natif lorsqu'il existe (Android/Chrome),
// repli sur jsQR 1.4.0 embarqué (assets/vendor/jsqr, script classique `window.jsQR`) — notamment iPhone, sans BarcodeDetector. Aucune image n'est
// enregistrée ni envoyée : le flux vidéo reste dans la page. Le décodage est limité à ~6 images par seconde et à 640 px de large (matériel modeste).

const FRAME_INTERVAL_MS = 160;
const MAX_WIDTH = 640;

export function cameraSupported() {
  return typeof navigator !== 'undefined' && navigator.mediaDevices && typeof navigator.mediaDevices.getUserMedia === 'function';
}

/**
 * Démarre le scan ; appelle onResult(texte) à chaque décodage, jusqu'à stop(). Rejette si la caméra est refusée ou absente.
 * @param {HTMLVideoElement} video
 * @param {(text: string) => void} onResult
 * @returns {Promise<{stop: () => void}>}
 */
export async function startScan(video, onResult) {
  if (!cameraSupported()) throw new Error('camera-unsupported');
  const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false });
  video.srcObject = stream;
  video.setAttribute('playsinline', 'true');
  await video.play();

  const detector = typeof globalThis.BarcodeDetector === 'function' ? new globalThis.BarcodeDetector({ formats: ['qr_code'] }) : null;
  const canvas = document.createElement('canvas');
  const context = canvas.getContext('2d', { willReadFrequently: true });
  let stopped = false;
  let busy = false;

  const tick = async () => {
    if (stopped) return;
    if (!busy && video.readyState >= 2 && video.videoWidth > 0) {
      busy = true;
      try {
        let text = null;
        if (detector !== null) {
          const found = await detector.detect(video);
          if (found.length > 0) text = found[0].rawValue;
        } else if (typeof globalThis.jsQR === 'function') {
          const scale = Math.min(1, MAX_WIDTH / video.videoWidth);
          canvas.width = Math.max(1, Math.round(video.videoWidth * scale));
          canvas.height = Math.max(1, Math.round(video.videoHeight * scale));
          context.drawImage(video, 0, 0, canvas.width, canvas.height);
          const image = context.getImageData(0, 0, canvas.width, canvas.height);
          const code = globalThis.jsQR(image.data, image.width, image.height, { inversionAttempts: 'dontInvert' });
          if (code !== null) text = code.data;
        }
        if (text !== null && !stopped) onResult(text);
      } catch {
        // une image illisible n'interrompt pas le scan
      } finally {
        busy = false;
      }
    }
    setTimeout(tick, FRAME_INTERVAL_MS);
  };
  setTimeout(tick, FRAME_INTERVAL_MS);

  return {
    stop() {
      stopped = true;
      stream.getTracks().forEach((track) => track.stop());
      video.srcObject = null;
    },
  };
}
