<?php

namespace Bayarcash\Laravel;

use Bayarcash\Fpx;
use Bayarcash\Laravel\Events\PaymentCancelled;
use Bayarcash\Laravel\Events\PaymentFailed;
use Bayarcash\Laravel\Events\PaymentSucceeded;
use Bayarcash\Laravel\Models\BayarcashTransaction;

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

        $terminal = [Fpx::STATUS_SUCCESS, Fpx::STATUS_FAILED, Fpx::STATUS_CANCELLED];
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

        match ($incoming) {
            Fpx::STATUS_SUCCESS   => PaymentSucceeded::dispatch($transaction),
            Fpx::STATUS_FAILED    => PaymentFailed::dispatch($transaction),
            Fpx::STATUS_CANCELLED => PaymentCancelled::dispatch($transaction),
            default               => null,
        };
    }
}
