<?php

use Illuminate\Support\Facades\Route;
use Lenorix\LaravelBeel\Http\Controllers\BeelWebhookController;

if (config('beel.register_webhook_route', true)) {
    // The optional segment lets each tenant register its own URL with BeeL, so a custom
    // WebhookSecretResolver can pick the secret from trusted request metadata. Package-specific name
    // so it never collides with an app's own route bindings (e.g. a global {tenant} binding).
    Route::post(trim((string) config('beel.webhook_path', 'beel/webhook'), '/').'/{beelWebhookKey?}', BeelWebhookController::class)
        ->name('beel.webhook');
}
