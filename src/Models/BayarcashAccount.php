<?php

namespace Bayarcash\Laravel\Models;

use Illuminate\Database\Eloquent\Model;

class BayarcashAccount extends Model
{
    protected $guarded = [];

    protected $casts = [
        'token'      => 'encrypted',
        'secret_key' => 'encrypted',
        'sandbox'    => 'boolean',
    ];
}
