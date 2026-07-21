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
    /**
     * Transactions owned by this model.
     */
    public function payments(): MorphMany
    {
        return $this->morphMany(
            config('bayarcash.models.transaction', BayarcashTransaction::class),
            'owner'
        );
    }

    /**
     * Direct Debit mandates owned by this model.
     */
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
     * @param  array<string, mixed>  $data
     */
    public function charge(array $data): PaymentIntentResource
    {
        $manager = app('bayarcash.manager');

        $data['order_number'] ??= $this->generateOrderNumber();
        $data['checksum'] ??= $manager->sdk()->createPaymentIntentChecksumValue($manager->secretKey(), $data);

        $intent = $manager->sdk()->createPaymentIntent($data);

        if (config('bayarcash.persistence')) {
            $transaction = $this->payments()->create([
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
     * @param  array<string, mixed>  $data
     */
    public function enrollDirectDebit(array $data): FpxDirectDebitApplicationResource
    {
        $manager = app('bayarcash.manager');

        $data['order_number'] ??= $this->generateOrderNumber();
        $data['checksum'] ??= $manager->sdk()->createFpxDirectDebitEnrolmentChecksumValue($manager->secretKey(), $data);

        $mandate = $manager->sdk()->createFpxDirectDebitEnrollment($data);

        if (config('bayarcash.persistence')) {
            $this->mandates()->create([
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

    /**
     * Generate an order number when the caller does not supply one.
     */
    protected function generateOrderNumber(): string
    {
        return 'INV-' . Str::upper(Str::random(16));
    }

    /**
     * Normalise the payment channel to a single int for storage.
     */
    protected function normalizeChannel(mixed $channel): ?int
    {
        if (is_array($channel)) {
            $channel = $channel[0] ?? null;
        }

        return $channel === null ? null : (int) $channel;
    }
}
