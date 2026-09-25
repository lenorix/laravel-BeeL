<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel;

use Lenorix\BeelSdk\Beel;
use Lenorix\BeelSdk\Generated\Client;
use Lenorix\BeelSdk\Resource\CompanyScope;

/**
 * Company scope decorator that keeps the SDK resources intact and exposes its raw client.
 *
 * @property-read \Lenorix\BeelSdk\Resource\Company\CompanyInvoicesResource $invoices
 * @property-read \Lenorix\BeelSdk\Resource\Company\CompanyCustomersResource $customers
 * @property-read \Lenorix\BeelSdk\Resource\Company\CompanyProductsResource $products
 * @property-read \Lenorix\BeelSdk\Resource\Company\CompanySeriesResource $series
 * @property-read \Lenorix\BeelSdk\Resource\Company\CompanyRecurringInvoicesResource $recurringInvoices
 * @property-read \Lenorix\BeelSdk\Resource\Company\CompanyPaymentConnectionsResource $paymentConnections
 * @property-read \Lenorix\BeelSdk\Resource\Company\CompanyTaxConfigurationResource $taxConfiguration
 * @property-read \Lenorix\BeelSdk\Resource\Company\CompanyVeriFactuConfigurationResource $verifactuConfiguration
 */
final class BeelCompany
{
    public readonly CompanyScope $scope;

    public readonly Client $raw;

    public function __construct(Beel $client, public readonly string $companyId)
    {
        $this->scope = $client->company($companyId);
        $this->raw = $client->raw;
    }

    public function __get(string $name): mixed
    {
        if (! property_exists($this->scope, $name)) {
            throw new \LogicException("Unknown BeeL company resource [{$name}].");
        }

        return $this->scope->{$name};
    }

    public function __call(string $name, array $arguments): mixed
    {
        return $this->scope->{$name}(...$arguments);
    }
}
