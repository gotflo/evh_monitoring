# Mise en ligne de la console de supervision

Procédure complète, dans l'ordre. Deux parties :

- **A. Plateforme** (espace.vasesdhonneurchicoutimi.org) : mettre en ligne l'agent de supervision et le secret partagé.
- **B. Console** (nouveau sous-domaine, par exemple console.vasesdhonneurchicoutimi.org) : installer ce projet.

Durée totale : environ 1 h 30. Chemins Hostinger de la plateforme :
`/home/u772952451/domains/vasesdhonneurchicoutimi.org/public_html/espace` (appelé **PLATEFORME** ci-dessous).

---

## 0. Préparer (sur votre ordinateur, 10 min)

1. **Générer le secret partagé** (64 caractères) et le garder de côté, il servira deux fois :

   ```bash
   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
   ```

2. **Construire l'interface de la console** :

   ```bash
   cd frontend
   npm ci
   npm audit
   npm run build
   ```

   Le résultat est dans `frontend/dist/` (`index.html`, `assets/`, `favicon.png`).

3. **Préparer le code Laravel de la console** (si vous n'avez pas Composer par SSH sur l'hébergement) :

   ```bash
   cd backend
   composer install --no-dev --optimize-autoloader
   ```

4. Faire deux archives :
   - `console-backend.zip` : tout le dossier `backend/` **sauf** `.env`, `database/*.sqlite`, `storage/logs/*`,
     `tests/` ;
   - `console-public.zip` : le contenu de `frontend/dist/`.

---

## A. Plateforme : agent de supervision (20 min)

L'agent fait partie du projet evh_platform (fichiers déjà prêts dans ce projet). Il ajoute :
la mesure des requêtes, la recopie des erreurs et journaux, la réception des erreurs des navigateurs, le blocage
des comptes, et l'API signée `/api/agent/*`. Détail : [AGENT-PLATEFORME.md](AGENT-PLATEFORME.md).

### A1. Sauvegardes

1. hPanel, Bases de données, **phpMyAdmin** : exporter la base de la plateforme (format SQL).
2. Gestionnaire de fichiers : compresser `public_html/espace`, télécharger l'archive, puis la supprimer du serveur.
3. Télécharger `espace/.env`.

### A2. Envoyer le code de la plateforme

Envoyer les fichiers modifiés ou ajoutés du backend de la plateforme (comme pour les mises à jour précédentes), en
particulier :

- `app/Http/Controllers/Api/AgentController.php`, `app/Http/Controllers/Api/ClientErrorController.php`
- `app/Http/Middleware/VerifyAgentSignature.php`, `MonitorRequests.php`, `EnsureNotBlocked.php`
- `app/Services/Monitoring/` (Monitor, HealthService, UserAdmin), `app/Support/Monitoring/` (Redactor, ServiceMap)
- `config/monitoring.php`, `config/auth.php`, `routes/api.php`, `routes/console.php`, `bootstrap/app.php`
- `database/migrations/2026_10_03_100001_add_blocking_to_users.php`,
  `database/migrations/2026_10_03_100002_create_monitoring_telemetry_tables.php`
- et l'interface membre recompilée (elle envoie les erreurs d'affichage à l'agent).

### A3. `.env` de la plateforme

Ajouter à la fin de `espace/.env` :

```
MONITOR_ENABLED=true
MONITOR_AGENT_SECRET=<le secret généré à l'étape 0>
```

Facultatif : `MONITOR_AGENT_ALLOWED_IPS=<IP du serveur de la console>` (si les deux sites sont sur le même
hébergement, l'IP est celle du serveur ; laisser vide en cas de doute).

### A4. Migrations et caches (SSH)

```bash
cd /home/u772952451/domains/vasesdhonneurchicoutimi.org/public_html/espace
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan event:cache
php artisan route:list --path=api/agent
```

Attendu : 11 routes `api/agent/...`. Vérifier aussi que la tâche cron de la plateforme existe toujours
(hPanel, Avancé, Tâches Cron) : `php artisan schedule:run` chaque minute dans le dossier `espace`.

---

## B. Console : nouveau sous-domaine (40 min)

### B1. Base de données de la console

hPanel, **Bases de données MySQL** : créer une base (par exemple `u772952451_console`) et son utilisateur, noter
le mot de passe.

### B2. Sous-domaine

hPanel, **Domaines**, **Sous-domaines** : créer `console` (ou un autre nom). Dossier personnalisé :
`public_html/console/public`. Attendre l'activation du certificat SSL (hPanel, **SSL**, l'installer si besoin).

### B3. Envoyer les fichiers

1. Gestionnaire de fichiers : créer `public_html/console`.
2. Y téléverser `console-backend.zip`, **Extraire** dans `console`, supprimer le zip.
3. Ouvrir `public_html/console/public`, y téléverser le contenu de `console-public.zip` (`index.html`, `assets/`,
   `favicon.png`) ; **`index.html` en dernier**.

Si Composer est disponible en SSH, vous pouvez envoyer le code sans `vendor/` et lancer ensuite
`composer install --no-dev --optimize-autoloader` dans `public_html/console`.

