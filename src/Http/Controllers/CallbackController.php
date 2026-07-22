<?php

namespace Bayarcash\Laravel\Http\Controllers;

use Bayarcash\Laravel\Events\MandateApproved;
use Bayarcash\Laravel\Events\MandateAuthorized;
use Bayarcash\Laravel\Events\WebhookReceived;
use Bayarcash\Laravel\Models\BayarcashMandate;
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
    public function __construct(protected PaymentRecorder $recorder)
    {
    }

    public function __invoke(Request $request): Response
    {
        $data = $request->all();
        $recordType = $data['record_type'] ?? 'transaction';
        $tenantId = $request->attributes->get('bayarcash_tenant');

        WebhookReceived::dispatch($recordType, $data);

        $handle = fn () => match ($recordType) {
            'transaction'                    => $this->recorder->record($data, 'callback', $tenantId),
            'bank_approval', 'authorization' => $this->handleMandate($data, $recordType, $tenantId),
            default                          => null, // pre_transaction & others: acknowledged only
        };

        // Guard against concurrent deliveries of the same order double-processing
        // the record. Falls back to running directly when no order_number exists.
        $orderNumber = $data['order_number'] ?? null;

        if ($orderNumber !== null && $orderNumber !== '') {
            Cache::lock('bayarcash-callback:' . $orderNumber, 10)->get($handle);
        } else {
            $handle();
        }

        return response('OK', 200);
    }

    /**
     * Upsert a mandate (idempotent on mandate_id) and fire the mandate event.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleMandate(array $data, string $recordType, ?string $tenantId = null): void
    {
        if (empty($data['mandate_id'])) {
            return;
        }

        /** @var class-string<BayarcashMandate> $model */
        $model = config('bayarcash.models.mandate', BayarcashMandate::class);

        $attributes = array_filter([
            'mandate_reference_number' => $data['mandate_reference_number'] ?? null,
            'order_number'             => $data['order_number'] ?? null,
            'amount'                   => $data['amount'] ?? null,
            'currency'                 => $data['currency'] ?? null,
            'payer_name'               => $data['payer_name'] ?? null,
            'payer_email'              => $data['payer_email'] ?? null,
            'payer_telephone_number'   => $data['payer_telephone_number'] ?? null,
            'application_type'         => $data['application_type'] ?? null,
            'status_description'       => $data['status_description'] ?? null,
        ], fn ($value) => $value !== null);

        if (isset($data['status']) && $data['status'] !== null) {
            $attributes['status'] = (int) $data['status'];
        }

        if ($tenantId !== null) {
            $attributes['tenant_id'] = $tenantId;
        }

        if (config('bayarcash.store_records')) {
            $mandate = $model::updateOrCreate(['mandate_id' => $data['mandate_id']], $attributes);
        } else {
            $mandate = new $model(['mandate_id' => $data['mandate_id']] + $attributes);
        }

        $recordType === 'authorization'
            ? MandateAuthorized::dispatch($mandate)
            : MandateApproved::dispatch($mandate);
    }
}
