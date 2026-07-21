<?php

namespace Bayarcash\Laravel\Http\Controllers;

use Bayarcash\Laravel\Models\BayarcashTransaction;
use Bayarcash\Laravel\PaymentRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Best-effort GET return handler. Verifies the checksum only when present and
 * never aborts, then settles the browser via redirect or JSON.
 */
class ReturnController
{
    public function __construct(protected PaymentRecorder $recorder)
    {
    }

    public function __invoke(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->query();

        $transaction = $this->settle($data);

        $redirect = config('bayarcash.return.redirect');

        if ($redirect) {
            $url = Route::has($redirect) ? route($redirect) : $redirect;

            $response = redirect()->to($url);

            // Flash the settled transaction only when a session is available
            // (the return route ships without session middleware by default).
            if ($transaction && $request->hasSession()) {
                $response->with('bayarcash_transaction', $transaction);
            }

            return $response;
        }

        return response()->json([
            'transaction' => $transaction,
            'status'      => $transaction?->status ?? ($data['status'] ?? null),
        ]);
    }

    /**
     * Record the return payload only when its checksum verifies. Never aborts.
     *
     * @param  array<string, mixed>  $data
     */
    protected function settle(array $data): ?BayarcashTransaction
    {
        if (empty($data['checksum'])) {
            return null;
        }

        $manager = app('bayarcash.manager');

        if (! $manager->sdk()->verifyReturnUrlCallbackData($data, $manager->secretKey())) {
            return null;
        }

        return $this->recorder->record($data, 'return');
    }
}
