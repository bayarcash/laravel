# Bayarcash for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/bayarcash/laravel.svg?style=flat-square)](https://packagist.org/packages/bayarcash/laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/bayarcash/laravel.svg?style=flat-square)](https://packagist.org/packages/bayarcash/laravel)
[![PHP Version](https://img.shields.io/packagist/php-v/bayarcash/laravel.svg?style=flat-square)](https://packagist.org/packages/bayarcash/laravel)
[![License](https://img.shields.io/packagist/l/bayarcash/laravel.svg?style=flat-square)](https://packagist.org/packages/bayarcash/laravel)
[![Tests](https://github.com/bayarcash/laravel/actions/workflows/tests.yml/badge.svg)](https://github.com/bayarcash/laravel/actions/workflows/tests.yml)

A Laravel integration for the [Bayarcash](https://bayar.cash) payment gateway. It wraps the framework-agnostic [`bayarcash/php-sdk`](https://packagist.org/packages/bayarcash/php-sdk) and adds a Laravel-idiomatic developer experience: config, a facade, a model trait, optional database persistence, automatic callback/return handling with checksum verification, scheduled reconciliation, and events.

Targets **Bayarcash API v3**.

It fits two setups:

- **Single merchant** — one set of credentials in `.env`. Everything in [Usage](#usage) works out of the box.
- **Multi-tenant (SaaS)** — each tenant has its own Bayarcash account with credentials stored in your database. See [Multi-tenant](#multi-tenant-credentials-in-the-database).

Either way you choose whether to **store payment records** in your database with [`BAYARCASH_STORE_RECORDS`](#store-records-store-data-or-pass-through).

## Requirements

- PHP 8.1+
- Laravel 10, 11, or 12

## Installation

```bash
composer require bayarcash/laravel
```

Publish the config file:

```bash
php artisan vendor:publish --tag=bayarcash-config
```

Publish and run the migrations (skip if you want [stateless mode](#stateless-mode)):

```bash
php artisan vendor:publish --tag=bayarcash-migrations
php artisan migrate
```

## Configuration

Add your credentials to `.env`:

```dotenv
BAYARCASH_API_TOKEN=your-personal-access-token
BAYARCASH_API_SECRET_KEY=your-api-secret-key
BAYARCASH_SANDBOX=true

# Optional
BAYARCASH_STORE_RECORDS=true
BAYARCASH_CALLBACK_PATH=bayarcash/callback
BAYARCASH_RETURN_PATH=bayarcash/return
BAYARCASH_RETURN_REDIRECT=payments.thank-you   # named route or absolute URL
BAYARCASH_RECONCILE=true
```

In the Bayarcash portal, point your portal's URLs at the package routes:

- **Callback URL** → `https://your-app.test/bayarcash/callback`
- **Return URL** → `https://your-app.test/bayarcash/return`

## Usage

### 1. Make a model payable

Add the `HasBayarcashPayments` trait to any Eloquent model (an `Order`, `User`, `Invoice`, ...):

```php
use Bayarcash\Laravel\Concerns\HasBayarcashPayments;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasBayarcashPayments;
}
```

This adds `payments()` and `mandates()` relations plus the `charge()` and `enrollDirectDebit()` helpers.

### 2. Create a payment

```php
use Bayarcash\Bayarcash;

$intent = $order->charge([
    'portal_key'             => 'your_portal_key',
    'payment_channel'        => Bayarcash::FPX,
    'order_number'           => $order->reference, // optional; auto-generated when omitted
    'amount'                 => '10.00',
    'payer_name'             => $order->customer_name,
    'payer_email'            => $order->customer_email,
    'payer_telephone_number' => $order->customer_phone,
]);

// Redirect the customer to the hosted checkout.
return redirect()->away($intent->url);
```

The checksum is generated for you. When record storage is enabled, a pending
`BayarcashTransaction` is stored and linked to `$order`, and a `PaymentCreated`
event fires.

### 3. Enrol a Direct Debit mandate

```php
use Bayarcash\FpxDirectDebit;

$mandate = $order->enrollDirectDebit([
    'portal_key'             => 'your_portal_key',
    'amount'                 => '10.00',
    'payer_name'             => 'Ahmad bin Abdullah',
    'payer_id_type'          => FpxDirectDebit::NRIC,
    'payer_id'               => '900101011234',
    'payer_email'            => 'ahmad@example.com',
    'payer_telephone_number' => '0123456789',
    'application_reason'      => 'Monthly subscription',
    'frequency_mode'         => FpxDirectDebit::MODE_MONTHLY,
]);

return redirect()->away($mandate->url);
```

### 4. Handle results

The package registers two routes automatically — you do not write controllers:

| Route | Method | Purpose |
|---|---|---|
| `/bayarcash/callback` | `POST` | Server-to-server, authoritative. Checksum-verified (invalid → `403`). Updates the transaction and fires the status event. |
| `/bayarcash/return` | `GET` | Browser redirect, best-effort. Verifies the checksum when present, never aborts, then redirects (or returns JSON). |

Set `BAYARCASH_RETURN_REDIRECT` to a named route or URL to control where the
customer lands after payment. When it is null, the return route responds with
JSON.

### 5. Listen for events

```php
use Bayarcash\Laravel\Events\PaymentSucceeded;
use Illuminate\Support\Facades\Event;

Event::listen(PaymentSucceeded::class, function (PaymentSucceeded $event) {
    $event->transaction->owner->markPaid();
});
```

Available events:

| Event | Payload |
|---|---|
| `PaymentCreated` | `->transaction` |
| `PaymentSucceeded` | `->transaction` |
| `PaymentFailed` | `->transaction` |
| `PaymentCancelled` | `->transaction` |
| `MandateAuthorized` | `->mandate` |
| `MandateApproved` | `->mandate` |
| `WebhookReceived` | `->recordType`, `->payload` |

### 6. Query stored records

```php
$order->payments()->successful()->get();
$order->payments()->pending()->get();

$transaction->statusLabel(); // "Successful", "Pending", ...
```

## Reconciliation

Callbacks and return redirects can be missed (downtime, network issues). The
package ships a `bayarcash:reconcile` command that re-queries pending payments
and auto-cancels stale ones. **It is scheduled automatically** (every minute)
when `BAYARCASH_RECONCILE=true` — you only need Laravel's scheduler running:

```
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

You can also run it manually:

```bash
php artisan bayarcash:reconcile
```

Reconciliation requires stored records.

## Store records (store data, or pass-through)

`BAYARCASH_STORE_RECORDS` decides whether the package keeps a local copy of every
payment and mandate in your database.

### `BAYARCASH_STORE_RECORDS=true` (default) — stateful

Transactions and mandates are recorded in the `bayarcash_transactions` and
`bayarcash_mandates` tables. This is what you get:

| Capability | What happens |
|---|---|
| **Pending row on `charge()`** | A `BayarcashTransaction` is created, linked to the payable model (`$order->payments()`), storing the `payment_intent_id` so the callback can complete the *same* row. |
| **Automatic webhook writes** | Callbacks update the record's `status`, set `paid_at` on success, and store the verified payload in `raw_callback`. |
| **Queryable history** | `$order->payments()->successful()`, `->pending()`, status labels, reporting — no extra API calls. |
| **Reconciliation** | `bayarcash:reconcile` can re-query and auto-cancel stale pending payments (it needs the stored rows to know what to reconcile). |

Requires the migrations:

```bash
php artisan vendor:publish --tag=bayarcash-migrations
php artisan migrate
```

Use this mode when you want an authoritative local record of payments (reporting,
reconciliation, linking payments to your models) — the typical SaaS setup.

### `BAYARCASH_STORE_RECORDS=false` — stateless (pass-through)

The package becomes a thin SDK wrapper. `charge()` / `enrollDirectDebit()` create
the intent and **return it without touching the database**, and the callback/return
routes still **verify checksums and fire events** — they just skip persistence.
No migrations are needed, and `bayarcash:reconcile` is disabled. Use this when you
already store payment state yourself and only want checksum-safe request/callback
handling.

## Multi-tenant (credentials in the database)

In a SaaS app each tenant has its own Bayarcash account. Store each tenant's
credentials in your own table and let the package resolve them per request. There
is **one shared webhook** for every tenant — no tenant id in the URL.

### 1. Store per-tenant credentials

The package ships an encrypted `bayarcash_accounts` table for this. Publish and run it:

```bash
php artisan vendor:publish --tag=bayarcash-tenant-migrations
php artisan migrate
```

Then store each tenant's credentials — `token` and `secret_key` are encrypted at rest:

```php
use Bayarcash\Laravel\Models\BayarcashAccount;

BayarcashAccount::create([
    'tenant_id'  => $tenant->id,
    'token'      => $token,
    'secret_key' => $secretKey,
    'sandbox'    => false,
]);
```

### 2. Turn multi-tenant on

Point the package at its built-in resolver and enable multi-tenant:

```php
// config/bayarcash.php
'credential_resolver' => \Bayarcash\Laravel\DatabaseCredentialResolver::class,
```

```dotenv
BAYARCASH_MULTI_TENANT=true
```

**Credentials already elsewhere?** If your tenants' Bayarcash credentials live on
your own model/table, skip the migration and implement the resolver yourself
instead — return `token`, `secret_key`, and `sandbox` for a tenant:

```php
use Bayarcash\Laravel\Contracts\CredentialResolver;

class MyCredentialResolver implements CredentialResolver
{
    public function resolve(mixed $tenant = null): array
    {
        $account = BayarcashAccount::where('tenant_id', $tenant)->firstOrFail();

        return [
            'token'      => $account->token,
            'secret_key' => $account->secret_key,
            'sandbox'    => (bool) $account->sandbox,
        ];
    }
}
```

### 3. Create payments per tenant

Pass the tenant to `charge()` / `enrollDirectDebit()`. The package generates the
checksum and calls the gateway with **that tenant's** credentials, and stamps the
stored row with `tenant_id`:

```php
$intent = $order->charge($data, tenant: $tenantId);
```

With no `tenant:` argument the default `.env` credentials are used — so single- and
multi-tenant code live side by side.

### 4. One shared webhook for every tenant

Point **every** tenant's portal Callback/Return URLs at the same package routes —
`https://your-app.test/bayarcash/callback` and `.../bayarcash/return`. There is no
per-tenant URL.

The package matches each callback to its local record, resolves **that tenant's**
secret, and verifies the checksum — rejecting with **`403`** (fail closed) when no
record matches. This lookup is why multi-tenant mode requires
`BAYARCASH_STORE_RECORDS=true`.

## The facade

For direct, lower-level access to the SDK:

```php
use Bayarcash\Laravel\Facades\Bayarcash;

$portals      = Bayarcash::getPortals();
$transaction  = Bayarcash::getTransaction('transaction_id');
$intent       = Bayarcash::sdk()->getPaymentIntent('payment_intent_id');
```

The facade proxies to the underlying `Bayarcash\Bayarcash` client (pinned to API v3).

## Error handling

SDK calls throw typed exceptions you can catch around `charge()` /
`enrollDirectDebit()`:

```php
use Bayarcash\Exceptions\ValidationException;
use Bayarcash\Exceptions\FailedActionException;

try {
    $intent = $order->charge([...]);
} catch (ValidationException $e) {
    $errors = $e->errors(); // 422
} catch (FailedActionException $e) {
    report($e); // 400
}
```

## Testing

```bash
composer install
vendor/bin/phpunit
```

## License

The MIT License (MIT).
