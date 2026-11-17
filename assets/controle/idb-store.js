// Stockage IndexedDB (LOT 09.4). Même contrat que MemoryStore. Un seul enregistrement `profile`, un `credential`, et une ligne par clé de jeton ; l'écriture d'un jeu
// (clear + put de toutes les lignes + profil) se fait dans UNE transaction : tout ou rien. Aucune donnée n'est chiffrée par l'application (voir store.js).
const DB_NAME = 'civipass-control';
const VERSION = 1;

function request(req) {
  return new Promise((resolve, reject) => {
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}

function done(tx) {
  return new Promise((resolve, reject) => {
    tx.oncomplete = () => resolve();
    tx.onerror = () => reject(tx.error);
    tx.onabort = () => reject(tx.error ?? new Error('transaction-aborted'));
  });
}

export class IdbStore {
  constructor(factory = globalThis.indexedDB) {
    this.factory = factory;
    this.dbPromise = null;
  }

  db() {
    this.dbPromise ??= new Promise((resolve, reject) => {
      const open = this.factory.open(DB_NAME, VERSION);
      open.onupgradeneeded = () => {
        const db = open.result;
        db.createObjectStore('kv');
        db.createObjectStore('rec');
      };
      open.onsuccess = () => resolve(open.result);
      open.onerror = () => reject(open.error);
    });

    return this.dbPromise;
  }

  async get(store, key) {
    const db = await this.db();
    const value = await request(db.transaction(store, 'readonly').objectStore(store).get(key));

    return value === undefined ? null : value;
  }

  async getCredential() { return this.get('kv', 'credential'); }
  async setCredential(credential) { const db = await this.db(); const tx = db.transaction('kv', 'readwrite'); tx.objectStore('kv').put({ ...credential }, 'credential'); await done(tx); }
  async clearCredential() { const db = await this.db(); const tx = db.transaction('kv', 'readwrite'); tx.objectStore('kv').delete('credential'); await done(tx); }
  async getProfile() { return this.get('kv', 'profile'); }
  async setProfile(profile) { const db = await this.db(); const tx = db.transaction('kv', 'readwrite'); tx.objectStore('kv').put({ ...profile }, 'profile'); await done(tx); }
  async getRecord(key) { return this.get('rec', key); }

  async getRecords() {
    const db = await this.db();
    const store = db.transaction('rec', 'readonly').objectStore('rec');
    const [keys, values] = await Promise.all([request(store.getAllKeys()), request(store.getAll())]);

    return new Map(keys.map((key, index) => [key, values[index]]));
  }

  async saveSync(profile, records) {
    const db = await this.db();
    const tx = db.transaction(['kv', 'rec'], 'readwrite');
    const rec = tx.objectStore('rec');
    rec.clear();
    for (const [key, line] of records) rec.put(line, key);
    tx.objectStore('kv').put({ ...profile }, 'profile');
    await done(tx);
  }

  async replaceRecords(records) {
    const db = await this.db();
    const tx = db.transaction('rec', 'readwrite');
    const rec = tx.objectStore('rec');
    rec.clear();
    for (const [key, line] of records) rec.put(line, key);
    await done(tx);
  }

  async wipeData({ withCredential = false } = {}) {
    const db = await this.db();
    const tx = db.transaction(['kv', 'rec'], 'readwrite');
    tx.objectStore('rec').clear();
    tx.objectStore('kv').delete('profile');
    if (withCredential) tx.objectStore('kv').delete('credential');
    await done(tx);
  }
}
