// Profil de synchronisation (LOT 09.4) : ce que l'appareil retient de la dernière réponse, hors lignes. Construit UNIQUEMENT depuis une enveloppe déjà validée.
import { offsetProblem } from './clock.js';

/**
 * @param {object} envelope  enveloppe validée (dataset.envelopeProblem === null)
 * @param {number} deviceNow horloge de l'appareil à la réception (secondes)
 */
export function profileFrom(envelope, deviceNow) {
  return {
    syncedAt: envelope.gen,
    deviceSyncedAt: deviceNow,
    lastSeen: deviceNow,
    clockBadAtSync: offsetProblem(deviceNow, envelope.gen),
    horizon: envelope.hor,
    eventRef: envelope.ev.ref,
    eventName: envelope.ev.name,
    eventEnd: envelope.ev.end,
    eventClosed: envelope.ev.closed,
    municipality: envelope.mu,
    timezone: envelope.tz,
    warn: envelope.tr.w,
    hard: envelope.tr.h,
    mode: envelope.tr.m,
    deviceExpiresAt: envelope.dev.exp,
    root: envelope.root,
    count: envelope.n,
  };
}
