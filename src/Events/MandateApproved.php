<?php

namespace Bayarcash\Laravel\Events;

use Bayarcash\Laravel\Models\BayarcashMandate;
use Illuminate\Foundation\Events\Dispatchable;

class MandateApproved
{
    use Dispatchable;

    public function __construct(public BayarcashMandate $mandate)
    {
    }
}
