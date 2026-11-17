// Cycle de vie des données locales (LOT 09.5, DM-132 §5, §27 du cadrage) : règles PURES de suppression.
//  - 'all'         : appareil expiré, événement terminé ou clos, appareil désactivé → TOUT est supprimé (y compris les empreintes révoquées) ;
//  - 'identifying' : âge > seuil dur → noms, plaques, fenêtres supprimés ; SEULES les empreintes révoquées/remplacées sont conservées (sans nom) ;
//  - 'none'        : rien.
// LIMITE : une PWA ne s'exécute pas en arrière-plan ; ces suppressions ont lieu à l'OUVERTURE de l'application (et à chaque synchronisation).

/** @returns {'none'|'identifying'|'all'} */
export function planPurge({ now, profile, deviceState = null }) {
  if (deviceState === 'DEACTIVATED' || deviceState === 'EXPIRED') return 'all';
  if (profile === null || profile === undefined) return 'none';
  if (now >= profile.deviceExpiresAt) return 'all';
  if (profile.eventClosed || (profile.eventEnd !== null && now >= profile.eventEnd)) return 'all';
  if (now - profile.deviceSyncedAt > profile.hard) return 'identifying';

  return 'none';
}

/** Lignes conservées après une purge 'identifying' : uniquement les empreintes révoquées (t = R) ou remplacées (t = X). */
export function keepTombstones(linesByKey, parseLine) {
  const kept = new Map();
  for (const [key, line] of linesByKey) {
    const record = parseLine(line);
    if (record !== null && (record.t === 'R' || record.t === 'X')) kept.set(key, line);
  }

  return kept;
}
