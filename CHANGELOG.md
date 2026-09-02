# Changelog

All notable changes to this project are documented here.

## [1.0.6] - 2026-09-02

### Fixed

- Read the customer-visibility prerequisite through Laravel's cached module configuration so the mailbox mapping screen and guarded user creation agree in production.

## [1.0.5] - 2026-09-01

### Fixed

- Validate the published SPDX package version, declared license and ZIP checksum during updater preflight.

## [1.0.4] - 2026-09-01

### Fixed

- Use `--release` for the updater tag instead of Artisan's reserved global `--version` option.

## [1.0.3] - 2026-09-01

### Fixed

- Normalize archive timestamps in UTC and verify byte-identical packages across different build timezones.

## [1.0.2] - 2026-09-01

### Fixed

- Sort every ZIP entry before packaging so identical source produces a stable release archive order and checksum.

## [1.0.1] - 2026-09-01

### Fixed

- Exclude PHPUnit cache metadata from release packages and enforce that boundary in CI and release checks.

## [1.0.0] - 2026-09-01

### Added

- Secure Rondo OpenID Connect relying-party flow with PKCE, nonce, signed ID-token validation and UserInfo subject matching.
- Transactional one-to-one identity bindings, immutable audit, session invalidation and administrator recovery.
- Guarded ordinary-agent creation and module-owned managed mailbox relationships.
- Verified mailbox mapping workflow and reconciliation command.
- Signed, authorized sidebar requests and opaque-origin sanitized rendering.
- Configurable semantic accents and coordinated responsive conversation-sidebar width.
- Fixed-version provisioning and checksum-gated targeted updater.
- Protected CI, deterministic package, SBOM and release workflow.
