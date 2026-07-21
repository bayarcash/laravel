<?php

namespace Bayarcash\Laravel\Models;

use Bayarcash\Fpx;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class BayarcashTransaction extends Model
{
    protected $table = 'bayarcash_transactions';

    protected $guarded = [];

    protected $casts = [
        'amount'       => 'decimal:2',
        'status'       => 'integer',
        'metadata'     => 'array',
        'raw_callback' => 'array',
        'paid_at'      => 'datetime',
    ];

    /**
     * The model that owns this transaction (payer).
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
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

    /**
     * Human-readable status label.
     */
    public function statusLabel(): string
    {
        return Fpx::getStatusText((int) $this->status);
    }
}
