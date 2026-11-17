// Stockage local (LOT 09.4) : interface + implémentation EN MÉMOIRE (tests, repli). L'implémentation IndexedDB est dans idb-store.js. AUCUN chiffrement applicatif n'est
// présenté comme protection (DM-132 §6) : la protection repose sur la minimisation, la durée, la purge, l'isolation et le verrouillage du téléphone.
//
// Contrat :
//  - credential : {secret, label, status, pairingCode?, deadline?} (le secret d'appareil ; jamais journalisé) ;
//  - profile    : profil de synchronisation (profile.js) ou null ;
//  - records    : table clé (SHA-256 du jeton) → ligne ;
//  - saveSync(profile, records) est ATOMIQUE : tout ou rien (transaction IndexedDB) ; une interruption laisse l'ancien jeu intact.

export class MemoryStore {
  constructor() {
    this.credentialValue = null;
    this.profileValue = null;
    this.recordsValue = new Map();
  }

  async getCredential() { return this.credentialValue === null ? null : { ...this.credentialValue }; }
  async setCredential(credential) { this.credentialValue = { ...credential }; }
  async clearCredential() { this.credentialValue = null; }
  async getProfile() { return this.profileValue === null ? null : { ...this.profileValue }; }
  async setProfile(profile) { this.profileValue = { ...profile }; }
  async getRecords() { return new Map(this.recordsValue); }
  async getRecord(key) { return this.recordsValue.get(key) ?? null; }

  /** Atomique : remplace profil ET lignes en une seule opération. */
  async saveSync(profile, records) {
    this.profileValue = { ...profile };
    this.recordsValue = new Map(records);
  }

  /** Remplace seulement les lignes conservées (purge « identifiants ») : le profil reste. */
  async replaceRecords(records) { this.recordsValue = new Map(records); }

  /** Efface profil et lignes ; le secret d'appareil est conservé (pour connaître l'état), sauf `withCredential`. */
  async wipeData({ withCredential = false } = {}) {
    this.profileValue = null;
    this.recordsValue = new Map();
    if (withCredential) this.credentialValue = null;
  }
}
