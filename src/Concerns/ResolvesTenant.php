<?php

namespace Bayarcash\Laravel\Concerns;

use Bayarcash\Laravel\Models\BayarcashMandate;
use Bayarcash\Laravel\Models\BayarcashTransaction;

/**
 * Resolves the owning tenant for an incoming callback/return payload. With one
 * shared webhook the tenant isn't in the URL, so it is discovered by matching the
 * payload to a locally stored record; when none matches, false is returned so
 * callers fail closed — we cannot know which secret to trust.
 */
trait ResolvesTenant
{
    /**
     * @param  array<string, mixed>  $data
     * @return string|null|false  The owning tenant id (nullable), or false when
     *                            no local record matched the payload.
     */
    protected function resolveTenantFromPayload(array $data): string|null|false
    {
        $recordType = $data['record_type'] ?? 'transaction';

        $record = in_array($recordType, ['bank_approval', 'authorization'], true)
            ? $this->findMandateRecord($data)
            : $this->findTransactionRecord($data);

        if ($record === null) {
            return false;
        }

        return $record->tenant_id === null ? null : (string) $record->tenant_id;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function findTransactionRecord(array $data): ?BayarcashTransaction
    {
        /** @var class-string<BayarcashTransaction> $model */
        $model = config('bayarcash.models.transaction', BayarcashTransaction::class);

        if (! empty($data['payment_intent_id'])) {
            $row = $model::query()->where('payment_intent_id', $data['payment_intent_id'])->first();

            if ($row) {
                return $row;
            }
        }

        if (! empty($data['order_number'])) {
            return $model::query()->where('order_number', $data['order_number'])->latest('id')->first();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function findMandateRecord(array $data): ?BayarcashMandate
    {
        /** @var class-string<BayarcashMandate> $model */
        $model = config('bayarcash.models.mandate', BayarcashMandate::class);

        if (! empty($data['mandate_id'])) {
            $row = $model::query()->where('mandate_id', $data['mandate_id'])->first();

            if ($row) {
                return $row;
            }
        }

        if (! empty($data['order_number'])) {
            return $model::query()->where('order_number', $data['order_number'])->latest('id')->first();
        }

        return null;
    }
}
