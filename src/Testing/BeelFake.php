<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Testing;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Factory;

/**
 * For your app's tests: BeeL API responses and resources shaped like the real API (taken from
 * BeeL's OpenAPI examples), to use with `Http::fake()`.
 *
 *     Http::fake([
 *         '*\/invoices/*' => BeelFake::ok(BeelFake::invoice(['status' => 'DRAFT'])),
 *         '*\/customers*' => BeelFake::page('customers', [BeelFake::customer()]),
 *         '*\/issue' => BeelFake::error(422, 'EMISSION_NOT_READY'),
 *     ]);
 *
 * Resource factories return arrays; `$overrides` are merged into nested objects, while lists
 * (e.g. `lines`, `events`, `scopes`) are replaced as a whole.
 */
final class BeelFake
{
    public const REQUEST_ID = 'f4a5b6c7-d8e9-4f0a-9b2c-3d4e5f6a7b8c';

    /** A successful response: `{success: true, data: ..., meta}`. */
    public static function ok(array $data, int $status = 200): PromiseInterface
    {
        return Factory::response(['success' => true, 'data' => $data, 'meta' => self::meta()], $status);
    }

    /**
     * One page of a paginated list: `data` holds `$key` (e.g. `invoices`, `customers`, `webhooks`,
     * `deliveries`) and `pagination`.
     *
     * @param  list<array<string, mixed>>  $items
     */
    public static function page(string $key, array $items, bool $hasNext = false, int $page = 1, int $perPage = 20): PromiseInterface
    {
        $total = ($page - 1) * $perPage + count($items) + ($hasNext ? 1 : 0);

        return self::ok([
            $key => $items,
            'pagination' => [
                'current_page' => $page,
                'total_pages' => $hasNext ? $page + 1 : $page,
                'total_items' => $total,
                'items_per_page' => $perPage,
                'has_next' => $hasNext,
                'has_previous' => $page > 1,
            ],
        ]);
    }

    /**
     * One page of a cursor-paginated list (e.g. `accounts`).
     *
     * @param  list<array<string, mixed>>  $items
     */
    public static function cursorPage(string $key, array $items, ?string $nextCursor = null, ?string $prevCursor = null): PromiseInterface
    {
        return self::ok([$key => $items, 'next_cursor' => $nextCursor, 'prev_cursor' => $prevCursor]);
    }

    /**
     * An error response in BeeL's format, which the SDK turns into the matching `BeelApiError`
     * subclass with `apiCode`, `details` and `requestId` set. `$retryAfter` sets the Retry-After
     * header (seconds), as BeeL does on 429.
     *
     * The package retries 429 and 5xx (`beel.http.retries`), so a test faking one of those gets
     * as many attempts as retries configured: use `Sleep::fake()` to skip the waits, or set
     * `beel.http.retries` to 0 to see the error on the first call.
     *
     * @param  array<string, mixed>  $details
     */
    public static function error(int $status, string $code, ?string $message = null, array $details = [], ?int $retryAfter = null): PromiseInterface
    {
        $message ??= ucfirst(strtolower(str_replace('_', ' ', $code))).'.';
        $error = ['code' => $code, 'message' => $message];
        if ($details !== []) {
            $error['details'] = $details;
        }

        return Factory::response([
            'success' => false,
            'error' => $error,
            'meta' => self::meta(),
            'type' => "https://docs.beel.es/errors/{$code}",
            'title' => $message,
            'status' => $status,
        ], $status, $retryAfter === null ? [] : ['Retry-After' => (string) $retryAfter]);
    }

