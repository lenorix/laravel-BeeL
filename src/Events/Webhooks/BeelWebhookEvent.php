<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel\Events\Webhooks;

use Lenorix\LaravelBeel\Events\BeelWebhookReceived;

/**
 * A verified BeeL webhook of one known type, dispatched right after BeelWebhookReceived so
 * listeners can subscribe to just what they need (e.g. `Event::listen(InvoiceIssued::class, ...)`).
 *
 * The same rules apply: it runs inside the webhook request (keep listeners light, dispatch a job)
 * and a listener that throws answers 503 so BeeL retries. `$webhook` has the id to deduplicate on,
 * `isTest()`, `companyId`, `webhookKey` and the raw payload; each subclass's `data()` returns the
 * SDK's typed model, built lazily (a schema mismatch throws WebhookPayloadError only there).
 */
abstract class BeelWebhookEvent
{
    /** @var array<string, class-string<self>> */
    private const TYPES = [
        'invoice.issued' => InvoiceIssued::class,
        'invoice.email.sent' => InvoiceEmailSent::class,
        'invoice.pdf.generated' => InvoicePdfGenerated::class,
        'invoice.voided' => InvoiceVoided::class,
        'invoice.schedule_failed' => InvoiceScheduleFailed::class,
        'recurring_invoice.paused' => RecurringInvoicePaused::class,
        'verifactu.status.updated' => VerifactuStatusUpdated::class,
        'account.claimed' => AccountClaimed::class,
        'company.created' => CompanyCreated::class,
        'representation.signed' => RepresentationSigned::class,
    ];

    final public function __construct(public readonly BeelWebhookReceived $webhook) {}

    /** The typed event for this delivery, or null for types this package doesn't know yet. */
    public static function for(BeelWebhookReceived $webhook): ?self
    {
        $class = self::TYPES[$webhook->type] ?? null;

        return $class === null ? null : new $class($webhook);
    }

    /** The SDK hydrated `data` into another model than this type's: the payload doesn't match BeeL's schema. */
    protected function unexpectedData(mixed $data): \UnexpectedValueException
    {
        return new \UnexpectedValueException("BeeL webhook {$this->webhook->id} ({$this->webhook->type}) has data of type ".get_debug_type($data).'.');
    }

    /** @return list<string> Every webhook type that has its own event class. */
    public static function types(): array
    {
        return array_keys(self::TYPES);
    }
}
