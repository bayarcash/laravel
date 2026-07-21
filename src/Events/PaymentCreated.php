<?php

namespace Bayarcash\Laravel\Events;

use Bayarcash\Laravel\Models\BayarcashTransaction;
use Illuminate\Foundation\Events\Dispatchable;

class PaymentCreated
{
    use Dispatchable;

    public function __construct(public BayarcashTransaction $transaction)
    {
    }
}
