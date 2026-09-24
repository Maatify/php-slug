# Security Policy

[![Maatify Slug](https://img.shields.io/badge/Maatify-Slug-blue?style=for-the-badge)](https://github.com/Maatify/php-slug)
[![Maatify Ecosystem](https://img.shields.io/badge/Maatify-Ecosystem-9C27B0?style=for-the-badge)](https://github.com/Maatify)

## Current Support State

This repository contains the package implementation, Runtime, and CI. There is no Published Stable release line and no Stable support line. `v1.0.0-rc.1` is the first RC identifier; external publication of that version makes it a published pre-release, not Stable, and does not create a Stable support line. A branch, tag, CI run, or GitHub Release alone is not proof of Composer publication, and this policy makes no public support or SLA commitment for the RC.

## Supported Versions

| Version | Supported |
|---|---|
| Stable release line | No |
| `1.0.0-rc.1` pre-release | No — pre-release; no Stable support line |

This table is support-oriented, not a mutable publication-status ledger. The RC does not create a Stable support line or SLA.

## Security Scope

The package scope includes slug profiles, input validation, ownership and lifecycle, history, PDO MySQL persistence, transactions, concurrency, and public result and exception contracts.

Outside the package scope are Host entity persistence and existence, authentication, authorization, routing and URL transport, HTTP status and redirect policy, SEO, framework integrations, and Host infrastructure. Issues in those areas should be reported to the application owner or responsible adapter.

## Private Reporting

Do not publish details of an unpatched vulnerability in GitHub Issues. Send a private report to `support@maatify.dev` with:

- a clear description of the issue and its impact;
- reproduction steps or a safe proof of concept;
- the affected version or commit and runtime environment; and
- any known temporary mitigation, with secrets and personal data removed.

This document does not promise a response or remediation time. Handling depends on report validation, scope, and the published Runtime state when the report arrives.

## Current Claim Boundaries

The existence of Runtime, Tests, CI, or a published RC is not a security certification or formal security audit. This policy does not claim Stable release readiness, production support, or a Stable support line from the RC. Any claim about supported versions or security fixes must match a published version and the actual support policy.

For the technical contract and trust boundaries, see [Package Reference](SLUG_PACKAGE_REFERENCE.md).
