<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel;

use Lenorix\BeelSdk\Beel;
use Lenorix\BeelSdk\Generated\Client;
use Lenorix\BeelSdk\Resource\Company\CompanyCustomersResource;
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
 * @property-read BeelCompanyInvoices $invoices The SDK's invoices resource plus storePdf().
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
 * @method self withOptions(\Lenorix\BeelSdk\Http\RequestOptions $options) Per-call options; keeps this decorator.
 */
final class BeelCompany
{
    public readonly CompanyScope $scope;

    public readonly Client $raw;

    /**
     * @param  Beel|CompanyScope  $client  A client, or (internally) a scope already built for this company.
     * @param  Client|null  $raw  The raw client, required with a scope.
     */
    public function __construct(Beel|CompanyScope $client, public readonly string $companyId, ?Client $raw = null)
    {
        $this->scope = $client instanceof Beel ? $client->company($companyId) : $client;
        $this->raw = $client instanceof Beel ? $client->raw : ($raw ?? throw new \InvalidArgumentException('A raw client is required with a scope.'));
    }

    public function __get(string $name): mixed
    {
        if (! property_exists($this->scope, $name)) {
            throw new \LogicException("Unknown BeeL company resource [{$name}].");
        }

        $resource = $this->scope->{$name};

        return $name === 'invoices' ? new BeelCompanyInvoices($resource) : $resource;
    }

    public function __call(string $name, array $arguments): mixed
    {
        $result = $this->scope->{$name}(...$arguments);

        // withOptions() returns a new SDK scope: keep this decorator (id, raw client, resources) around it.
        if ($result instanceof CompanyScope) {
            return new self($result, $this->companyId, $this->raw);
        }

        return $result;
    }
}
