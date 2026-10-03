# Agent de supervision de la plateforme

La console est un projet séparé. Côté plateforme (projet evh_platform), un petit **agent** fournit les mesures et
exécute les actions décidées dans la console. Il n'a aucun écran : tout se consulte dans la console.

## Ce que l'agent ajoute à la plateforme

| Élément | Rôle |
|---------|------|
| `app/Http/Middleware/MonitorRequests.php` | identifiant `X-Request-Id` sur chaque réponse de l'API, mesure de chaque requête après l'envoi de la réponse |
| `app/Services/Monitoring/Monitor.php` | écriture des mesures : compteurs, requêtes notables, journal central, jours d'activité, intégrations, tâches ; nettoyage de sécurité |
| `app/Services/Monitoring/HealthService.php` | état de santé (partagé avec `/api/health`) et version détaillée pour la console |
| `app/Services/Monitoring/UserAdmin.php` | blocage, déblocage, fermeture des sessions, suppression d'un compte avec le résumé des conséquences |
| `app/Support/Monitoring/Redactor.php` | masquage des numéros, codes, jetons, valeurs SQL, paramètres d'URL |
| `app/Support/Monitoring/ServiceMap.php` | classement des routes par service |
| `app/Http/Controllers/Api/ClientErrorController.php` | réception des erreurs d'affichage des navigateurs (`POST /api/monitor/client-errors`) |
| `app/Http/Middleware/EnsureNotBlocked.php` | un compte bloqué ne peut plus rien faire |
| `app/Http/Middleware/VerifyAgentSignature.php` | vérification de la signature des appels de la console |
| `app/Http/Controllers/Api/AgentController.php` | API de contrôle `/api/agent/*` |
| `config/monitoring.php` | réglages de l'agent |
| migrations `2026_10_03_100001` et `2026_10_03_100002` | colonnes de blocage, tables `monitor_*` |
| `frontend/src/monitoring.ts` | envoi des erreurs d'affichage (message, extrait de pile, chemin de la page) |

Instrumentation existante : connexion des membres (codes demandés, erronés, connexions, comptes bloqués), Twilio
Verify (appels réussis ou en échec), boîte d'envoi des notifications push, automatismes (`app:tick`).

## API de contrôle (`/api/agent/*`)

Toutes les routes exigent une signature valide (voir le README, « Liaison avec la plateforme »). Limite : 120
requêtes par minute et par adresse.

| Méthode et chemin | Effet |
|-------------------|-------|
| `GET /health` | état détaillé : services, intégrations, configuration (indicateurs seulement, jamais de secret), versions, heure, date de mise en ligne |
| `GET /packages` | paquets PHP installés et leur version (vérification des vulnérabilités par la console) |
| `GET /logs/files`, `GET /logs/files/{nom}` | fichiers journaux du serveur, masqués à la source |
| `GET /users/{id}/impact` | conséquences d'une suppression |
| `POST /users/{id}/block` | bloquer (motif obligatoire) : sessions fermées, appareils retirés, plus de code envoyé |
| `POST /users/{id}/unblock` | débloquer |
| `POST /users/{id}/revoke-sessions` | fermer les sessions |
| `DELETE /users/{id}` | supprimer (contraintes de la base respectées) |
| `POST /actions/run_automation` | lancer les automatismes |
| `POST /actions/flush_push` | renvoyer les notifications en attente |
| `POST /actions/sms_check` | diagnostic Twilio Verify sans envoi |
| `POST /actions/clear_config` | vider les caches de configuration, de routes et de vues |
| `POST /notify` | alerte dans l'application (cloche et push) aux comptes portant les numéros donnés |

Chaque action sur un compte est aussi inscrite dans le journal d'audit de la plateforme, avec la mention
« console de supervision » et le nom de la personne de la console.

## Variables d'environnement de la plateforme

| Variable | Défaut | Rôle |
|----------|--------|------|
| `MONITOR_ENABLED` | `true` | collecte des mesures |
| `MONITOR_AGENT_SECRET` | vide | secret partagé avec la console ; vide = API de contrôle fermée |
| `MONITOR_AGENT_ALLOWED_IPS` | vide | adresses autorisées à appeler l'API (vide = toutes) |
| `MONITOR_AGENT_MAX_SKEW` | `300` | écart maximal d'horloge accepté (secondes) |
| `MONITOR_SLOW_REQUEST_MS` | `1500` | requête comptée lente et conservée en détail |
| `MONITOR_SLOW_QUERY_MS` | `500` | requête SQL lente journalisée (sans les valeurs) |
| `MONITOR_LOG_LEVEL` | `warning` | niveau minimal des journaux recopiés |
| `MONITOR_AGENT_MAX_DAYS` | `120` | au-delà, les mesures sont supprimées par la plateforme elle-même |
