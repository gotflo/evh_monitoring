<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

/*
 * En production, Laravel sert l'interface React (build Vite copie dans public/).
 * Toutes les adresses hors /api renvoient index.html ; le routeur React prend le relais.
 */
Route::get('/{any}', function () {
    $index = public_path('index.html');
    abort_unless(File::exists($index), 404, 'Interface non deployee (index.html manquant).');

    return response(File::get($index), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
})->where('any', '^(?!api|up)(?!.*\.[A-Za-z0-9]{1,8}$).*$');
