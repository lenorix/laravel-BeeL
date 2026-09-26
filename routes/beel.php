<?php

use Illuminate\Support\Facades\Route;
use Lenorix\LaravelBeel\Http\Controllers\BeelWebhookController;

if (config('beel.register_webhook_route', true)) {
    Route::post(trim((string) config('beel.webhook_path', 'beel/webhook'), '/'), BeelWebhookController::class)
        ->name('beel.webhook');
}
