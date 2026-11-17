// Client minimal du protocole DevTools de Chrome (WebSocket natif, Node ≥ 22) : aucune dépendance ajoutée (DT-057). Pilote UN onglet.
import { spawn } from 'node:child_process';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

export async function launchChrome({ chromePath, port = 9300 + Math.floor(Math.random() * 600) } = {}) {
  const profile = mkdtempSync(join(tmpdir(), 'civipass-e2e-'));
  const child = spawn(chromePath, [
    '--headless=new', `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`, '--no-first-run', '--no-default-browser-check',
    '--disable-extensions', '--disable-sync', '--window-size=412,915', 'about:blank',
  ], { stdio: 'ignore', detached: true });
  let target = null;
  for (let i = 0; i < 60 && target === null; i++) {
    await sleep(250);
    try {
      const list = await (await fetch(`http://127.0.0.1:${port}/json/list`)).json();
      target = list.find((t) => t.type === 'page') ?? null;
    } catch {
      target = null;
    }
  }
  if (target === null) throw new Error('Chrome non joignable');
  const ws = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((resolve, reject) => { ws.onopen = resolve; ws.onerror = reject; });
  let id = 0;
  const pending = new Map();
  const handlers = [];
  ws.onmessage = (event) => {
    const message = JSON.parse(event.data);
    if (message.id !== undefined && pending.has(message.id)) {
      const { resolve, reject } = pending.get(message.id);
      pending.delete(message.id);
      if (message.error) reject(new Error(`${message.error.message}`)); else resolve(message.result);
    } else if (message.method) {
      for (const handler of handlers) handler(message);
    }
  };
  const send = (method, params = {}) => new Promise((resolve, reject) => {
    const messageId = ++id;
    pending.set(messageId, { resolve, reject });
    ws.send(JSON.stringify({ id: messageId, method, params }));
  });

  return {
    send,
    on: (handler) => handlers.push(handler),
    async evaluate(expression) {
      const result = await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true, userGesture: true });
      if (result.exceptionDetails) throw new Error(`Évaluation en échec : ${result.exceptionDetails.exception?.description ?? result.exceptionDetails.text}`);
      return result.result.value;
    },
    async waitFor(expression, { timeoutMs = 20000, label = expression } = {}) {
      const start = Date.now();
      for (;;) {
        try {
          const value = await this.evaluate(expression);
          if (value) return value;
        } catch {
          // la page peut être en cours de navigation
        }
        if (Date.now() - start > timeoutMs) throw new Error(`Délai dépassé : ${label}`);
        await sleep(250);
      }
    },
    sleep,
    async close() {
      try { ws.close(); } catch { /* déjà fermé */ }
      try { process.kill(-child.pid, 'SIGKILL'); } catch { child.kill('SIGKILL'); }
      await sleep(300);
      rmSync(profile, { recursive: true, force: true });
    },
  };
}
