<?php

use Illuminate\Support\Facades\Schedule;

// Agent de supervision : sante de la plateforme, detection et enquete des incidents, actions
// automatiques, alertes, rapports et conservation. Cron de l'hebergeur : php artisan schedule:run
// chaque minute.
Schedule::command('monitor:check')->everyMinute()->withoutOverlapping(5);
