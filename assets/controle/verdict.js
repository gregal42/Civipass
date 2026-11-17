// Verdict HORS CONNEXION (LOT 09.4, DM-130) : fonction PURE, testée avec node:test. Le serveur reste l'unique moteur de droits : l'appareil compare l'heure aux fenêtres UTC
// déjà calculées (état « V » avec fenêtres, « S » suspendu, « E » expiré, « F » futur au-delà de l'horizon). Règles non négociables :
//  - un verdict POSITIF n'est jamais vert et n'emploie JAMAIS les mots « vérifié », « confirmé » ou « à jour » : il est toujours « selon la dernière synchronisation » ;
//  - tout verdict (positif ou négatif) est QUALIFIÉ par l'instant et l'âge de la synchronisation ;
//  - au moindre défaut de confiance (aucun jeu, horloge incohérente, appareil expiré/désactivé, jeu trop ancien, jeton inconnu) : « indéterminé », JAMAIS un refus ni un vert ;
//  - mode STRICT : aucun verdict positif (« indéterminé : confirmation en ligne requise ») ; les cas négatifs connus restent affichés.
import { clockProblem } from './clock.js';

/** Bande de fraîcheur : 'recent' (< avertissement), 'old' (avertissement → seuil dur), 'stale' (> seuil dur). */
export function ageBand(ageSeconds, warn, hard) {
  if (ageSeconds > hard) return 'stale';
  return ageSeconds >= warn ? 'old' : 'recent';
}

function zoneStatus(zone, now) {
  switch (zone.s) {
    case 'S': return 'SUSPENDED';
    case 'E': return 'EXPIRED';
    case 'F': return 'UPCOMING';
    default: {
      if (zone.w.some(([start, end]) => start <= now && now < end)) return 'ACTIVE';
      return zone.w.some(([start]) => start > now) ? 'UPCOMING' : 'OUTSIDE';
    }
  }
}

/** Agrège les zones comme le contrôle en ligne (PublicVerdict::derive) : une zone active suffit ; sinon à venir, suspendue, expirée. */
function aggregate(zones) {
  const statuses = zones.map((z) => z.status);
  if (statuses.includes('ACTIVE')) return { positive: true, partial: statuses.some((s) => s !== 'ACTIVE') };
  if (statuses.some((s) => s === 'UPCOMING' || s === 'OUTSIDE')) return { positive: false, cause: 'UPCOMING' };
  if (statuses.includes('SUSPENDED')) return { positive: false, cause: 'SUSPENDED' };
  if (statuses.length > 0 && statuses.every((s) => s === 'EXPIRED')) return { positive: false, cause: 'EXPIRED' };

  return { positive: false, cause: 'NONE' };
}

/**
 * @param {object} input
 * @param {number} input.now                    secondes (horloge de l'appareil)
 * @param {object|null} input.profile           profil de synchronisation (voir profile.js) ; null = aucun jeu valide
 * @param {{kind:'P'|'R'|'X', record:object}|null} input.lookup   enregistrement trouvé pour l'empreinte du jeton, ou null
 * @param {'ACTIVE'|'EXPIRED'|'DEACTIVATED'|'PENDING'|null} [input.deviceState]  état de l'appareil connu localement
 */
export function computeVerdict({ now, profile, lookup, deviceState = null }) {
  const indeterminate = (reason, extra = {}) => ({ code: 'INDETERMINATE', reason, ...extra });
  if (profile === null || profile === undefined) return indeterminate('no-dataset');
  if (deviceState === 'DEACTIVATED') return indeterminate('device-deactivated');
  if (deviceState === 'PENDING') return indeterminate('device-pending');
  if (deviceState === 'EXPIRED' || now >= profile.deviceExpiresAt) return indeterminate('device-expired');
  const clock = clockProblem(now, profile);
  if (clock !== null) return indeterminate(clock);

  const age = Math.max(0, now - profile.deviceSyncedAt);
  const band = ageBand(age, profile.warn, profile.hard);
  const qualifier = { ageSeconds: age, syncedAt: profile.syncedAt, band, mode: profile.mode };
  const base = (verdict) => ({ ...verdict, qualifier });

  if (profile.eventClosed || (profile.eventEnd !== null && now >= profile.eventEnd)) return base({ code: 'EVENT_ENDED' });
  if (lookup !== null && lookup !== undefined && lookup.kind === 'R') return base({ code: 'REVOKED', stale: band === 'stale' });
  if (lookup !== null && lookup !== undefined && lookup.kind === 'X') return base({ code: 'REPLACED', stale: band === 'stale' });
  if (band === 'stale' || now >= profile.horizon) return base(indeterminate('stale'));
  if (lookup === null || lookup === undefined) return base(indeterminate('unknown'));

  const record = lookup.record;
  const zones = record.z.map((zone) => ({ label: zone.l, status: zoneStatus(zone, now), windows: zone.w }));
  const person = { first: record.f, last: record.l, plates: record.p, reference: record.r };
  const result = aggregate(zones);
  if (!result.positive) return base({ code: 'NOT_VALID', cause: result.cause, person, zones });
  if (profile.mode === 'STRICT') return base(indeterminate('strict', { person, zones }));

  return base({ code: band === 'old' ? 'VALID_OLD' : 'VALID', partial: result.partial, person, zones });
}

