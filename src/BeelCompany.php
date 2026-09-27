<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel;

use Illuminate\Container\Container;
use Lenorix\BeelSdk\Beel;
use Lenorix\BeelSdk\Exception\BeelApiError;
use Lenorix\BeelSdk\Generated\Client;
use Lenorix\BeelSdk\Resource\Company\CompanyActivationsResource;
use Lenorix\BeelSdk\Resource\Company\CompanyCustomersResource;
use Lenorix\BeelSdk\Resource\Company\CompanyInvoiceCustomizationResource;
use Lenorix\BeelSdk\Resource\Company\CompanyInvoicesResource;
use Lenorix\BeelSdk\Resource\Company\CompanyLogoResource;
use Lenorix\BeelSdk\Resource\Company\CompanyPaymentConnectionsResource;
use Lenorix\BeelSdk\Resource\Company\CompanyProductsResource;
use Lenorix\BeelSdk\Resource\Company\CompanyRecurringInvoicesResource;
use Lenorix\BeelSdk\Resource\Company\CompanyRepresentationResource;
use Lenorix\BeelSdk\Resource\Company\CompanySeriesResource;
use Lenorix\BeelSdk\Resource\Company\CompanyTaxConfigurationResource;
use Lenorix\BeelSdk\Resource\Company\CompanyVeriFactuConfigurationResource;
use Lenorix\BeelSdk\Resource\CompanyScope;
use Lenorix\LaravelBeel\Exceptions\DocumentAlreadyExists;
use Lenorix\LaravelBeel\Exceptions\DocumentDownloadFailed;
use Lenorix\LaravelBeel\Support\DocumentKind;
use Lenorix\LaravelBeel\Support\DocumentResponse;
use Lenorix\LaravelBeel\Support\SignedDownloadStorage;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
 * @property-read CompanyRepresentationResource $representation
 * @property-read CompanyActivationsResource $activations
 * @property-read CompanyInvoiceCustomizationResource $invoiceCustomization
 * @property-read CompanyLogoResource $logo
 *
 * @method \Lenorix\BeelSdk\Generated\Model\CompanyData get()
 * @method \Lenorix\BeelSdk\Generated\Model\CompanyData update(\Lenorix\BeelSdk\Generated\Model\UpdateCompanyRequest|array<string, mixed> $request)
 * @method void delete()
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

    /**
     * Store the company's AEAT representation document (PDF) on a Laravel disk and return the path:
     * the generated one while unsigned, the signed copy once submitted. Same streaming, verification
     * and atomic write as `$company->invoices->storePdf()`.
     *
     * @param  array<string, mixed>  $options  Passed to the disk; `ContentType` defaults to `application/pdf`.
     *
     * @throws DocumentAlreadyExists The path exists and `$overwrite` is false.
     * @throws DocumentDownloadFailed Every attempt failed; nothing was written to `$path`.
     * @throws BeelApiError From BeeL, e.g. 400 while the document has not been generated.
     */
    public function storeRepresentationDocument(string $path, ?string $disk = null, bool $overwrite = false, array $options = []): string
    {
        return Container::getInstance()->make(SignedDownloadStorage::class)->store(
            fn (): string => $this->scope->representation->documentLink()->getDownloadUrl(),
            DocumentKind::Pdf, "the representation document of company {$this->companyId}", $path, $disk, $overwrite, $options,
        );
    }

    /**
     * Answer with the company's AEAT representation document as a download, streamed from BeeL's
     * pre-signed URL without storing it.
     *
     * @throws DocumentDownloadFailed
     * @throws BeelApiError From BeeL, e.g. 400 while the document has not been generated.
     */
    public function downloadRepresentationDocument(?string $fileName = null): StreamedResponse
    {
        $document = "the representation document of company {$this->companyId}";
        [$body, $length, $type] = Container::getInstance()->make(SignedDownloadStorage::class)->openSigned(
            fn (): string => $this->scope->representation->documentLink()->getDownloadUrl(),
            $document,
        );

        return DocumentResponse::make($body, $length, $type, DocumentKind::Pdf, $document, $fileName ?? 'representation.pdf');
    }

    public function __get(string $name): mixed
    {
        if (! property_exists($this->scope, $name)) {
            throw new \LogicException("Unknown BeeL company resource [{$name}].");
        }

        $resource = $this->scope->{$name};

        return $resource instanceof CompanyInvoicesResource ? new BeelCompanyInvoices($resource) : $resource;
    }

    /** @param  array<array-key, mixed>  $arguments */
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