    /** An issued invoice with VERI*FACTU accepted, like `GET /v1/companies/{id}/invoices/{id}`. */
    public static function invoice(array $overrides = []): array
    {
        return self::merge([
            'id' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479',
            'invoice_number' => 'A/2025/0042',
            'series' => ['id' => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890', 'code' => 'A'],
            'number' => 42,
            'type' => 'STANDARD',
            'status' => 'ISSUED',
            'issue_date' => '2025-01-20',
            'due_date' => '2025-02-20',
            'issuer' => [
                'legal_name' => 'Tu Empresa SL',
                'nif' => 'B12345674',
                'address' => ['street' => 'Calle Ejemplo', 'number' => '123', 'postal_code' => '28001', 'city' => 'Madrid', 'province' => 'Madrid', 'country' => 'España'],
                'email' => 'info@tuempresa.es',
            ],
            'recipient' => [
                'customer_id' => '123e4567-e89b-12d3-a456-426614174000',
                'legal_name' => 'Cliente Ejemplo SL',
                'nif' => 'B87654321',
                'email' => 'cliente@ejemplo.com',
                'address' => ['street' => 'Avenida Cliente', 'number' => '456', 'postal_code' => '28013', 'city' => 'Madrid', 'province' => 'Madrid', 'country' => 'España'],
            ],
            'lines' => [[
                'description' => 'Corporate website development',
                'quantity' => 40,
                'unit' => 'hours',
                'unit_price' => 37.5,
                'discount_percentage' => 0,
                'taxable_base' => 1500,
                'main_tax' => ['type' => 'IVA', 'percentage' => 21, 'regime_key' => '01'],
                'irpf_rate' => 15,
                'line_total' => 1590,
            ]],
            'totals' => [
                'taxable_base' => 1500,
                'total_vat' => 315,
                'total_irpf' => 225,
                'total_equivalence_surcharge' => 0,
                'vat_breakdown' => [['type' => 21, 'base' => 1500, 'amount' => 315]],
                'irpf_breakdown' => [['type' => 15, 'base' => 1500, 'amount' => 225]],
                'invoice_total' => 1590,
            ],
            'verifactu' => [
                'enabled' => true,
                'invoice_hash' => 'a7f3c9e2b1d4f8a6c3e9b2d5f1a8c4e7b9d2f5a1c8e4b7d3f9a2c6e1b5d8f4a7',
                'qr_url' => 'https://verifactu.agenciatributaria.gob.es/v?id=a7f3c9e2b1d4f8a6',
                'submission_status' => 'ACCEPTED',
            ],
            'created_at' => '2025-01-20T10:30:00Z',
            'updated_at' => '2025-01-20T10:35:00Z',
        ], $overrides);
    }

    /** A customer, like `GET /v1/companies/{id}/customers/{id}`. */
    public static function customer(array $overrides = []): array
    {
        return self::merge([
            'id' => '123e4567-e89b-12d3-a456-426614174000',
            'legal_name' => 'Tech Solutions SL',
            'nif' => 'B12345674',
            'email' => 'admin@techsolutions.com',
            'phone' => '+34912345678',
            'address' => ['street' => 'Calle Mayor', 'number' => '123', 'postal_code' => '28013', 'city' => 'Madrid', 'province' => 'Madrid', 'country' => 'España'],
            'active' => true,
            'created_at' => '2024-03-15T09:00:00Z',
            'updated_at' => '2025-01-20T10:00:00Z',
        ], $overrides);
    }

    /**
     * The API key's identity, like `GET /v1/me/identity`.
     *
     * @param  list<string>|null  $scopes  Replaces the default scopes when given.
     */
    public static function identity(array $overrides = [], ?array $scopes = null): array
    {
        $identity = self::merge([
            'account_id' => '9b2e4c1a-7d3f-4e8b-a6c5-1f0d2e3b4a5c',
            'name' => 'Tu Empresa SL',
            'email' => 'owner@tuempresa.es',
            'language' => 'es',
            'credential' => [
                'type' => 'api_key',
                'environment' => 'TEST',
                'scopes' => ['invoices:read', 'invoices:write', 'customers:read', 'customers:write', 'webhooks:read', 'webhooks:write'],
            ],
        ], $overrides);

        if ($scopes !== null) {
            $identity['credential']['scopes'] = $scopes;
        }

        return $identity;
    }

    /**
     * A company's issuing readiness, like `GET /v1/companies/{id}/issuing-readiness`.
     *
     * @param  list<string>  $blockers  Not ready when non-empty (e.g. `REPRESENTATION_NOT_SIGNED`).
     */
    public static function issuingReadiness(array $blockers = []): array
    {
        return ['ready' => $blockers === [], 'blockers' => $blockers];
    }

    /** An account you provisioned as an integrator, as listed by `GET /v1/accounts` (cursor-paginated). */
    public static function managedAccount(array $overrides = []): array
    {
        return self::merge([
            'account_id' => '9b2e4c1a-7d3f-4e8b-a6c5-1f0d2e3b4a5c',
            'external_ref' => 'acct-2041',
            'display_name' => 'Cliente Ejemplo SL',
            'access_level' => 'OPERATE',
            'status' => 'ACTIVE',
            'claim' => ['status' => 'CLAIMED'],
            'company_id' => '7c9e6679-7425-40de-944b-e07fc1f90ae7',
            'representation_signed' => true,
            'created_at' => '2025-01-15T09:00:00Z',
        ], $overrides);
    }

    /** A webhook subscription, as listed by `GET /v1/accounts/{id}/webhooks` (never with its secret). */
    public static function webhookSubscription(array $overrides = []): array
    {
        return self::merge([
            'id' => '5d6e7f80-91a2-4b3c-8d4e-5f60718293a4',
            'url' => 'https://app.test/beel/webhook',
            'events' => ['invoice.issued', 'verifactu.status.updated'],
            'active' => true,
            'consecutive_failures' => 0,
            'created_at' => '2025-01-10T09:00:00Z',
        ], $overrides);
    }

    /** One delivery attempt of a subscription, as listed by `GET /v1/accounts/{id}/webhooks/{id}/deliveries`. */
    public static function webhookDelivery(array $overrides = []): array
    {
        return self::merge([
            'id' => '0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d',
            'subscription_id' => '5d6e7f80-91a2-4b3c-8d4e-5f60718293a4',
            'webhook_event_id' => '3f7a1b2c-4d5e-6f7a-8b9c-0d1e2f3a4b5c',
            'event_type' => 'invoice.issued',
            'attempt_number' => 1,
            'http_status' => 202,
            'success' => true,
            'duration_ms' => 120,
            'delivered_at' => '2025-01-20T10:35:02Z',
        ], $overrides);
    }

    /**
     * A realistic `data` for a webhook event type, e.g. to pass to `postBeelWebhook()` (which uses
     * it by default). Unknown types get an empty array.
     */
    public static function webhookData(string $type, array $overrides = []): array
    {
        $data = match ($type) {
            'invoice.issued' => ['invoice_id' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479', 'invoice_number' => 'A/2025/0042', 'customer_email' => 'cliente@ejemplo.com', 'customer_name' => 'Cliente Ejemplo SL'],
            'invoice.email.sent' => ['invoice_id' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479', 'invoice_number' => 'A/2025/0042', 'all_recipients' => ['cliente@ejemplo.com'], 'sent_at' => '2025-01-20T10:36:00Z'],
            'invoice.pdf.generated' => ['invoice_id' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479', 'invoice_number' => 'A/2025/0042'],
            'invoice.voided' => ['invoice_id' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479', 'invoice_number' => 'A/2025/0042', 'cancellation_reason' => 'Issued by mistake'],
            'invoice.schedule_failed' => ['invoice_id' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479', 'invoice_number' => null, 'customer_name' => 'Cliente Ejemplo SL', 'scheduled_for' => '2025-01-20', 'blocker' => 'NIF_NOT_REGISTERED'],
            'recurring_invoice.paused' => ['recurring_invoice_id' => '7a8b9c0d-1e2f-4a3b-8c4d-5e6f7a8b9c0d', 'name' => 'Cuota mensual mantenimiento', 'reason' => 'GENERATION_FAILURE', 'blocker' => 'NIF_NOT_REGISTERED', 'since' => '2025-01-20T08:00:00Z'],
            'verifactu.status.updated' => ['invoice_id' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479', 'invoice_number' => 'A/2025/0042', 'verifactu_registration_id' => '2b3c4d5e-6f7a-4b8c-9d0e-1f2a3b4c5d6e', 'previous_status' => 'PENDING', 'new_status' => 'ACCEPTED', 'qr_url' => 'https://verifactu.agenciatributaria.gob.es/v?id=a7f3c9e2b1d4f8a6'],
            'account.claimed' => ['account_id' => '9b2e4c1a-7d3f-4e8b-a6c5-1f0d2e3b4a5c', 'external_ref' => 'acct-2041', 'email' => 'owner@cliente.es'],
            'company.created' => ['account_id' => '9b2e4c1a-7d3f-4e8b-a6c5-1f0d2e3b4a5c', 'external_ref' => 'acct-2041', 'nif' => 'B87654321', 'company_id' => '7c9e6679-7425-40de-944b-e07fc1f90ae7', 'legal_name' => 'Cliente Ejemplo SL'],
            'representation.signed' => ['account_id' => '9b2e4c1a-7d3f-4e8b-a6c5-1f0d2e3b4a5c', 'external_ref' => 'acct-2041', 'company_id' => '7c9e6679-7425-40de-944b-e07fc1f90ae7', 'nif' => 'B87654321', 'signed_at' => '2025-01-21T12:00:00Z'],
            default => [],
        };

        return self::merge($data, $overrides);
    }

    /**
     * Merges objects key by key and replaces lists whole, so overriding `lines` or `scopes` never
     * leaves elements of the default behind.
     */
    private static function merge(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            $base[$key] = is_array($value) && ! array_is_list($value) && is_array($base[$key] ?? null) && ! array_is_list($base[$key])
                ? self::merge($base[$key], $value)
                : $value;
        }

        return $base;
    }

    /** @return array<string, string> */
    private static function meta(): array
    {
        return ['timestamp' => '2025-01-20T11:00:00Z', 'request_id' => self::REQUEST_ID];
    }
}
