<?php

declare(strict_types=1);

namespace Lenorix\LaravelBeel;

use Lenorix\BeelSdk\Beel;
use Lenorix\BeelSdk\Generated\Client;
use Lenorix\BeelSdk\Resource\Account\AccountCompaniesResource;
use Lenorix\BeelSdk\Resource\Account\AccountEmailsResource;
use Lenorix\BeelSdk\Resource\Account\AccountInvitationsResource;
use Lenorix\BeelSdk\Resource\Account\AccountMembersResource;
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
 *
 * @method \Lenorix\BeelSdk\Generated\Model\ManagedAccountSummary get()
 * @method \Lenorix\BeelSdk\Generated\Model\ProvisioningUsage usage()
 * @method mixed changeAccessLevel(\Lenorix\BeelSdk\Generated\Model\ChangeAccessLevelRequest $request)
 * @method \Lenorix\BeelSdk\Generated\Model\ClaimTokenResult createClaimToken(?\Lenorix\BeelSdk\Generated\Model\CreateClaimTokenRequest $request = null)
 * @method mixed setOwner(\Lenorix\BeelSdk\Generated\Model\SetAccountOwnerRequest $request)
 * @method mixed endManagement()
 */
final class BeelAccount
{
    public readonly AccountScope $scope;

    public readonly Client $raw;

    public function __construct(Beel $client, public readonly string $accountId)
    {
        $this->scope = $client->account($accountId);
        $this->raw = $client->raw;
    }

    public function __get(string $name): mixed
    {
        if (! property_exists($this->scope, $name)) {
            throw new \LogicException("Unknown BeeL account resource [{$name}].");
        }

        return $this->scope->{$name};
    }

    public function __call(string $name, array $arguments): mixed
    {
        return $this->scope->{$name}(...$arguments);
    }
}
