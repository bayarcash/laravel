# Changelog

All notable changes to `bayarcash/laravel` will be documented in this file.

## 1.3.0

### Fixed
- `bayarcash:reconcile` is now multi-tenant aware. Each pending payment is re-queried, and cancelled once stale, with the credentials of the tenant stored on it instead of the `.env` ones. Each tenant's credentials are resolved once per run, and a tenant whose credentials fail no longer blocks the others. Payments without a tenant still use `.env`.
- `bayarcash:reconcile` can now be run with `Artisan::call()` from a web request (for example an admin "Run reconcile now" button). Before, it was only registered for the command line. Scheduling is unchanged.
- The return redirect now tells your app which payment came back. When the checksum verifies, `order_number`, `transaction_id` and `status` (taken from the recorded transaction) are added to the redirect as query parameters. Unverified returns and returns without a checksum redirect with no parameters, as before. Turn it off with `BAYARCASH_RETURN_INCLUDE_REFERENCE=false` (`return.include_reference`).

### Added
- `Bayarcash::portals($tenant = null)` returns every portal on the account as a collection, following all pages. The SDK's `getPortals()` returns only the first page of 15.
- `Bayarcash::hasPortal($portalKey, $tenant = null)` checks whether a portal key belongs to the account, stopping at the page that has it. Handy for a "test connection" button.

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
