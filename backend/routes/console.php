<?php

use Illuminate\Support\Facades\Schedule;

// Agent de supervision : sante de la plateforme, detection et enquete des incidents, actions
// automatiques, alertes, rapports et conservation. Cron de l'hebergeur chaque minute :
// php artisan monitor:check --cron (lancement direct : schedule:run a besoin de proc_open, que
// certains hebergeurs desactivent ; monitor:check se protege lui-meme des passages en double).
Schedule::command('monitor:check --cron')->everyMinute()->withoutOverlapping(5);
