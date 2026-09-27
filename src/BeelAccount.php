<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel;

use Lenorix\BeelSdk\Beel;
use Lenorix\BeelSdk\Generated\Client;
use Lenorix\BeelSdk\Resource\Account\AccountCompaniesResource;
use Lenorix\BeelSdk\Resource\Account\AccountEmailsResource;
use Lenorix\BeelSdk\Resource\Account\AccountInvitationsResource;
use Lenorix\BeelSdk\Resource\Account\AccountMembersResource;
use Lenorix\BeelSdk\Resource\Account\AccountRequestLogsResource;
use Lenorix\BeelSdk\Resource\Account\AccountWebhooksResource;
use Lenorix\BeelSdk\Resource\AccountScope;

/**
 * Account scope decorator that keeps the SDK resources intact and exposes its raw client.
 *
 * @property-read AccountCompaniesResource $companies
 * @property-read AccountMembersResource $members
 * @property-read AccountInvitationsResource $invitations
 * @property-read AccountWebhooksResource $webhooks
 * @property-read AccountEmailsResource $emails
 * @property-read AccountRequestLogsResource $requestLogs
 *
 * @method \Lenorix\BeelSdk\Generated\Model\ManagedAccountSummary get()
 * @method \Lenorix\BeelSdk\Generated\Model\ProvisioningUsage usage()
 * @method void changeAccessLevel(\Lenorix\BeelSdk\Generated\Model\ChangeAccessLevelRequest|array<string, mixed> $request)
 * @method \Lenorix\BeelSdk\Generated\Model\ClaimTokenResult createClaimToken(\Lenorix\BeelSdk\Generated\Model\CreateClaimTokenRequest|array<string, mixed>|null $request = null)
 * @method void setOwner(\Lenorix\BeelSdk\Generated\Model\SetAccountOwnerRequest|array<string, mixed> $request)
 * @method void endManagement()
 * @method self withOptions(\Lenorix\BeelSdk\Http\RequestOptions $options) Per-call options; keeps this decorator.
 */
final class BeelAccount
{
    public readonly AccountScope $scope;

    public readonly Client $raw;

    /**
     * @param  Beel|AccountScope  $client  A client, or (internally) a scope already built for this account.
     * @param  Client|null  $raw  The raw client, required with a scope.
     */
    public function __construct(Beel|AccountScope $client, public readonly string $accountId, ?Client $raw = null)
    {
        $this->scope = $client instanceof Beel ? $client->account($accountId) : $client;
        $this->raw = $client instanceof Beel ? $client->raw : ($raw ?? throw new \InvalidArgumentException('A raw client is required with a scope.'));
    }

    public function __get(string $name): mixed
    {
        if (! property_exists($this->scope, $name)) {
            throw new \LogicException("Unknown BeeL account resource [{$name}].");
        }

        return $this->scope->{$name};
    }

    /** @param  array<array-key, mixed>  $arguments */
    public function __call(string $name, array $arguments): mixed
    {
        $result = $this->scope->{$name}(...$arguments);

        // withOptions() returns a new SDK scope: keep this decorator (id, raw client, resources) around it.
        if ($result instanceof AccountScope) {
            return new self($result, $this->accountId, $this->raw);
        }

        return $result;
    }
}
