<?php

namespace DiscordEventNotifier\Http\Controllers;

use DiscordEventNotifier\Services\DiscordNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;

class TestWebhookController
{
    public function __invoke(DiscordNotifier $notifier): JsonResponse
    {
        $key = 'discord-event-notifier:test:'.auth()->id();
        abort_if(RateLimiter::tooManyAttempts($key, 3), 429, 'Too many test messages. Try again in one minute.');
        RateLimiter::hit($key, 60);
        try {
            $notifier->sendTest();
            return response()->json(['success' => true, 'message' => 'Test notification sent to Discord.']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Discord delivery failed: '.$e->getMessage()], 502);
        }
    }
}
