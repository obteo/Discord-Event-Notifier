<?php

use DiscordEventNotifier\Http\Controllers\TestWebhookController;
use Illuminate\Support\Facades\Route;

// POST /api/admin/extensions/discord-event-notifier/test
// The Panel protects admin extension routes with its administrator authentication middleware.
Route::post('/test', TestWebhookController::class);
