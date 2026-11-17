// Client de l'API d'appareil (LOT 09.2/09.3). Aucune session ni cookie (`credentials: 'omit'`) ; le secret d'appareil voyage dans l'en-tête Authorization, jamais dans une URL ;
// aucune réponse n'est mise en cache (`no-store`). Toute erreur réseau est renvoyée sous la forme {kind:'network'} : jamais une exception qui pourrait laisser un état à moitié écrit.
export function createApi(apiBase, fetchImpl = globalThis.fetch.bind(globalThis)) {
  async function call(method, path, { body, secret, timeoutMs = 20000 } = {}) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeoutMs);
    try {
      const headers = { Accept: 'application/json' };
      if (secret) headers.Authorization = `Bearer ${secret}`;
      if (body !== undefined) headers['Content-Type'] = 'application/json';
      const response = await fetchImpl(`${apiBase}${path}`, { method, headers, body: body === undefined ? undefined : JSON.stringify(body), cache: 'no-store', credentials: 'omit', signal: controller.signal });
      let json = null;
      try {
        json = await response.json();
      } catch {
        json = null; // corps absent, tronqué ou illisible : l'appelant le traite comme une réponse invalide
      }

      return { kind: 'http', status: response.status, ok: response.ok, body: json, retryAfter: Number(response.headers?.get?.('Retry-After') ?? 0) };
    } catch {
      return { kind: 'network' };
    } finally {
      clearTimeout(timer);
    }
  }

  return {
    ping: () => call('GET', '/ping', { timeoutMs: 3000 }),
    register: (payload) => call('POST', '/activation', { body: payload }),
    device: (secret) => call('GET', '/device', { secret, timeoutMs: 8000 }),
    sync: (secret, buckets) => call('POST', '/sync', { secret, body: buckets === null || buckets === undefined ? undefined : { buckets } }),
  };
}
