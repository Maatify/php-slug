# Security Policy

[![Maatify Slug](https://img.shields.io/badge/Maatify-Slug-blue?style=for-the-badge)](https://github.com/Maatify/php-slug)
[![Maatify Ecosystem](https://img.shields.io/badge/Maatify-Ecosystem-9C27B0?style=for-the-badge)](https://github.com/Maatify)

## Current Support State

This repository contains the package implementation, Runtime, and CI, but no Stable release has been published through Packagist and no Stable release line is currently supported. A branch or Draft PR is not a published release or externally consumable Release Candidate, and this policy makes no public support or SLA commitment before official publication.

## Supported Versions

| Version | Supported |
|---|---|
| No published release | No |

This table changes only when the actual publication state and support policy change. Creating an unpublished branch or tag, or obtaining a successful CI run, does not change it.

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

The existence of Runtime, Tests, and CI is not a security certification or formal security audit, and this policy does not claim production or release readiness before an official Stable release is published. Any later claim about supported versions or security fixes must match a published version and the actual support policy.

For the technical contract and trust boundaries, see [Package Reference](SLUG_PACKAGE_REFERENCE.md).
