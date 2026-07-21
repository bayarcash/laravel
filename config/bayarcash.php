<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API Credentials
    |--------------------------------------------------------------------------
    |
    | Your Bayarcash Personal Access Token (PAT) and API Secret Key. Both are
    | issued from the Bayarcash console. The secret key is used to generate
    | and verify checksums for payloads and callbacks.
    |
    */

    'token'      => env('BAYARCASH_API_TOKEN'),
    'secret_key' => env('BAYARCASH_API_SECRET_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    |
    | When "sandbox" is true the package talks to the Bayarcash sandbox. This
    | package targets Bayarcash API v3 only; the SDK is always set to v3.
    |
    */

    'sandbox' => env('BAYARCASH_SANDBOX', false),
    'timeout' => env('BAYARCASH_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Persistence
    |--------------------------------------------------------------------------
    |
    | When false the package behaves as a stateless SDK wrapper: the trait
    | helpers and callback/return handlers verify and fire events but never
    | read from or write to the database.
    |
    */

    'persistence' => env('BAYARCASH_PERSISTENCE', true),

    /*
    |--------------------------------------------------------------------------
    | Callback URL (POST, server-to-server, authoritative)
    |--------------------------------------------------------------------------
    |
    | Point the Bayarcash portal "Callback URL" at this path. Callbacks are
    | verified by checksum and are the authoritative source of truth. A bad
    | checksum aborts with 403.
    |
    */

    'callback' => [
        'enabled'    => true,
        'path'       => env('BAYARCASH_CALLBACK_PATH', 'bayarcash/callback'),
        'middleware' => ['api'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Return URL (GET, browser redirect, best-effort)
    |--------------------------------------------------------------------------
    |
    | Point the Bayarcash portal "Return URL" at this path. The return handler
    | verifies the checksum when present but never aborts. "redirect" is a
    | named route or URL to settle the browser on; when null a JSON response
    | is returned instead.
    |
    */

    'return' => [
        'enabled'  => true,
        'path'     => env('BAYARCASH_RETURN_PATH', 'bayarcash/return'),
        'redirect' => env('BAYARCASH_RETURN_REDIRECT'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reconciliation (scheduled)
    |--------------------------------------------------------------------------
    |
    | Recovers payments whose callback/return was missed. When enabled the
    | package schedules "bayarcash:reconcile" to run every minute. Pending
    | rows older than "requery_after" minutes are re-queried; rows still
    | pending after "cancel_after" minutes are auto-cancelled.
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
    | Override these if your application extends the packaged Eloquent models.
    |
    */

    'models' => [
        'transaction' => \Bayarcash\Laravel\Models\BayarcashTransaction::class,
        'mandate'     => \Bayarcash\Laravel\Models\BayarcashMandate::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Credential Resolver (multi-tenant seam)
    |--------------------------------------------------------------------------
    |
    | Optional class implementing Bayarcash\Laravel\Contracts\CredentialResolver
    | used to resolve per-tenant credentials. Leave null to use the config
    | credentials above.
    |
    */

    'credential_resolver' => null,

];
