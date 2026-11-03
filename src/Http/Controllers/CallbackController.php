<?php

namespace Bayarcash\Laravel\Http\Controllers;

use Bayarcash\Laravel\Events\WebhookReceived;
use Bayarcash\Laravel\MandateRecorder;
use Bayarcash\Laravel\PaymentRecorder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Authoritative POST callback handler. The checksum has already been verified
 * by the VerifyBayarcashSignature middleware before this runs; in multi-tenant
 * mode that middleware also stashes the resolved tenant on the request.
 */
class CallbackController
{
    public function __construct(
        protected PaymentRecorder $recorder,
        protected MandateRecorder $mandates,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $data = $request->all();
        $recordType = $data['record_type'] ?? 'transaction';
        $tenantId = $request->attributes->get('bayarcash_tenant');

        WebhookReceived::dispatch($recordType, $data);

        $handle = fn () => match ($recordType) {
            'transaction'                    => $this->recorder->record($data, 'callback', $tenantId),
            'bank_approval', 'authorization' => $this->mandates->record($data, $recordType, $tenantId),
            default                          => null, // pre_transaction & others: acknowledged only
        };

        // Serialize duplicate deliveries; deductions have no order_number, so lock on transaction_id.
        $lockKey = $data['order_number'] ?? $data['transaction_id'] ?? null;

        if ($lockKey !== null && $lockKey !== '') {
            Cache::lock('bayarcash-callback:' . $lockKey, 10)->get($handle);
        } else {
            $handle();
        }

        return response('OK', 200);
    }
}
