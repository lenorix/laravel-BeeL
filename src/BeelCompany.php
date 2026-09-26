<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel;

use Lenorix\BeelSdk\Beel;
use Lenorix\BeelSdk\Generated\Client;
use Lenorix\BeelSdk\Resource\Company\CompanyCustomersResource;
use Lenorix\BeelSdk\Resource\Company\CompanyInvoicesResource;
use Lenorix\BeelSdk\Resource\Company\CompanyPaymentConnectionsResource;
use Lenorix\BeelSdk\Resource\Company\CompanyProductsResource;
use Lenorix\BeelSdk\Resource\Company\CompanyRecurringInvoicesResource;
use Lenorix\BeelSdk\Resource\Company\CompanySeriesResource;
use Lenorix\BeelSdk\Resource\Company\CompanyTaxConfigurationResource;
use Lenorix\BeelSdk\Resource\Company\CompanyVeriFactuConfigurationResource;
use Lenorix\BeelSdk\Resource\CompanyScope;

/**
 * Company scope decorator that keeps the SDK resources intact and exposes its raw client.
 *
 * @property-read CompanyInvoicesResource $invoices
 * @property-read CompanyCustomersResource $customers
 * @property-read CompanyProductsResource $products
 * @property-read CompanySeriesResource $series
 * @property-read CompanyRecurringInvoicesResource $recurringInvoices
 * @property-read CompanyPaymentConnectionsResource $paymentConnections
 * @property-read CompanyTaxConfigurationResource $taxConfiguration
 * @property-read CompanyVeriFactuConfigurationResource $verifactuConfiguration
 *
 * @method \Lenorix\BeelSdk\Generated\Model\CompanyData get()
 * @method \Lenorix\BeelSdk\Generated\Model\CompanyData update(\Lenorix\BeelSdk\Generated\Model\UpdateCompanyRequest $request)
 * @method mixed delete()
 * @method \Lenorix\BeelSdk\Generated\Model\FiscalSummaryResponse fiscalSummary(array<string, mixed> $query = [])
 * @method \Lenorix\BeelSdk\Generated\Model\IssuingReadinessData issuingReadiness()
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
