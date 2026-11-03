<?php

namespace Bayarcash\Laravel;

use Bayarcash\Bayarcash;
use Bayarcash\Fpx;
use Bayarcash\Laravel\Events\PaymentCancelled;
use Bayarcash\Laravel\Events\PaymentFailed;
use Bayarcash\Laravel\Events\PaymentSucceeded;
use Bayarcash\Laravel\Http\CallbackType;
use Bayarcash\Laravel\Models\BayarcashMandate;
use Bayarcash\Laravel\Models\BayarcashTransaction;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Shared upsert, guards and event dispatch for transaction results. The callback
 * (authoritative), return (best-effort) and reconcile (requery) paths all funnel
 * through record() so matching, guards and events behave identically.
 */
class PaymentRecorder
{
    /**
     * @param  array<string, mixed>  $data
     * @param  string  $via  One of: callback, return, requery.
     * @param  string|null  $tenantId  Owning tenant, stamped when multi-tenant.
     */
    public function record(array $data, string $via, ?string $tenantId = null): BayarcashTransaction
    {
        /** @var class-string<BayarcashTransaction> $model */
        $model = config('bayarcash.models.transaction', BayarcashTransaction::class);

        $status = isset($data['status']) && $data['status'] !== null ? (int) $data['status'] : null;
        $attributes = $this->attributes($data, $status, $via);

        if (CallbackType::of($data) === 'deduction') {
            $attributes += $this->deductionDefaults($data);

            if (isset($attributes['paid_at'])) {
                $attributes['paid_at'] = $this->deductedAt($data) ?? $attributes['paid_at'];
            }
        }

        if ($tenantId !== null) {
            $attributes['tenant_id'] = $tenantId;
        }

        // Stateless mode: never touch the DB, but still fire events.
        if (! config('bayarcash.store_records')) {
            $transaction = new $model($attributes);
            $this->fireEvent($transaction, null, $status);

            return $transaction;
        }

        $transaction = $this->match($model, $data);
        $previousStatus = $transaction && $transaction->exists && $transaction->status !== null
            ? (int) $transaction->status
            : null;

        if ($transaction && $transaction->exists) {
            if ($this->shouldSkip($previousStatus, $status)) {
                return $transaction;
            }

            // A re-delivered callback must not move paid_at or overwrite a known channel.
            foreach (['paid_at', 'payment_channel'] as $kept) {
                if ($transaction->{$kept} !== null) {
                    unset($attributes[$kept]);
                }
            }

            $transaction->fill($attributes)->save();
        } else {
            $transaction = $model::create($attributes);
        }

        $this->fireEvent($transaction, $previousStatus, $status);

        return $transaction;
    }

    /**
     * Match an existing row: pending intent → transaction id → order number.
     *
     * @param  class-string<BayarcashTransaction>  $model
     * @param  array<string, mixed>  $data
     */
    protected function match(string $model, array $data): ?BayarcashTransaction
    {
        // A mandate's deductions share one order number, so never match them on it.
        if (CallbackType::of($data) === 'deduction') {
            return $this->matchDeduction($model, $data);
        }

        if (! empty($data['payment_intent_id'])) {
            $pending = $model::query()
                ->where('payment_intent_id', $data['payment_intent_id'])
                ->whereNull('transaction_id')
                ->first();

            if ($pending) {
                return $pending;
            }
        }

        if (! empty($data['transaction_id'])) {
            $byTransaction = $model::query()
                ->where('transaction_id', $data['transaction_id'])
                ->first();

            if ($byTransaction) {
                return $byTransaction;
            }
        }

        if (! empty($data['order_number'])) {
            return $model::query()
                ->where('order_number', $data['order_number'])
                ->first();
        }

        return null;
    }

    protected function matchDeduction(string $model, array $data): ?BayarcashTransaction
    {
        if (! empty($data['transaction_id'])) {
            $byTransaction = $model::query()->where('transaction_id', $data['transaction_id'])->first();

            if ($byTransaction) {
                return $byTransaction;
            }
        }

        if (empty($data['reference_number'])) {
            return null;
        }

        // The callback and the sync's deduction list always share the reference.
        return $model::query()
            ->where('mandate_id', $data['mandate_id'])
            ->where('exchange_reference_number', $data['reference_number'])
            ->first();
    }

