<?php

namespace Bayarcash\Laravel\Models;

use Bayarcash\Bayarcash;
use Bayarcash\Fpx;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class BayarcashTransaction extends Model
{
    protected $table = 'bayarcash_transactions';

    protected $guarded = [];

    protected $casts = [
        'amount'          => 'decimal:2',
        'status'          => 'integer',
        'payment_channel' => 'integer',
        'cycle'           => 'integer',
        'metadata'        => 'array',
        'raw_callback'    => 'array',
        'paid_at'         => 'datetime',
    ];

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function mandate(): BelongsTo
    {
        return $this->belongsTo(config('bayarcash.models.mandate', BayarcashMandate::class), 'mandate_id', 'mandate_id');
    }

    public function scopeDirectDebit(Builder $query): Builder
    {
        return $query->where('payment_channel', Bayarcash::FPX_DIRECT_DEBIT);
    }

    public function isDirectDebit(): bool
    {
        return $this->payment_channel === Bayarcash::FPX_DIRECT_DEBIT;
    }

    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->where('status', Fpx::STATUS_SUCCESS);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', [Fpx::STATUS_NEW, Fpx::STATUS_PENDING]);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', Fpx::STATUS_FAILED);
    }

    public function statusLabel(): string
    {
        return Fpx::getStatusText((int) $this->status);
    }
}
