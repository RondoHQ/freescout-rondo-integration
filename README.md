# Rondo Integration for FreeScout

Rondo Integration is the first-party FreeScout module for Rondo Club. It provides secure OpenID Connect sign-in, one-to-one subject binding, managed mailbox access, live Rondo context in a sandboxed conversation sidebar, controlled club accents and responsive sidebar width.

## Requirements

- FreeScout 1.8.238 or newer
- PHP 7.1 or newer with OpenSSL, cURL, DOM and Zip
- HTTPS Rondo and FreeScout installations outside explicit local testing
- `APP_LIMIT_USER_CUSTOMER_VISIBILITY=true` before guarded user creation can be enabled
- One tested local FreeScout administrator account as break glass

The module fails closed for Rondo data and managed access until the matching Rondo integration configuration, access and sidebar endpoints are active. Normal FreeScout operation and `/login?rondo_oauth=0` remain available during that setup.

## Install

Production installation uses an exact immutable release and approved SHA-256:

```sh
export RONDO_MODULE_VERSION=v1.0.6
export RONDO_MODULE_SHA256=<approved-64-character-sha256>
export FREESCOUT_ROOT=/var/www/html
./provision/install-fixed-version.sh
```

After activation, open **Manage → Rondo Integration**. Configure the Rondo base URL, the Rondo-issued OIDC client ID and one-time client secret, and the shared HMAC signing key. The callback to register in Rondo is shown on that page and is normally:

```text
https://your-freescout.example/rondo/oidc/callback
```

Verify OIDC discovery before enabling login. Keep `/login?rondo_oauth=0` and a local administrator available as independent recovery paths.

## Approved updates

FreeScout can report stable updates from the module manifest, but production installation uses the checksum-gated wrapper:

```sh
php artisan rondo:integration-update --release=v1.0.6 --sha256=<approved-sha256> --check
php artisan rondo:integration-update --release=v1.0.6 --sha256=<same-sha256> --install
```

The install command backs up the database and module directory, installs only alias `rondointegration`, runs FreeScout's module migration/install path, verifies the running version and restores the backup on failure.

## Configuration precedence

Environment values override the administrator interface:

- `RONDO_BASE_URL`
- `RONDO_OIDC_CLIENT_ID`
- `RONDO_OIDC_CLIENT_SECRET`
- `RONDO_SIGNING_KEY`
- `RONDO_FORCE_OAUTH_LOGIN`
- `RONDO_AUTOMATIC_USER_CREATION`
- `RONDO_MANAGED_MAILBOX_MAPPINGS`
- `RONDO_ALLOW_LOCAL_HTTP` for an explicitly local/testing environment only

`RONDO_MANAGED_MAILBOX_MAPPINGS` is a JSON object such as `{"ledenadministratie":7}`. Keys are accepted only when returned by Rondo's configuration service, mailbox IDs must be active and unique, and the UI cannot replace an environment-managed selection.

No club URL, mailbox ID, signing key, client secret or club color is compiled into the release.

## Development

```sh
composer install
composer lint
composer test
php scripts/package.php
```

The sidebar placement was independently implemented after auditing `fulldecent/freescout-sidebar-webhook` at commit `8c88174489686536431640395ed7b1b8c30fad2d`. See `THIRD_PARTY_NOTICES.md`.

## License

AGPL-3.0-only.
