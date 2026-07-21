<?php

namespace Bayarcash\Laravel\Events;

use Bayarcash\Laravel\Models\BayarcashTransaction;
use Illuminate\Foundation\Events\Dispatchable;

class PaymentCancelled
{
    use Dispatchable;

    public function __construct(public BayarcashTransaction $transaction)
    {
    }
}
