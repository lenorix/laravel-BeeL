<?php

use Lenorix\BeelSdk\Resource\AccountScope;
use Lenorix\BeelSdk\Resource\CompanyScope;
use Lenorix\LaravelBeel\BeelAccount;
use Lenorix\LaravelBeel\BeelCompany;

/**
 * The decorators forward to the SDK scopes by magic, so apps' IDEs and static analysis only see
 * what their docblocks declare. When a lenorix/beel-sdk update adds a method or resource, these
 * tests fail until the docblock lists it.
 */
function declaredIn(string $decorator, string $tag): array
{
    preg_match_all('/@'.$tag.'\s+\S+\s+\$?(\w+)/', (string) (new ReflectionClass($decorator))->getDocComment(), $matches);

    return $matches[1];
}

function scopeMethods(string $scope): array
{
    $methods = array_filter(
        (new ReflectionClass($scope))->getMethods(ReflectionMethod::IS_PUBLIC),
        fn (ReflectionMethod $method) => ! $method->isStatic() && ! str_starts_with($method->name, '__'),
    );

    return array_values(array_map(fn (ReflectionMethod $method) => $method->name, $methods));
}

function scopeResources(string $scope): array
{
    return array_map(fn (ReflectionProperty $property) => $property->name, (new ReflectionClass($scope))->getProperties(ReflectionProperty::IS_PUBLIC));
}

it('annotates every method and resource of the SDK scope it decorates', function (string $decorator, string $scope, array $ownProperties) {
    expect(declaredIn($decorator, 'method'))->toEqualCanonicalizing(scopeMethods($scope))
        ->and(array_merge(declaredIn($decorator, 'property-read'), $ownProperties))->toEqualCanonicalizing(scopeResources($scope));
})->with([
    'company' => [BeelCompany::class, CompanyScope::class, ['companyId']],
    'account' => [BeelAccount::class, AccountScope::class, ['accountId']],
]);
