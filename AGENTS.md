# AGENTS.md

Guidance for AI agents (and humans) changing `lenorix/laravel-beel`: a Laravel 13 integration of BeeL, the Spanish invoicing API with VERI*FACTU, built on `lenorix/beel-sdk`.

## Commands

```bash
composer test       # Pest: the whole suite, including the doc snippet and Boost checks
composer analyse    # PHPStan (level 5, src/ and config/)
vendor/bin/pint     # code style; CI fails on a diff (`vendor/bin/pint --test`)
```

Run all three before every commit. CI runs PHP 8.4 and 8.5 on Ubuntu and Windows, with prefer-lowest and prefer-stable. Files are checked out with LF on every OS (`.gitattributes`).

## Hard rules

- **Tests never reach a real service.** `Http::preventStrayRequests()` is on in `tests/TestCase.php`. Fake BeeL with `Http::fake()` and `Testing\BeelFake`. A one-off probe against a local server (for example a benchmark) goes in a temporary file that is deleted afterwards, never in the suite.
- **Secrets never leak:**
  - never print or log a webhook secret (the only exception is `RotatedWebhookSecretNotStored`);
  - never put an API key in a queued payload unless the job is `ShouldBeEncrypted`;
  - never send BeeL's `Authorization` header to a pre-signed URL, and keep that URL out of exception messages.
- **The webhook route has no middleware that answers 4xx** (throttle, auth, CSRF). BeeL doesn't retry 4xx.
- **Test the package, not the SDK.** Test through the public API. A test that could pass for the wrong reason is worse than none: after writing one, break the code it covers and check the test fails.
- **Don't copy SDK internals.** If something belongs in the SDK, say so. The SDK lives next to this repo, in `../BeeL-php-sdk`, and its OpenAPI spec is at `../BeeL-php-sdk/build/openapi.json`. Prefer a public SDK API, even when it's new, over reimplementing it here.

## Keep in sync when you change something

A public API change is not done until each of these is updated:

| Changed | Also update |
|---|---|
| Any public class, method, option, event or command | `CHANGELOG.md` (`[Unreleased]`); PHPDoc on the code itself; `resources/boost/skills/beel-invoicing/references/laravel-package.md` |
| Something every app needs, or a rule that prevents a mistake | `resources/boost/guidelines/core.blade.php` (short: one line) |
| Something a new user needs to get started | `README.md`: short sections, one line per extra feature, details in PHPDoc |
| `config/beel.php` | Comments in the config file; the config table in `laravel-package.md` |
| A BeeL response shape the package fakes | `Testing\BeelFake`, checked by a test that goes through a real SDK call |
| `lenorix/beel-sdk` version | `composer.json`; "Requirements" in README and CHANGELOG; `references/beel-sdk.md` (version line and changed APIs); remove workarounds the new version makes unnecessary |
| Laravel or PHP support | `composer.json`; `.github/workflows/run-tests.yml` matrix; README and CHANGELOG |
| BeeL API behaviour or VERI*FACTU facts | `references/beel-api.md` or `references/verifactu.md`, with the source (docs.beel.es page, OpenAPI, BOE) |

## Docs conventions

- Everything in the repo is in English.
- **README:** for getting started. Keep paragraphs short. For the rest, one line saying the feature exists and where its PHPDoc is. Every PHP example must parse (`tests/DocSnippetsTest.php`). If an example shows behaviour, add a test that runs the same code (see `StoreInvoicePdfJobTest`).
- **Boost guideline** (`core.blade.php`): it is loaded into every agent's context, so keep only rules and pointers. Details go in the skill. It must render as Blade: no echo braces or directives, or Boost drops it silently (`BoostGuidelinesTest`).
- **Boost skill** (`resources/boost/skills/beel-invoicing`):
  - `SKILL.md` has the workflows and says which reference to read;
  - `references/*.md` hold the details;
  - the frontmatter follows the Agent Skills spec (`BoostGuidelinesTest`);
  - references say what they were verified against.
- **PHPDoc:** explain why, limits and failure modes, not what the code already says.
- **CHANGELOG:** follows Keep a Changelog; describe behaviour, not implementation.

## Code conventions

- `declare(strict_types=1)`, `final` classes by default, readonly promoted properties.
- Write code that reads like the surrounding code: match its naming, idiom and comment density.
- Get clients only through `BeelManager`. Credentials come from `Contracts\CredentialsResolver`, resolved on every call.
- `BeelCompany`, `BeelAccount` and `BeelCompanyInvoices` decorate SDK scopes and resources. They must keep wrapping whatever `withOptions()` returns.
- Internal helpers go in `src/Support` and are marked `@internal`.
- Use `Illuminate\Support\Sleep` for delays, so tests can `Sleep::fake()`.

## Git

- Commit only when asked. Push only when the user approves.
- Commit messages explain why, in the imperative mood: a short subject, then a body.
- Never rewrite published history.
