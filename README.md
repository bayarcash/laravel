# Bayarcash for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/bayarcash/laravel.svg?style=flat-square)](https://packagist.org/packages/bayarcash/laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/bayarcash/laravel.svg?style=flat-square)](https://packagist.org/packages/bayarcash/laravel)
[![PHP Version](https://img.shields.io/packagist/php-v/bayarcash/laravel.svg?style=flat-square)](https://packagist.org/packages/bayarcash/laravel)
[![License](https://img.shields.io/packagist/l/bayarcash/laravel.svg?style=flat-square)](https://packagist.org/packages/bayarcash/laravel)
[![Tests](https://github.com/bayarcash/laravel/actions/workflows/tests.yml/badge.svg)](https://github.com/bayarcash/laravel/actions/workflows/tests.yml)

A Laravel integration for the [Bayarcash](https://bayar.cash) payment gateway. It wraps the framework-agnostic [`bayarcash/php-sdk`](https://packagist.org/packages/bayarcash/php-sdk) and adds a Laravel-idiomatic developer experience: config, a facade, a model trait, optional database persistence, automatic callback/return handling with checksum verification, scheduled reconciliation, and events.

Targets **Bayarcash API v3**.

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
BAYARCASH_PERSISTENCE=true
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

The checksum is generated for you. When persistence is enabled, a pending
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

Reconciliation requires persistence.

## Stateless mode

Set `BAYARCASH_PERSISTENCE=false` (or simply do not publish the migrations) to
use the package as a thin SDK wrapper. In this mode `charge()` /
`enrollDirectDebit()` return the SDK resource without writing to the database,
and the callback/return handlers still verify checksums and fire events — they
just skip persistence.

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
