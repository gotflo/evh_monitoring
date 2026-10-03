<?php

use App\Http\Controllers\Api\AccessController;
use App\Http\Controllers\Api\ActionController;
use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\IncidentController;
use App\Http\Controllers\Api\LogController;
use App\Http\Controllers\Api\OverviewController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SecurityController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

// --- Connexion : numero autorise + code a usage unique ---
Route::post('/auth/request-code', [AuthController::class, 'requestCode'])->middleware('throttle:console-code');
Route::post('/auth/verify', [AuthController::class, 'verify'])->middleware('throttle:console-verify');

// --- Console : chaque route verifie la session ET le role (console:viewer|admin|owner) ---
Route::middleware(['auth:console', 'throttle:console'])->group(function () {
    Route::middleware('console:viewer')->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/overview', OverviewController::class);
        Route::get('/activity', [ActivityController::class, 'index']);
        Route::get('/users', [UserController::class, 'index']);
        Route::get('/users/{user}', [UserController::class, 'show'])->whereNumber('user');
        Route::get('/logs', [LogController::class, 'index']);
        Route::get('/logs/errors', [LogController::class, 'errors']);
        Route::get('/logs/requests', [LogController::class, 'requests']);
        Route::get('/logs/performance', [LogController::class, 'performance']);
        Route::get('/logs/{id}', [LogController::class, 'show'])->whereNumber('id');
        Route::get('/incidents', [IncidentController::class, 'index']);
        Route::get('/incidents/{incident}', [IncidentController::class, 'show'])->whereNumber('incident');
        Route::get('/reports', [ReportController::class, 'index']);
        Route::get('/reports/{report}', [ReportController::class, 'show'])->whereNumber('report');
        Route::get('/security', [SecurityController::class, 'index']);
        Route::get('/settings', [SettingsController::class, 'show']);
        Route::get('/audit', [AuditController::class, 'index']);
        Route::get('/actions', [ActionController::class, 'index']);
    });

    Route::middleware('console:admin')->group(function () {
        Route::get('/users/{user}/impact', [UserController::class, 'impact'])->whereNumber('user');
        Route::post('/users/{user}/block', [UserController::class, 'block'])->whereNumber('user');
        Route::post('/users/{user}/unblock', [UserController::class, 'unblock'])->whereNumber('user');
        Route::post('/users/{user}/revoke-sessions', [UserController::class, 'revokeSessions'])->whereNumber('user');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->whereNumber('user');
        Route::get('/logs/files', [LogController::class, 'files']);
        Route::get('/logs/files/{name}', [LogController::class, 'file'])->where('name', '[A-Za-z0-9._-]+');
        Route::post('/incidents/{incident}/acknowledge', [IncidentController::class, 'acknowledge'])->whereNumber('incident');
        Route::post('/incidents/{incident}/resolve', [IncidentController::class, 'resolve'])->whereNumber('incident');
        Route::post('/incidents/{incident}/reopen', [IncidentController::class, 'reopen'])->whereNumber('incident');
        Route::post('/incidents/{incident}/notes', [IncidentController::class, 'note'])->whereNumber('incident');
        Route::post('/incidents/{incident}/investigate', [IncidentController::class, 'investigate'])->whereNumber('incident');
        Route::post('/reports', [ReportController::class, 'generate']);
        Route::post('/security/vulnerabilities', [SecurityController::class, 'checkVulnerabilities'])->middleware('throttle:console-vulnerabilities');
        Route::put('/settings/rules', [SettingsController::class, 'updateRules']);
        Route::put('/settings/notifications', [SettingsController::class, 'updateNotifications']);
        // Role exact verifie dans le controleur (certaines actions : proprietaire).
        Route::post('/actions/{action}', [ActionController::class, 'run'])->where('action', '[a-z_]+')->middleware('throttle:console-actions');
        Route::get('/access', [AccessController::class, 'index']);
    });

    Route::middleware('console:owner')->group(function () {
        Route::post('/access', [AccessController::class, 'store']);
        Route::patch('/access/{target}', [AccessController::class, 'update'])->whereNumber('target');
        Route::delete('/access/{target}', [AccessController::class, 'destroy'])->whereNumber('target');
        Route::post('/access/{target}/revoke-sessions', [AccessController::class, 'revokeSessions'])->whereNumber('target');
        Route::put('/settings/retention', [SettingsController::class, 'updateRetention']);
    });
});
