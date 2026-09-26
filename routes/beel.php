<?php

use Illuminate\Support\Facades\Route;
use Lenorix\LaravelBeel\Http\Controllers\BeelWebhookController;

if (config('beel.register_webhook_route', true)) {
    // The optional {tenant} segment lets each tenant register its own URL with BeeL, so a custom
    // WebhookSecretResolver can pick the secret from trusted request metadata ($request->route('tenant')).
    Route::post(trim((string) config('beel.webhook_path', 'beel/webhook'), '/').'/{tenant?}', BeelWebhookController::class)
        ->name('beel.webhook');
}
