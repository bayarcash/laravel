<?php

namespace Bayarcash\Laravel\Models;

use Bayarcash\FpxDirectDebit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', FpxDirectDebit::STATUS_ACTIVE);
    }

    public function statusLabel(): string
    {
        return FpxDirectDebit::getStatusText((int) $this->status);
    }
}
