# Changelog

All notable changes to `bayarcash/laravel` will be documented in this file.

## 1.1.0

### Added
- Multi-tenant support via a single shared webhook that resolves each tenant automatically. Enable with `BAYARCASH_MULTI_TENANT=true`.
- `charge()` and `enrollDirectDebit()` accept an optional tenant, to take payments under a specific tenant's credentials.
- Optional encrypted `bayarcash_accounts` table and a `DatabaseCredentialResolver` for storing per-tenant credentials (publish `bayarcash-tenant-migrations`).

## 1.0.0
- Initial release: config, facade, the `HasBayarcashPayments` trait, optional database storage, checksum-verified callback/return handling, scheduled reconciliation, and events.
