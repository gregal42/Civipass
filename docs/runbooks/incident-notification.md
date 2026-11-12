# Incident de notification

Voir aussi [exploitation-notifications.md](../exploitation-notifications.md) (distributeur, SMTP, rotation des clés).

## Étapes techniques
1. **Distributeur arrêté** : `app:ops:status` signale `notifications-dispatch` en retard ; `systemctl status civipass-notifications-dispatch.timer`, relancer. Les messages échus partent ; garantie « au moins une fois ».
2. **Échec SMTP** : l'unité `OnFailure` envoie une alerte ; vérifier `OPS_ALERT_EMAIL` et le relais ; les messages restent `PENDING`/`RESERVED` puis reprennent (bail).
3. **Doublon possible** après arrêt brutal (même identifiant de message) : normal, jamais un nouveau lien.
4. **Avis de conservation ou alerte de dormance** : **non activés** ; ne pas les activer en incident. Un envoi erroné se constate dans l'audit (`DORMANCY_ALERT_SENT`, `RETENTION` notice), jamais en base de contenus personnels.
5. **Clé de chiffrement compromise** : rotation selon [exploitation-notifications.md](../exploitation-notifications.md) ; secrets 48 h au plus.

## Décisions
Information des personnes en cas de message envoyé à tort : responsable de traitement / DPO.
