<?php

use DiscordEventNotifier\Http\Controllers\WingsEventController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

// Public ingress only because the Wings service uses signed, timestamped HMAC messages.
// No user sessions, API keys, or public secrets are used for this endpoint.
Route::post('/wings-event', WingsEventController::class)
    ->withoutMiddleware([PreventRequestForgery::class]);