    /**
     * Build the attribute set to persist, dropping absent values so an update
     * never nulls out data already on the row.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function attributes(array $data, ?int $status, string $via): array
    {
        $attributes = array_filter([
            'transaction_id'            => $data['transaction_id'] ?? null,
            'payment_intent_id'         => $data['payment_intent_id'] ?? null,
            'mandate_id'                => $data['mandate_id'] ?? null,
            'cycle'                     => $data['cycle'] ?? null,
            'payment_channel'           => isset($data['payment_gateway_id']) && $data['payment_gateway_id'] !== ''
                ? (int) $data['payment_gateway_id']
                : null,
            'exchange_reference_number' => $data['exchange_reference_number'] ?? null,
            'order_number'              => $data['order_number'] ?? null,
            'currency'                  => $data['currency'] ?? null,
            'amount'                    => $data['amount'] ?? null,
            'payer_name'                => $data['payer_name'] ?? null,
            'payer_email'               => $data['payer_email'] ?? null,
            'payer_telephone_number'    => $data['payer_telephone_number'] ?? null,
            'payer_bank_name'           => $data['payer_bank_name'] ?? null,
            'status_description'        => $data['status_description'] ?? null,
        ], fn ($value) => $value !== null);

        if ($status !== null) {
            $attributes['status'] = $status;
        }

        if ($status === Fpx::STATUS_SUCCESS) {
            $attributes['paid_at'] = now();
        }

        // Only the server-to-server callback payload is trusted for storage.
        if ($via === 'callback') {
            $attributes['raw_callback'] = $data;
        }

        return $attributes;
    }

    /**
     * Fill a deduction's gaps from its mandate: the payload carries no owner,
     * order number or payer details.
     *
     * @return array<string, mixed>
     */
    protected function deductionDefaults(array $data): array
    {
        /** @var class-string<BayarcashMandate> $model */
        $model = config('bayarcash.models.mandate', BayarcashMandate::class);

        $mandate = config('bayarcash.store_records')
            ? $model::query()->where('mandate_id', $data['mandate_id'])->first()
            : null;

        return array_filter([
            'payment_channel'           => Bayarcash::FPX_DIRECT_DEBIT,
            'exchange_reference_number' => $data['reference_number'] ?? null,
            'order_number'              => $mandate?->order_number ?? $data['mandate_reference_number'] ?? $data['mandate_id'],
            'owner_type'                => $mandate?->owner_type,
            'owner_id'                  => $mandate?->owner_id,
            'currency'                  => $mandate?->currency,
            'payer_name'                => $mandate?->payer_name,
            'payer_email'               => $mandate?->payer_email,
            'payer_telephone_number'    => $mandate?->payer_telephone_number,
        ], fn ($value) => $value !== null);
    }

    // Bayarcash sends the deduction date in Malaysia time; it can be days before the callback.
    protected function deductedAt(array $data): ?Carbon
    {
        if (empty($data['datetime'])) {
            return null;
        }

        try {
            return Carbon::parse($data['datetime'], 'Asia/Kuala_Lumpur')->setTimezone(config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Guard: never downgrade a successful row, and ignore a new/pending
     * incoming status once the row is already terminal.
     */
    protected function shouldSkip(?int $previous, ?int $incoming): bool
    {
        if ($previous === null) {
            return false;
        }

        if ($incoming === null) {
            return true;
        }

        if ($previous === Fpx::STATUS_SUCCESS && $incoming !== Fpx::STATUS_SUCCESS) {
            return true;
        }

        $terminal = [Fpx::STATUS_SUCCESS, Fpx::STATUS_FAILED, Fpx::STATUS_CANCELLED, Fpx::STATUS_ABANDONED];
        $transient = [Fpx::STATUS_NEW, Fpx::STATUS_PENDING];

        if (in_array($previous, $terminal, true) && in_array($incoming, $transient, true)) {
            return true;
        }

        return false;
    }

    /**
     * Fire the status-specific event only on an actual transition.
     */
    protected function fireEvent(BayarcashTransaction $transaction, ?int $previous, ?int $incoming): void
    {
        if ($incoming === null || $incoming === $previous) {
            return;
        }

        // A cancelled deduction is a deleted billing file, not a payer cancellation.
        if ($incoming === Fpx::STATUS_CANCELLED && $transaction->isDirectDebit()) {
            return;
        }

        match ($incoming) {
            Fpx::STATUS_SUCCESS   => PaymentSucceeded::dispatch($transaction),
            Fpx::STATUS_FAILED    => PaymentFailed::dispatch($transaction),
            Fpx::STATUS_CANCELLED,
            Fpx::STATUS_ABANDONED => PaymentCancelled::dispatch($transaction),
            default               => null,
        };
    }
}
