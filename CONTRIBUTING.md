# Contributing

Thanks for helping improve **hyprpay/payments**. This guide covers local setup, the
quality gate every change must pass, and the conventions the codebase follows.

By participating in this project you agree to abide by our
[Code of Conduct](CODE_OF_CONDUCT.md).

## Getting set up

```bash
composer install
composer test
```

Tests are database-free and make no real network calls; drivers are exercised through
the in-memory `FakeHttpClient`, so `composer test` runs anywhere with no services.

## The quality gate

Every change must pass the full gate before it is merged:

```bash
composer check
```

which runs, in order:

| Command | Tool | Must be |
| --- | --- | --- |
| `composer format:test` | Laravel Pint (config in `pint.json`) | clean |
| `composer rector:dry` | Rector (`rector.php`) | no proposed changes |
| `composer analyse` | PHPStan **level max** (`phpstan.neon`) | **0 errors, no baseline** |
| `composer test` | Pest | all green |

Fix issues rather than suppress them. Do **not** add `@phpstan-ignore`, a PHPStan
baseline, `assert()`, or inline `/** @var */` to silence static analysis — narrow the
type at the point of use (see `Hyprpay\Payments\Support\Value` and `data_get()`).
Run `composer format` and `composer rector` to apply the automatic fixes.

## Conventions

- **`declare(strict_types=1)`** on every file; explicit return types on every method.
- **Immutable DTOs** — `final readonly` classes with constructor property promotion.
- **Enums** — string-backed, `TitleCase` cases.
- **Control flow** — guard clauses, `match`, and ternaries; no `if/else` or `switch`.
- **No rounding of money** — carry amounts as minor units via `Money`.
- **`filled()` / `blank()`** over `=== null` / `empty()` guards.
- **Detailed PHPDoc** on every class and method (this is a library — docblocks are
  part of the public contract).
- **`use` imports** only — no inline fully-qualified class names.

## Adding a gateway

1. Create `src/Gateways/{Name}/` with the driver (extend `AbstractPaymentGateway`),
   its client, signing/payload helpers, and enums.
2. Add a case to `src/Enums/GatewayName.php`.
3. Add one branch to `src/PaymentGatewayFactory::make()`.
4. Add the gateway's defaults to `config/gateway.php`.
5. Implement only the operations the gateway genuinely supports — the rest inherit
   `UnsupportedOperationException` from `AbstractPaymentGateway`.
6. Add Pest coverage: a signature/golden test and a `FakeHttpClient` feature test per
   operation, including webhook verify (valid + tampered) and the error path.

## Signing & webhooks

Payment signing is the security-critical surface. When you touch it:

- Reproduce the provider's exact field order and hashing verbatim from their docs.
- Add a golden/deterministic test that pins the produced signature for fixed inputs.
- Build request bodies deterministically — never introduce `uniqid()`, `time()`,
  `rand()`, or other non-determinism, so identical retries stay byte-for-byte equal
  and the gateway can deduplicate them.

## Pull requests

- Keep PRs focused and describe the "why".
- Ensure `composer check` is green and include/update tests for your change.
- Do not break existing public method signatures without a clear migration note.

## Releasing

The git tag is the single source of truth for the package version. `composer.json`
carries **no `version` key** — Packagist infers it from the tag, and a hardcoded key
that disagrees with the tag makes Packagist silently ignore that release.

1. Add `docs/changelog/vX.Y.Z.md` and prepend the entry to `docs/changelog/manifest.json`.
2. Merge to `main`, then tag and push — the tag push runs the quality gate and
   publishes the GitHub release:

   ```sh
   git tag -a vX.Y.Z -m "vX.Y.Z"
   git push origin vX.Y.Z
   ```
