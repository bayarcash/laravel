# Changelog

All notable changes to `bayarcash/laravel` will be documented in this file.

## 1.4.0

Requires `bayarcash/php-sdk` 3.2.2. Run `php artisan migrate` after updating.

### Added
- Direct Debit deductions are recorded as payments (`payment_channel` 3) and fire `PaymentSucceeded` / `PaymentFailed`. Use `isDirectDebit()` to tell them apart.
- `directDebit()` scope and `mandate()` on transactions, and `payments()` on mandates.
- `payment_channel` is taken from the callback when not already set.
- `MandateRejected` and `MandateTerminated` events.
- `bayarcash:sync-mandates`, scheduled twice a day: applies approvals, terminations and deductions whose callback never arrived. Turn it off with `BAYARCASH_MANDATE_SYNC=false`.
- `$mandate->cancel()` stops an active mandate on Bayarcash, and refuses one still waiting for the bank.
- A deduction's `paid_at` is the date Bayarcash took it.

### Fixed
- `enrollDirectDebit()` sets the mandate's `return_url` to the callback route. Without it Bayarcash sent no mandate callbacks at all.
- `enrollDirectDebit()` cleans the payer name and phone before signing, so values like "A/L" or "+60" no longer fail with "Data checksum mismatch".
- Mandate status now follows the bank: approved → active, rejected → rejected, approved termination → terminated. `MandateApproved` fires only on approval.
- An authorization no longer overwrites the mandate's amount with the RM1 verification fee.
- Deduction callbacks no longer return `403`.
- The `incoming_transaction` notice before a deduction is answered with `200` instead of `403`.
- A cancelled deduction (billing file deleted) no longer fires `PaymentCancelled`.
- Abandoned payments (status `5`) fire `PaymentCancelled`.
- A re-delivered callback no longer changes `paid_at`.
- `hasPortal()` uses a single request.
- `BAYARCASH_TIMEOUT` is now applied.

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
