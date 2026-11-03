<?php

namespace Bayarcash\Laravel\Events;

use Bayarcash\Laravel\Models\BayarcashMandate;
use Illuminate\Foundation\Events\Dispatchable;

class MandateTerminated
{
    use Dispatchable;

    public function __construct(public BayarcashMandate $mandate)
    {
    }
}
