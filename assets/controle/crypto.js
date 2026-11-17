// SHA-256 (WebCrypto) : navigateur et Node ≥ 20 (`globalThis.crypto.subtle`). Les empreintes sont des chaînes hexadécimales minuscules.
export async function sha256Hex(text) {
  const bytes = new TextEncoder().encode(text);
  const digest = await globalThis.crypto.subtle.digest('SHA-256', bytes);
  return Array.from(new Uint8Array(digest), (b) => b.toString(16).padStart(2, '0')).join('');
}
