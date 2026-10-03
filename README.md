# Console de supervision My vasesdhonneur

Console de supervision et d'administration de la plateforme **My vasesdhonneur** (espace des membres de l'église
Vases d'Honneur Chicoutimi). C'est un **projet séparé** : son propre code, sa propre base de données, son propre
sous-domaine, sa propre connexion. Elle surveille la plateforme en continu, enquête automatiquement sur chaque
problème, propose ou exécute les actions de correction sûres, et garde la trace de tout ce qui est fait.

## Sommaire

1. [Ce que fait la console](#ce-que-fait-la-console)
2. [Architecture](#architecture)
3. [Liaison avec la plateforme](#liaison-avec-la-plateforme)
4. [Accès, rôles et sécurité](#accès-rôles-et-sécurité)
5. [Détection, enquête et actions automatiques](#détection-enquête-et-actions-automatiques)
6. [Écrans](#écrans)
7. [Données collectées et confidentialité](#données-collectées-et-confidentialité)
8. [Structure des fichiers](#structure-des-fichiers)
9. [Installation locale](#installation-locale)
10. [Variables d'environnement](#variables-denvironnement)
11. [Tests](#tests)
12. [Mise en ligne](#mise-en-ligne)
13. [Limites connues](#limites-connues)

## Ce que fait la console

- **Temps réel** : la vérification tourne chaque minute (cron) ; les écrans se mettent à jour seuls (tableau de bord
  et incidents toutes les 30 secondes, journaux en direct toutes les 10 secondes, seulement quand l'onglet est visible).
- **Disponibilité vue de l'extérieur** : la console appelle la page de santé publique de la plateforme depuis un autre
  sous-domaine. Elle détecte donc aussi un arrêt complet du serveur de la plateforme, ce qu'une console intégrée ne
  pourrait pas faire.
- **État de chaque service** : base de données, cache, stockage et disque, automatismes, cron, notifications push,
  SMS de connexion (Twilio Verify), courriel, liaison avec la plateforme.
- **Utilisation** : requêtes, membres actifs (jour, semaine, mois), connexions, nouveaux comptes, graphiques par
  période, par service et par type d'action.
- **Erreurs et performances** : journal central (serveur, navigateur, intégrations, tâches, base de données,
  sécurité), erreurs regroupées par cause, requêtes en échec ou lentes, routes les plus lentes, requêtes SQL lentes,
  fichiers journaux des deux serveurs.
- **Incidents** : 15 règles de détection aux seuils réglables, un incident par problème (jamais de doublon),
  **enquête automatique** (cause probable, preuves, actions à faire), **actions automatiques** sûres, alertes,
  résolution automatique quand le problème disparaît.
- **Administration des comptes membres** : recherche, état, appartenance, sessions, appareils ; bloquer, débloquer,
  fermer les sessions, supprimer (avec le résumé des conséquences et une confirmation explicite).
- **Localités de connexion** : pour chaque membre, ses dernières connexions et les localités d'où il se connecte
  (ville, région, pays d'après l'adresse IP) ; vue d'ensemble des localités dans Sécurité ; une connexion depuis un
  pays inhabituel ouvre un incident avec enquête (localités habituelles du membre, adresse, codes erronés).
- **Sécurité** : contrôles de configuration des deux projets, accès refusés, comportements suspects, vulnérabilités
  connues des paquets PHP des deux projets.
- **Rapports** : hebdomadaire, mensuel, quotidien en option, ou à la demande, avec les tendances.
- **Audit** : connexions à la console, changements d'accès, actions sur les comptes, incidents, réglages, dépannage
  et actions automatiques, avec la personne responsable, la date, la cible et le résultat.

## Architecture

```
                        Internet
                           |
        +------------------+-------------------+
        |                                      |
  espace.vasesdhonneurchicoutimi.org     console.vasesdhonneurchicoutimi.org
  PLATEFORME (projet evh_platform)       CONSOLE (ce projet, evh_monitoring)
  Laravel + React                        Laravel + React
        |                                      |
        |  1. GET /api/health (public) <-------+  disponibilité vue de l'extérieur
        |  2. /api/agent/* (signé)     <-------+  état détaillé et actions
        |                                      |
  base MySQL de la plateforme  <---------------+  3. lecture des mesures (connexion « app »)
  (comptes, audit, tables monitor_*)           |
                                         base MySQL de la console
                                         (accès, audit, incidents, rapports, réglages)
```

- **Backend** : Laravel 13 (PHP 8.3), API JSON sous `/api`, jetons Sanctum à durée limitée, tâches planifiées.
- **Frontend** : React 19 + TypeScript + Vite, servi par Laravel en production (un seul sous-domaine pour l'API et
  l'interface), graphiques SVG sans dépendance, interface claire et utilisable sur téléphone.
- **Deux bases** : la base de la console (ses propres données) et une connexion en lecture vers la base de la
  plateforme (`app`), la seule écriture étant la purge des anciennes mesures selon la durée de conservation.
- **Hébergement mutualisé** (Hostinger) : pas de worker permanent ni de websocket ; cron chaque minute et
  rafraîchissement régulier de l'interface.

## Liaison avec la plateforme

Trois liaisons, réglées par quelques variables d'environnement de chaque côté :

| Liaison | Sens | Utilisation | Protection |
|---------|------|-------------|------------|
| `GET {PLATFORM_URL}/api/health` | console vers plateforme | disponibilité, temps de réponse, état essentiel | page publique sans donnée sensible |
| `{PLATFORM_URL}/api/agent/*` | console vers plateforme | état détaillé, paquets installés, fichiers journaux, actions sur les comptes, actions de dépannage, alertes dans l'application | signature HMAC-SHA256 avec un secret partagé, horodatage (5 minutes de tolérance), anti-rejeu, liste d'IP facultative |
| base de la plateforme (`APP_DB_*`) | lecture | mesures `monitor_*`, comptes, appartenance, journal d'audit de l'église | identifiants MySQL dans le `.env` de la console |

**Signature d'une requête de l'agent** (identique des deux côtés) :

```
chaîne  = horodatage + "\n" + MÉTHODE + "\n" + chemin + "\n" + paramètres triés + "\n" + auteur + "\n" + sha256(corps)
en-têtes : X-Agent-Timestamp, X-Agent-Actor (personne de la console), X-Agent-Signature = HMAC-SHA256(secret, chaîne)
```

Le secret ne circule jamais. Une requête trop ancienne, déjà reçue ou modifiée est refusée. Les actions sont
exécutées **par la plateforme elle-même** (ses règles, ses contraintes de base de données et son journal d'audit
s'appliquent, avec le nom de la personne de la console) ; la console ne modifie jamais directement la base de la
plateforme.

Côté plateforme, l'**agent** (déjà intégré au projet evh_platform) mesure chaque requête, recopie les journaux et
erreurs, reçoit les erreurs des navigateurs, mesure Twilio et les notifications push, et expose l'API signée.

## Accès, rôles et sécurité

- À la mise en service, **un seul numéro est autorisé : +1 418 718 1876** (propriétaire principal), créé par la
  migration à partir de `CONSOLE_OWNER_PHONE`. Il invite ou retire les autres numéros depuis la console.
- **Connexion** : numéro autorisé + **code à usage unique par SMS** (Twilio Verify) ; 5 essais au plus par code ;
  limites par adresse IP. Un numéro non autorisé ne reçoit aucun SMS et voit la même réponse (on ne révèle pas quels
  numéros ont accès) ; la tentative est consignée.
- **Session** propre à la console, limitée à `CONSOLE_SESSION_HOURS` heures (12 par défaut).
- **Rôles** :

| Rôle | Droits |
|------|--------|
| Propriétaire | tout, y compris la gestion des accès, la durée de conservation et les actions sensibles |
| Administrateur | consultation, actions sur les comptes membres, incidents, dépannage, seuils, canaux et rapports |
| Lecture seule | consultation uniquement |

- Le **propriétaire principal** ne peut être ni retiré ni rétrogradé depuis la console ; il reste toujours au moins un
  propriétaire. Changement du propriétaire principal : uniquement en ligne de commande (`php artisan console:owner`).
- **Chaque route vérifie la session et le rôle côté serveur** (middleware `console:viewer|admin|owner`) ; masquer un
  bouton n'est jamais la protection. Toute action refusée est consignée.
- Aucun secret ni accès de secours dans le code : les identifiants sont dans le `.env`.
- En-têtes de sécurité, politique de sécurité du contenu, aucune indexation par les moteurs de recherche.

## Détection, enquête et actions automatiques

À chaque minute, `php artisan monitor:check` :

1. relève l'état de la plateforme vu de l'extérieur et par l'agent ;
2. évalue chaque règle active ;
3. ouvre ou met à jour l'incident correspondant (une clé par problème : jamais de doublon) ;
4. **enquête** : cause probable, niveau de confiance, preuves, actions recommandées ;
5. prévient par les canaux actifs (une fois par incident, de nouveau en cas d'aggravation) ;
6. exécute l'**action automatique** prévue pour la règle, si elle est activée (au plus une fois par quart d'heure
   et par incident), puis vérifie de nouveau à la minute suivante ;
7. résout automatiquement les incidents dont la condition a disparu (et prévient) ;
8. produit les rapports dus et applique la conservation (une fois par jour).

| Règle | Déclenchement par défaut | Enquête automatique | Action automatique |
|-------|--------------------------|---------------------|--------------------|
| Plateforme indisponible | 2 vérifications de suite sans réponse ou en erreur 5xx, ou base/cache en panne | distingue serveur web arrêté, base injoignable, application en erreur, hébergement entier arrêté | non |
| Plateforme lente | page de santé au-delà de 3 s, 3 fois de suite | part de la base de données, volume, routes lentes | non |
| Liaison avec la plateforme | API de l'agent ou lecture de la base en échec | secret absent, secrets différents, IP refusée, version trop ancienne, identifiants de base | non |
| Taux d'erreurs serveur | au moins 5 % de 5xx sur 15 min (critique au-delà de 25 %) | routes en échec, erreur dominante et son emplacement dans le code, mise en ligne récente | non |
| Erreur répétée | une même erreur 5 fois en 15 min | pages ou routes, appareils, membres touchés, emplacement | non |
| Forte hausse des échecs | 3 fois le niveau habituel, au moins 10 | limites 429 et adresses en cause, ou erreurs | non |
| Ralentissement | 95 % des requêtes au-delà de 1 s | base de données, pic d'activité, trop de requêtes SQL | non |
| Automatismes en retard ou en échec | pas de passage depuis 20 min, ou étape en échec | cron absent, étape en échec et son erreur | relancer les automatismes |
| Notifications push bloquées | envois en attente depuis 5 min | clés de chiffrement, envois en attente | renvoyer les notifications |
| Échecs des codes SMS | 3 échecs Twilio en 15 min | codes d'erreur Twilio expliqués (21608, 20003, 60410...) | non (diagnostic proposé) |
| Erreurs d'affichage | 10 erreurs JavaScript en 15 min | pages, appareils, pile d'appels | non |
| Espace disque ou stockage | moins de 10 % libre | taille des journaux, droits | non |
| Tentatives de connexion suspectes | 10 codes erronés en 15 min ou 15 demandes de code par heure depuis une IP | force brute ou envoi abusif de SMS, numéros visés, connexions réussies | non (blocage d'IP proposé) |
| Connexion depuis un pays inhabituel | connexion d'un membre hors des pays habituels (`MONITOR_HOME_COUNTRIES`, Canada par défaut) | première fois ou localité déjà connue, localités habituelles, codes erronés depuis l'adresse, sessions ouvertes | non (fermer les sessions ou bloquer proposé) |
| Accès refusés à la console | 3 refus en 15 min | numéros, adresses, actions refusées | non |

Toutes les actions automatiques sont désactivables règle par règle (Réglages), notées dans l'historique de
l'incident et dans l'audit au nom de « Agent automatique ». Les actions plus sensibles sont proposées sous forme de
bouton dans l'enquête, exécutées après confirmation.

**Canaux d'alerte** :

| Canal | État | Réglage |
|-------|------|---------|
| Application (cloche + push de la plateforme) | actif sans réglage | la personne de la console doit avoir un compte membre avec le même numéro |
| Courriel | prêt, inactif tant que non configuré | `MAIL_*` et `MONITOR_ALERT_EMAILS` |
| Webhook (Slack, Discord, Teams, n8n) | prêt, inactif tant que non configuré | `MONITOR_ALERT_WEBHOOK_URL`, `MONITOR_ALERT_WEBHOOK_FORMAT` |

Si la plateforme est arrêtée, le canal « application » ne peut pas fonctionner : courriel et webhook restent
utilisables (recommandé d'en configurer au moins un).

## Écrans

| Menu | Contenu |
|------|---------|
| Tableau de bord | état global, liaison, services, disponibilité 24 h / 7 j / 30 j, temps de réponse vu de l'extérieur, requêtes, erreurs, membres actifs, graphiques, incidents avec leur cause probable |
| Incidents et alertes | liste en temps réel, enquête, actions recommandées en un clic, prise en charge, notes, résolution |
| Journaux et erreurs | journal central filtrable et en direct, détail d'un événement et de sa requête, erreurs regroupées, requêtes en échec ou lentes, performances, fichiers des deux serveurs |
| Rapports | rapports automatiques et à la demande, impression |
| Activité | actions des membres et responsables, connexions, refus |
| Utilisateurs | recherche, fiche (dont localités et dernières connexions), bloquer, débloquer, fermer les sessions, supprimer |
| Dépannage | vérifier maintenant, relancer les automatismes, renvoyer les notifications, alerte de test, diagnostic SMS, recharger la configuration de la plateforme, appliquer la conservation |
| Sécurité | comportements à vérifier, contrôles des deux projets, accès refusés, codes de connexion, connexions à la console, comptes bloqués, vulnérabilités |
| Journal d'audit | toutes les actions de la console |
| Accès à la console | personnes autorisées, rôles, alertes, sessions |
| Réglages | canaux, rapports, règles, seuils et actions automatiques, conservation, liaison |

## Données collectées et confidentialité

- Mesures sur la plateforme : compteurs par tranche de 5 minutes avec des **routes génériques**
  (`api/admin/members/{user}`), détail des seules requêtes notables (erreurs, refus, limites, lenteurs), journaux à
  partir du niveau « warning », erreurs des navigateurs (chemin de la page, sans paramètres), appels Twilio et push.
- **Jamais enregistrés** : codes de connexion, jetons, mots de passe, clés, corps des requêtes, paramètres d'URL,
  contenu des FISS, journaux spirituels, demandes ou notes. Masquage automatique avant tout enregistrement ou affichage
  (numéros réduits aux 4 derniers chiffres, codes, jetons, identifiants Twilio, valeurs SQL, courriels).
- Chaque réponse de la plateforme porte un identifiant `X-Request-Id` qui relie une erreur à sa requête.
- **Localités** : les adresses IP publiques des connexions sont envoyées au service de localisation choisi
  (`GEOIP_PROVIDER` : `ipwhois` par défaut, en HTTPS et sans clé ; `ipapi` ; ou `none` pour désactiver). Les adresses
  du réseau local ne sont jamais envoyées. Résultat mis en cache 30 jours, précision de l'ordre de la ville (souvent
  celle du fournisseur d'accès).
- **Conservation** réglable par le propriétaire : journaux 30 jours, mesures 90 jours, audit et rapports 365 jours
  par défaut. Filet de sécurité côté plateforme : mesures supprimées au-delà de 120 jours de toute façon.

## Structure des fichiers

```
evh_monitoring/
- README.md                       ce document
- docs/
    - MISE-EN-LIGNE.md            procédure complète de mise en ligne (console + agent de la plateforme)
    - AGENT-PLATEFORME.md         ce que l'agent ajoute à la plateforme et son API signée
- backend/                        Laravel 13 (API + tâches planifiées)
    - app/
        - Console/Commands/
            - MonitorCheck.php    vérification chaque minute (détection, enquête, actions, rapports)
            - ConsoleOwner.php    propriétaire principal en ligne de commande
        - Http/
            - Controllers/Api/    Auth, Overview, Activity, User, Log, Incident, Report,
                                    Security, Action, Access, Settings, Audit
            - Middleware/         ConsoleAccess (session + rôle), SecurityHeaders
        - Models/                 Operator, AuditLog, Incident, Report, OtpCode
        - Services/
            - AgentClient.php     appels signés à l'API de la plateforme
            - PlatformHealth.php  état vu de l'extérieur, par l'agent et par la base
            - AlertEngine.php     règles, incidents, actions automatiques, résolution
            - Investigator.php    enquête automatique (cause, preuves, actions)
            - AlertNotifier.php   canaux application, courriel, webhook
            - Metrics.php         lecture des mesures de la plateforme
            - ReportBuilder.php   rapports périodiques
            - SecurityChecks.php  contrôles et vulnérabilités (Packagist)
        - GeoLocator.php      localité des connexions (cache, service configurable)
            - MonitorSettings.php règles, seuils, conservation, canaux
            - Retention.php       conservation
            - OtpService.php      codes de connexion
            - Sms/                client Twilio Verify
        - Support/                Audit, Redactor (masquage), ServiceMap, PlatformAudit, Once, Phone, Like
    - config/monitoring.php       réglages de la console et de la liaison
    - database/migrations/        accès, audit, incidents, rapports, réglages, disponibilité, localités
    - routes/api.php              API de la console (rôles par groupe de routes)
    - routes/console.php          planification (monitor:check chaque minute)
    - tests/                      tests de bout en bout (plateforme simulée, signature vérifiée)
    - .env.example                développement
    - .env.production.example     production (modèle commenté)
- frontend/                       React + TypeScript + Vite
    - src/
        - App.tsx                 connexion, menu, routes
        - api.ts, auth.tsx        client de l'API, session et rôles
        - format.ts, ui.tsx       formats, données en temps réel, composants
        - components/             graphiques, sélecteur de pays, notifications
        - pages/                  un fichier par écran
        - styles.css              interface claire et responsive
```

## Installation locale

Prérequis : PHP 8.3 (extensions pdo_sqlite ou pdo_mysql, openssl, mbstring), Composer, Node 18 ou plus, et la
plateforme evh_platform qui tourne en local (par défaut sur `http://127.0.0.1:8000`).

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan serve --port=8001
```

Dans `backend/.env`, renseigner `APP_DB_*` (base de la plateforme), `PLATFORM_URL` et `MONITOR_AGENT_SECRET`
(même valeur que dans le `.env` de la plateforme). En local, `SMS_DRIVER=log` affiche le code à l'écran.

```bash
cd frontend
npm install
npm run dev            # http://localhost:5174 (les appels /api vont vers le port 8001)
```

Vérification chaque minute en local : `php artisan schedule:work` (ou `php artisan monitor:check` à la main).

## Variables d'environnement

| Variable | Rôle |
|----------|------|
| `APP_URL` | adresse de la console (https en production) |
| `DB_*` | base de la console |
| `APP_DB_CONNECTION`, `APP_DB_HOST`, `APP_DB_PORT`, `APP_DB_DATABASE`, `APP_DB_USERNAME`, `APP_DB_PASSWORD` | base de la plateforme (lecture des mesures) |
| `PLATFORM_URL` | adresse de la plateforme, sans / final |
| `MONITOR_AGENT_SECRET` | secret partagé avec la plateforme, 32 caractères ou plus, identique des deux côtés |
| `PLATFORM_TIMEOUT`, `PLATFORM_SLOW_HEALTH_MS` | délai maximal d'une réponse de la plateforme (s), seuil de lenteur (ms) |
| `CONSOLE_OWNER_PHONE` | propriétaire principal à la mise en service (`+14187181876`) |
| `CONSOLE_SESSION_HOURS` | durée d'une session (12 par défaut) |
| `SMS_DRIVER`, `TWILIO_*` | codes de connexion : `twilio_verify` en production (mêmes identifiants que la plateforme possible) |
| `MONITOR_RETENTION_DAYS`, `MONITOR_METRICS_RETENTION_DAYS`, `CONSOLE_AUDIT_RETENTION_DAYS` | conservation par défaut |
| `MONITOR_ALERT_EMAILS`, `MAIL_*` | alertes par courriel |
| `MONITOR_ALERT_WEBHOOK_URL`, `MONITOR_ALERT_WEBHOOK_FORMAT` | alertes par webhook |
| `MONITOR_VULNERABILITY_CHECK` | vérification Packagist à la demande |
| `GEOIP_PROVIDER` | localité des connexions : `ipwhois` (défaut), `ipapi` ou `none` |
| `MONITOR_HOME_COUNTRIES` | pays habituels (codes ISO séparés par des virgules, `CA` par défaut) |

Côté plateforme : `MONITOR_AGENT_SECRET` (le même), et en option `MONITOR_AGENT_ALLOWED_IPS`, `MONITOR_ENABLED`,
`MONITOR_SLOW_REQUEST_MS`, `MONITOR_SLOW_QUERY_MS`, `MONITOR_LOG_LEVEL`, `MONITOR_AGENT_MAX_DAYS`.

## Tests

```bash
cd backend && php artisan test     # accès, rôles, actions signées, détection, enquête, actions automatiques, panne
cd frontend && npx tsc -b && npx eslint src && npm run build
```

Les tests simulent la plateforme (page de santé et API de l'agent) en vérifiant la signature exactement comme la
plateforme le fait, sur une base de test reproduisant le schéma de la plateforme. Côté plateforme, les tests
`MonitoringAgentTest` vérifient l'agent (signature valide, falsifiée, rejouée, périmée ; actions ; collecte ; masquage).

## Mise en ligne

Procédure détaillée pas à pas : [`docs/MISE-EN-LIGNE.md`](docs/MISE-EN-LIGNE.md).

## Limites connues

- Hébergement mutualisé : pas de connexion permanente (websocket) ; le temps réel repose sur la vérification chaque
  minute et le rafraîchissement automatique de l'interface.
- Si la console et la plateforme sont sur le même serveur, une panne de l'hébergement entier arrête les deux :
  garder une surveillance externe (par exemple UptimeRobot) de la page de santé de la plateforme.
- Le 95e centile des temps de réponse est estimé à partir de tranches de durée.
- Les mesures commencent à la mise en service de l'agent sur la plateforme.
- Les tentatives bloquées par le pare-feu de l'hébergeur n'atteignent pas l'application et ne sont pas visibles.
- Les dépendances npm ne sont pas sur les serveurs : lancer `npm audit` avant une mise en ligne.
- Sauvegardes des bases : gérées par l'hébergeur, non vérifiables depuis la console.
- Pas d'alerte par SMS : Twilio Verify ne sert qu'aux codes de connexion.
- Localité par adresse IP : approximative (ville du fournisseur d'accès, réseau mobile, VPN) ; à confirmer avec la
  personne avant toute décision.
