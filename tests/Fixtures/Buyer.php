<?php

namespace Bayarcash\Laravel\Tests\Fixtures;

use Bayarcash\Laravel\Concerns\HasBayarcashPayments;
use Illuminate\Database\Eloquent\Model;

class Buyer extends Model
{
    use HasBayarcashPayments;

    protected $table = 'buyers';

    protected $guarded = [];
}