### B4. `.env` de la console

Copier `public_html/console/.env.production.example` en `public_html/console/.env` et remplir :

| Variable | Valeur |
|----------|--------|
| `APP_URL` | `https://console.vasesdhonneurchicoutimi.org` |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | base de la console (B1) |
| `APP_DB_DATABASE`, `APP_DB_USERNAME`, `APP_DB_PASSWORD` | **mêmes valeurs** que `DB_*` du `.env` de la plateforme |
| `PLATFORM_URL` | `https://espace.vasesdhonneurchicoutimi.org` |
| `MONITOR_AGENT_SECRET` | **le même secret** que sur la plateforme (étape 0) |
| `SMS_DRIVER`, `TWILIO_*` | `twilio_verify` et les mêmes identifiants Twilio que la plateforme |
| `MAIL_*`, `MONITOR_ALERT_EMAILS` | facultatif : alertes par courriel (boîte créée dans hPanel, SMTP `smtp.hostinger.com`, port 465) |
| `MONITOR_ALERT_WEBHOOK_URL` | facultatif : alertes Slack, Discord ou Teams |
| `GEOIP_PROVIDER`, `MONITOR_HOME_COUNTRIES` | localité des connexions (`ipwhois` par défaut, `none` pour désactiver) et pays habituels (`CA`) |

### B5. Installation (SSH)

```bash
cd /home/u772952451/domains/vasesdhonneurchicoutimi.org/public_html/console
php artisan key:generate --force
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan console:owner
php artisan monitor:check
```

Attendu : la migration crée le propriétaire principal **+14187181876** ; `console:owner` l'affiche ;
`monitor:check` affiche « État : ok » (ou « degraded » avec les détections).

Droits : `chmod -R 775 storage bootstrap/cache` et `chmod 640 .env`.

### B6. Tâche cron de la console

hPanel, Avancé, **Tâches Cron**, ajouter (chaque minute) :

```
cd /home/u772952451/domains/vasesdhonneurchicoutimi.org/public_html/console && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

C'est elle qui rend la console temps réel : vérification, enquêtes, actions automatiques, alertes, rapports.

---

## C. Vérifications (15 min)

1. `https://console.vasesdhonneurchicoutimi.org` : page de connexion de la console.
2. Saisir **418 718 1876** : le SMS arrive, la connexion se fait (le code ne doit pas s'afficher à l'écran).
3. Tableau de bord : « Plateforme (vue de l'extérieur) : En ligne », « Liaison sécurisée (agent) : Active »,
   « Lecture des mesures : Active ».
   - Liaison en échec : ouvrir l'incident « Liaison avec la plateforme », l'enquête indique la cause (secret absent,
     secrets différents, IP refusée, identifiants de base).
4. Dépannage, **Envoyer une alerte de test** : la notification arrive dans l'application des membres sur le compte
   portant le même numéro (et par courriel ou webhook s'ils sont configurés).
5. Sécurité : tous les contrôles « Conforme » ou « À savoir » ; lancer **Vérifier maintenant** pour les vulnérabilités.
6. Accès à la console : ajouter les autres personnes et leur rôle.
7. Après quelques minutes : graphiques et compteurs alimentés par l'activité réelle.
8. Conseillé : surveillance externe (UptimeRobot) de `https://espace.vasesdhonneurchicoutimi.org/api/health` et de
   `https://console.vasesdhonneurchicoutimi.org/up`.

---

## En cas de problème

| Symptôme | Cause probable | Correction |
|----------|----------------|------------|
| Page blanche ou erreur 500 sur la console | `.env` incomplet ou caches anciens | vérifier `.env`, puis `php artisan config:cache` |
| « Liaison sécurisée : En échec », HTTP 401 | secrets différents ou horloges décalées | même `MONITOR_AGENT_SECRET` des deux côtés, puis `config:cache` des deux côtés |
| « Liaison sécurisée : En échec », HTTP 503 | secret absent sur la plateforme | ajouter `MONITOR_AGENT_SECRET` au `.env` de la plateforme |
| « Lecture des mesures : Impossible » | identifiants `APP_DB_*` erronés | recopier les `DB_*` de la plateforme |
| Aucun SMS de connexion | `SMS_DRIVER` ou identifiants Twilio | vérifier `TWILIO_*` ; le journal `storage/logs` de la console donne l'erreur |
| Données qui ne bougent plus | cron de la console absent | tâche B6 |

Accès perdu (numéro du propriétaire changé) : en SSH, `php artisan console:owner +1XXXXXXXXXX`.

---

## Retour arrière

- **Console** : supprimer le sous-domaine et le dossier `public_html/console` ; la plateforme n'en dépend pas.
- **Agent de la plateforme** : retirer `MONITOR_AGENT_SECRET` du `.env` (l'API de contrôle se ferme), ou
  `MONITOR_ENABLED=false` (plus aucune mesure), puis `php artisan config:cache`. Retour complet :
  `php artisan migrate:rollback --step=2` puis remise des fichiers précédents.
