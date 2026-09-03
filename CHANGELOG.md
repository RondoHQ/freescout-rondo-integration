# Changelog

All notable changes to this project are documented here.

## [1.6.0] - 2026-09-03

### Added

- Allow only exact HTTPS Sportlink member-detail links supplied by Rondo, rendered as a secondary sidebar action.

### Changed

- Reduce the space above the Rondo card while retaining compact horizontal padding.
- Present Rondo's process information under the clearer **Acties** tab label.

## [1.5.0] - 2026-09-03

### Added

- Present live Rondo member data in compact member, contact and process tabs based on the configured club colors.

### Changed

- Replace the raw accordion layout with a responsive card, status badges, contribution alert and a clear Rondo action.

## [1.4.0] - 2026-09-02

### Added

- Accept the closed `contributie.v1` mailbox policy, which requires Rondo's effective `financieel` capability.
- Let administrators select every active FreeScout mailbox in which the Rondo sidebar should appear, independently of managed mailbox-access mappings.

### Changed

- Require `ledenadministratie.v2`, allowing Rondo to add live financial context only for the exact signed-in user when their separate finance permission allows it.
- Use the generic `basis.v1` sidebar policy for selected mailboxes without a dedicated Rondo mapping.

## [1.3.0] - 2026-09-02

### Added

- Switch between all accessible Rondo profiles that share a customer email address inside the existing sidebar iframe.

### Changed

- Preserve the server-rendered profile selector while continuing to strip inline scripts and event handlers.

## [1.2.0] - 2026-09-02

### Added

- Deliver each incoming and sent reply as its own idempotent Rondo timeline activity after the reply is published.
- Attribute sent replies to the bound Rondo user without sending agent email addresses or FreeScout message content.

### Changed

- Move, hide or restore every activity belonging to a conversation when its FreeScout customer changes.

## [1.1.0] - 2026-09-02

### Added

- Deliver mapped FreeScout conversation creation and customer-change events to Rondo from a bounded, retryable queue.
- Reload current customer emails for every delivery without storing them in the queue or logs.

### Changed

- Share one normalized customer-email policy between the live sidebar and activity delivery.

### Fixed

- Exclude the Git worktree pointer file from release archives built inside a worktree.

## [1.0.11] - 2026-09-02

### Fixed

- Move sidebar height reporting to a same-origin external script allowed by FreeScout's inherited content security policy.
- Fall back to a usable fixed-height sidebar when automatic height reporting is unavailable.

## [1.0.10] - 2026-09-02

### Fixed

- Load sandboxed sidebar documents only after the iframe is visible and report a render failure instead of silently displaying a blank panel.

## [1.0.9] - 2026-09-02

### Fixed

- Persist redacted unexpected OIDC failure diagnostics in the module audit trail and show the latest references to administrators under Rondo identities.
- Log authentication failures at error level while keeping the login recovery path available when diagnostic storage or logging fails.

## [1.0.8] - 2026-09-02

### Fixed

- Make authentication diagnostic redaction fail safely and cover token-shaped values with an executable regression test.

## [1.0.7] - 2026-09-02

### Fixed

- Log a redacted authentication diagnostic with the same reference shown on failed Rondo sign-ins.

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
