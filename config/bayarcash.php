<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    |
    | Your Personal Access Token and API Secret Key from the Bayarcash console.
    | The secret key signs request checksums and verifies the checksums on
    | incoming callbacks.
    |
    */

    'token'      => env('BAYARCASH_API_TOKEN'),
    'secret_key' => env('BAYARCASH_API_SECRET_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    |
    | Enable "sandbox" to route requests to the Bayarcash sandbox. The SDK
    | always targets API v3. "timeout" is the HTTP request timeout in seconds.
    |
    */

    'sandbox' => env('BAYARCASH_SANDBOX', false),
    'timeout' => env('BAYARCASH_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Store Records
    |--------------------------------------------------------------------------
    |
    | When disabled the package acts as a stateless SDK wrapper: callbacks are
    | still verified and events still fire, but nothing is read from or written
    | to the database.
    |
    */

    'store_records' => env('BAYARCASH_STORE_RECORDS', true),

    /*
    |--------------------------------------------------------------------------
    | Callback URL
    |--------------------------------------------------------------------------
    |
    | The POST server-to-server callback is the authoritative source of truth.
    | Point your portal's "Callback URL" at this path; a bad checksum aborts
    | with a 403 response.
    |
    */

    'callback' => [
        'enabled'    => true,
        'path'       => env('BAYARCASH_CALLBACK_PATH', 'bayarcash/callback'),
        'middleware' => ['api'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Return URL
    |--------------------------------------------------------------------------
    |
    | The GET browser return is best-effort: it verifies the checksum when one
    | is present but never aborts. "redirect" is a named route or URL to send
    | the customer to; when null the route returns a JSON response.
    |
    */

    'return' => [
        'enabled'  => true,
        'path'     => env('BAYARCASH_RETURN_PATH', 'bayarcash/return'),
        'redirect' => env('BAYARCASH_RETURN_REDIRECT'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reconciliation
    |--------------------------------------------------------------------------
    |
    | Recovers payments whose callback or return was missed. When enabled the
    | "bayarcash:reconcile" command runs every minute: pending rows older than
    | "requery_after" minutes are re-queried, and rows still pending after
    | "cancel_after" minutes are auto-cancelled.
    |
    */

    'reconcile' => [
        'enabled'       => env('BAYARCASH_RECONCILE', true),
        'requery_after' => 2,
        'cancel_after'  => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Override these to use your own models that extend the packaged Eloquent
    | models.
    |
    */

    'models' => [
        'transaction' => \Bayarcash\Laravel\Models\BayarcashTransaction::class,
        'mandate'     => \Bayarcash\Laravel\Models\BayarcashMandate::class,
        'account'     => \Bayarcash\Laravel\Models\BayarcashAccount::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-tenant
    |--------------------------------------------------------------------------
    |
    | Provide a CredentialResolver to look up per-tenant credentials; leave it
    | null to use the config credentials above. With "multi_tenant" enabled,
    | every tenant shares the same callback/return URLs — the owning tenant is
    | matched from a locally stored record by order_number and verified with
    | that tenant's secret (an unmatched callback is rejected with 403). This
    | requires "store_records".
    |
    */

    'credential_resolver' => null,

    'multi_tenant' => env('BAYARCASH_MULTI_TENANT', false),

];