const FORBIDDEN_POSITIVE_WORDS = /vérifi|confirm|à jour|temps réel/i;

/** Textes FIXES affichés (DM-128 étendu) : titre, consigne et ton. Le verdict positif ne contient jamais de mot de vérification. */
export function describe(verdict) {
  const stale = verdict.stale ? ' (information ancienne, non actualisée)' : '';
  switch (verdict.code) {
    case 'VALID':
      return { tone: 'valid', title: 'Valide selon la dernière synchronisation', text: verdict.partial ? 'Certaines zones seulement sont autorisées : contrôlez la zone concernée ci-dessous.' : 'Les droits affichés reposent sur les données du téléphone, pas sur le serveur.' };
    case 'VALID_OLD':
      return { tone: 'old', title: 'Valide selon des données anciennes', text: 'Données de plus de quelques heures : une révocation a pu échapper à cet appareil. Contrôlez en ligne dès que possible ou contactez votre poste de commandement.' };
    case 'NOT_VALID': {
      const causes = { UPCOMING: 'Aucun droit actif maintenant ; des droits commencent plus tard.', SUSPENDED: 'Les accès sont suspendus, selon la dernière synchronisation.', EXPIRED: 'Les périodes d’accès sont terminées, selon la dernière synchronisation.', NONE: 'Aucun droit actif, selon la dernière synchronisation.' };
      return { tone: 'invalid', title: 'Non valide selon la dernière synchronisation', text: causes[verdict.cause] ?? causes.NONE };
    }
    case 'REVOKED':
      return { tone: 'invalid', title: `Révoqué selon la dernière synchronisation${stale}`, text: 'Ce laissez-passer a été révoqué. Ne le considérez pas comme valide.' };
    case 'REPLACED':
      return { tone: 'invalid', title: `QR code remplacé selon la dernière synchronisation${stale}`, text: 'Ce QR code n’est plus valable. Demandez le nouveau QR code à la personne ou contactez la commune.' };
    case 'EVENT_ENDED':
      return { tone: 'grey', title: 'Événement terminé', text: 'L’événement est terminé : plus aucun accès n’est autorisé.' };
    case 'INDETERMINATE': {
      const reasons = {
        'no-dataset': 'Aucune donnée de contrôle sur cet appareil. Synchronisez-le avec le réseau.',
        'device-pending': 'Cet appareil n’est pas encore approuvé.',
        'device-deactivated': 'Cet appareil a été désactivé. Ne l’utilisez plus pour contrôler.',
        'device-expired': 'L’autorisation de cet appareil est terminée. Demandez son renouvellement.',
        'clock-offset': 'L’heure du téléphone était incorrecte à la dernière synchronisation. Réglez l’heure en automatique puis synchronisez.',
        'clock-backwards': 'L’heure du téléphone est incohérente. Réglez l’heure en automatique puis synchronisez.',
        'clock-before-sync': 'L’heure du téléphone est incohérente. Réglez l’heure en automatique puis synchronisez.',
        'clock-changed': 'L’heure du téléphone a changé. Réglez l’heure en automatique puis synchronisez.',
        stale: 'Données trop anciennes : aucune validation hors connexion. Synchronisez ou vérifiez en ligne.',
        unknown: 'Ce QR code est inconnu de cet appareil (hors du périmètre de cette commune, ou postérieur à la dernière synchronisation).',
        strict: 'Mode strict : confirmation en ligne requise. Les informations affichées reposent sur la dernière synchronisation.',
      };
      return { tone: 'indeterminate', title: 'Indéterminé', text: `${reasons[verdict.reason] ?? 'Vérification impossible.'} Ce n’est pas un refus d’autorisation : appliquez la procédure de terrain de votre commune.` };
    }
    default:
      return { tone: 'indeterminate', title: 'Indéterminé', text: 'Vérification impossible. Ce n’est pas un refus d’autorisation.' };
  }
}

/** Garde de non-régression : aucun texte POSITIF n'emploie un mot de vérification en temps réel. */
export function positiveTextsAreHonest() {
  return ['VALID', 'VALID_OLD'].every((code) => {
    const { title, text } = describe({ code, partial: false });
    return !FORBIDDEN_POSITIVE_WORDS.test(title) && !FORBIDDEN_POSITIVE_WORDS.test(text);
  });
}
