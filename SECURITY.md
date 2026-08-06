# Security Policy

## Supported versions

This package follows semantic versioning. Security fixes are applied to the latest
released minor version. Until a `1.0.0` release, please track the latest `0.x`.

| Version | Supported |
| --- | :---: |
| latest `0.x` | ✅ |
| older | ❌ |

## Reporting a vulnerability

**Please do not open a public issue, pull request, or discussion for security
vulnerabilities.**

Report suspected vulnerabilities privately to **security@getpayin.com**. If you use
GitHub, you may instead open a private advisory via the repository's
**Security → Report a vulnerability** page.

Please include:

- a description of the issue and its impact;
- the affected version(s) and gateway/driver, if specific;
- clear steps to reproduce, or a minimal proof of concept;
- any relevant logs or request/response captures (with secrets redacted).

## What to expect

- **Acknowledgement** within 3 business days.
- An initial assessment and severity triage within 7 business days.
- Coordinated disclosure: we will agree on a timeline with you, ship a fix, and credit
  you in the release notes unless you prefer to remain anonymous.

## Scope & handling sensitive data

This SDK signs and transmits payment requests. When reporting or reproducing issues:

- **Never send real cardholder data, live API keys, or shared secrets.** Use sandbox
  credentials and test cards.
- Redact `Authorization`, `Signature`, `v-c-*`, `hmac`, `token`, and `shared_secret`
  values from any captures you share.

Areas of particular interest: request-signing correctness (HMAC field order and
encoding), webhook signature verification, idempotency/replay handling, and any path
where a `mixed` gateway response could be trusted without validation.
