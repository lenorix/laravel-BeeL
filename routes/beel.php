<?php

use Illuminate\Support\Facades\Route;
use Lenorix\LaravelBeel\Http\Controllers\BeelWebhookController;

if (config('beel.register_webhook_route', true)) {
    $route = Route::post(trim((string) config('beel.webhook_path', 'beel/webhook'), '/'), BeelWebhookController::class)
        ->name('beel.webhook');

    if (config('beel.webhook_rate_limit.max_attempts_per_minute') !== null) {
        $route->middleware('throttle:beel-webhook');
    }
}
