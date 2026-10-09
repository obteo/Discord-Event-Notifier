<?php

use DiscordEventNotifier\Http\Controllers\PersonalPreferencesController;
use Illuminate\Support\Facades\Route;

Route::get('/preferences', [PersonalPreferencesController::class, 'show']);
Route::put('/preferences', [PersonalPreferencesController::class, 'update']);
Route::post('/preferences/test', [PersonalPreferencesController::class, 'test']);
