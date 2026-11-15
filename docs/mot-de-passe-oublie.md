# Mot de passe oublié

Chaque compte peut enregistrer une adresse e-mail dans **Mon compte**. En cas d'oubli, le lien
**« Mot de passe oublié ? »** de la page de connexion envoie un lien de réinitialisation à cette adresse.

## Sécurité

- Le lien est valable **30 minutes**, une seule fois ; une nouvelle demande annule le précédent.
- Le lien **ne suffit pas** : le code de double authentification (ou un code de secours) est demandé
  pour choisir le nouveau mot de passe. Quelqu'un qui pirate la boîte mail ne peut pas prendre le compte.
- Refusé pour un compte qui n'a pas encore activé sa double authentification.
- Même réponse que le compte existe ou non ; mail envoyé après la réponse (pas d'écart de durée).
- Limites : 5 demandes par IP et 3 par compte en 15 minutes ; un lien inventé compte comme un essai raté
  (blocage progressif de l'IP) ; 5 codes 2FA faux annulent le lien.
- Jeton stocké haché (HMAC avec la clé du portail), jamais en clair.
- Après le changement : toutes les sessions sont déconnectées, alerte ntfy et mail de confirmation.

## Réglages

Dans `config.local.php` (jamais versionné) :

```php
const SMTP_HOST = 'ssl0.ovh.net';
const SMTP_PORT = 465;                      // 465 = SSL/TLS, 587 = STARTTLS
const SMTP_USER = 'noreply@leo44.cloud';
const SMTP_PASS = 'le mot de passe de la boîte';
const SMTP_FROM = 'noreply@leo44.cloud';
const APP_URL   = 'https://leo44.cloud';    // adresse utilisée dans le lien envoyé
```

Sans ces réglages, la page « Mot de passe oublié » indique que la fonction n'est pas activée.
L'envoi utilise uniquement des connexions chiffrées avec vérification du certificat du serveur mail.
