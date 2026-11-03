<?php

namespace Bayarcash\Laravel\Models;

use Bayarcash\FpxDirectDebit;
use Bayarcash\Laravel\Exceptions\MandateNotCancellable;
use Bayarcash\Laravel\MandateRecorder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class BayarcashMandate extends Model
{
    protected $table = 'bayarcash_mandates';

    protected $guarded = [];

    protected $casts = [
        'amount'         => 'decimal:2',
        'status'         => 'integer',
        'payer_id_type'  => 'integer',
        'metadata'       => 'array',
        'effective_date' => 'date',
        'expiry_date'    => 'date',
    ];

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(config('bayarcash.models.transaction', BayarcashTransaction::class), 'mandate_id', 'mandate_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', FpxDirectDebit::STATUS_ACTIVE);
    }

    public function statusLabel(): string
    {
        return FpxDirectDebit::getStatusText((int) $this->status);
    }

    public function cancel(): static
    {
        if (empty($this->mandate_id)) {
            throw new MandateNotCancellable('This mandate has no Bayarcash mandate id yet, so it cannot be cancelled.');
        }

        $sdk = app('bayarcash.manager')->for($this->tenant_id ?: null);
        $status = (int) ($sdk->get('mandates/' . $this->mandate_id)['status'] ?? -1);

        if ($status === FpxDirectDebit::STATUS_ACTIVE) {
            $sdk->deactivateFpxDirectDebit($this->mandate_id);
            $status = FpxDirectDebit::STATUS_INACTIVE;
        } elseif (! in_array($status, MandateRecorder::STOPPED, true)) {
            // Never mark it cancelled here while Bayarcash may still deduct from it.
            throw new MandateNotCancellable(
                "This mandate is still waiting for the bank (Bayarcash status {$status}), so it cannot be cancelled yet."
            );
        }

        return app(MandateRecorder::class)->transition($this, $status);
    }
}
