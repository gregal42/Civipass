// Cohérence de l'horloge de l'appareil (LOT 09.4, DM-130 §6). Fonctions PURES. GARANTIES RÉELLES et LIMITES : le navigateur n'offre aucune source de temps fiable hors connexion ;
// on ne détecte que (1) une horloge déjà fausse au moment de la synchronisation, (2) un RECUL entre deux usages (dernière heure vue), (3) un changement d'horloge pendant que
// la page est ouverte. Une AVANCE d'horloge, application fermée, n'est pas détectable seule : elle est bornée par l'horizon du jeu. Toute incohérence donne « indéterminé ».
export const TOLERANCE_SECONDS = 120;
export const SESSION_DRIFT_SECONDS = 5;

/** Écart entre l'horloge de l'appareil et celle du serveur à la synchronisation : trop grand → jeu inutilisable (« réglez l'heure »). */
export function offsetProblem(deviceNow, serverGenerated, tolerance = TOLERANCE_SECONDS) {
  return Math.abs(deviceNow - serverGenerated) > tolerance;
}

/**
 * @param {{deviceSyncedAt: number, lastSeen: number, clockBadAtSync?: boolean}} profile
 * @returns {null|'clock-offset'|'clock-backwards'|'clock-before-sync'}
 */
export function clockProblem(now, profile, tolerance = TOLERANCE_SECONDS) {
  if (profile.clockBadAtSync === true) return 'clock-offset';
  if (now < profile.lastSeen - tolerance) return 'clock-backwards';
  if (now < profile.deviceSyncedAt - tolerance) return 'clock-before-sync';

  return null;
}

/** Dernière heure locale vue : ne recule jamais. */
export function touch(profile, now) {
  return { ...profile, lastSeen: Math.max(profile.lastSeen ?? 0, now) };
}

/**
 * Dérive pendant la session : l'horloge murale et l'horloge monotone de la page doivent avancer du même temps.
 * @param {{wall: number, mono: number}} start  relevés (secondes) à l'ouverture de la page
 * @param {{wall: number, mono: number}} current
 */
export function sessionDriftProblem(start, current, tolerance = SESSION_DRIFT_SECONDS) {
  return Math.abs((current.wall - start.wall) - (current.mono - start.mono)) > tolerance ? 'clock-changed' : null;
}
