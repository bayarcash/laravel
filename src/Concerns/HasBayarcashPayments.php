<?php

namespace Bayarcash\Laravel\Concerns;

use Bayarcash\Fpx;
use Bayarcash\FpxDirectDebit;
use Bayarcash\Laravel\Events\PaymentCreated;
use Bayarcash\Laravel\Models\BayarcashMandate;
use Bayarcash\Laravel\Models\BayarcashTransaction;
use Bayarcash\Resources\FpxDirectDebitApplicationResource;
use Bayarcash\Resources\PaymentIntentResource;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

/**
 * Makes any Eloquent model a Bayarcash payer.
 */
trait HasBayarcashPayments
{
    public function payments(): MorphMany
    {
        return $this->morphMany(
            config('bayarcash.models.transaction', BayarcashTransaction::class),
            'owner'
        );
    }

    public function mandates(): MorphMany
    {
        return $this->morphMany(
            config('bayarcash.models.mandate', BayarcashMandate::class),
            'owner'
        );
    }

    /**
     * Create a payment intent for this model and persist a pending row.
     *
     * Pass $tenant to charge with a specific tenant's credentials (multi-tenant
     * SaaS). When null the default .env credentials are used — identical to the
     * single-merchant behaviour.
     *
     * @param  array<string, mixed>  $data
     */
    public function charge(array $data, mixed $tenant = null): PaymentIntentResource
    {
        $manager = app('bayarcash.manager');
        $sdk = $tenant !== null ? $manager->for($tenant) : $manager->sdk();
        $secret = $manager->secretKey($tenant);

        $data['order_number'] ??= $this->generateOrderNumber();
        $data['checksum'] ??= $sdk->createPaymentIntentChecksumValue($secret, $data);

        $intent = $sdk->createPaymentIntent($data);

        if (config('bayarcash.store_records')) {
            $transaction = $this->payments()->create([
                'tenant_id'              => $tenant === null ? null : (string) $tenant,
                'payment_intent_id'      => $intent->id ?? null,
                'order_number'           => $data['order_number'],
                'payment_channel'        => $this->normalizeChannel($data['payment_channel'] ?? null),
                'amount'                 => $data['amount'] ?? null,
                'currency'               => $data['currency'] ?? null,
                'payer_name'             => $data['payer_name'] ?? null,
                'payer_email'            => $data['payer_email'] ?? null,
                'payer_telephone_number' => $data['payer_telephone_number'] ?? null,
                'status'                 => Fpx::STATUS_NEW,
                'metadata'               => $data['metadata'] ?? null,
            ]);

            PaymentCreated::dispatch($transaction);
        }

        return $intent;
    }

    /**
     * Enrol this model in a Direct Debit mandate and persist a pending row.
     *
     * Pass $tenant to enrol with a specific tenant's credentials; null uses the
     * default .env credentials (single-merchant behaviour).
     *
     * @param  array<string, mixed>  $data
     */
    public function enrollDirectDebit(array $data, mixed $tenant = null): FpxDirectDebitApplicationResource
    {
        $manager = app('bayarcash.manager');
        $sdk = $tenant !== null ? $manager->for($tenant) : $manager->sdk();
        $secret = $manager->secretKey($tenant);

        $data['order_number'] ??= $this->generateOrderNumber();
        $data['checksum'] ??= $sdk->createFpxDirectDebitEnrolmentChecksumValue($secret, $data);

        $mandate = $sdk->createFpxDirectDebitEnrollment($data);

        if (config('bayarcash.store_records')) {
            $this->mandates()->create([
                'tenant_id'              => $tenant === null ? null : (string) $tenant,
                'mandate_id'             => $mandate->id ?? null,
                'order_number'           => $data['order_number'],
                'amount'                 => $data['amount'] ?? null,
                'currency'               => $data['currency'] ?? null,
                'payer_name'             => $data['payer_name'] ?? null,
                'payer_id'               => $data['payer_id'] ?? null,
                'payer_id_type'          => $data['payer_id_type'] ?? null,
                'payer_email'            => $data['payer_email'] ?? null,
                'payer_telephone_number' => $data['payer_telephone_number'] ?? null,
                'application_type'       => FpxDirectDebit::ENROLMENT,
                'frequency_mode'         => $data['frequency_mode'] ?? null,
                'status'                 => FpxDirectDebit::STATUS_NEW,
                'metadata'               => $data['metadata'] ?? null,
            ]);
        }

        return $mandate;
    }

    protected function generateOrderNumber(): string
    {
        return 'INV-' . Str::upper(Str::random(16));
    }

    protected function normalizeChannel(mixed $channel): ?int
    {
        if (is_array($channel)) {
            $channel = $channel[0] ?? null;
        }

        return $channel === null ? null : (int) $channel;
    }
}
