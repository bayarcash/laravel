# Changelog

All notable changes to `bayarcash/laravel` will be documented in this file.

## 1.3.0

### Fixed
- `bayarcash:reconcile` uses each payment's own tenant credentials instead of the `.env` ones.
- `bayarcash:reconcile` can run from a web request through `Artisan::call()`.
- A verified return redirect includes `order_number`, `transaction_id` and `status`. Turn this off with `BAYARCASH_RETURN_INCLUDE_REFERENCE=false`.

### Added
- `Bayarcash::portals()` lists every portal across all pages.
- `Bayarcash::hasPortal($portalKey)` checks whether a portal key belongs to the account.

## 1.2.0

### Added
- Laravel 13 support.

## 1.1.0

### Added
- Multi-tenant support via a single shared webhook that resolves each tenant automatically. Enable with `BAYARCASH_MULTI_TENANT=true`.
- `charge()` and `enrollDirectDebit()` accept an optional tenant, to take payments under a specific tenant's credentials.
- Optional encrypted `bayarcash_accounts` table and a `DatabaseCredentialResolver` for storing per-tenant credentials (publish `bayarcash-tenant-migrations`).

## 1.0.0
- Initial release: config, facade, the `HasBayarcashPayments` trait, optional database storage, checksum-verified callback/return handling, scheduled reconciliation, and events.
