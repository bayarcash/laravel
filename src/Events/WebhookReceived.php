<?php

namespace Bayarcash\Laravel\Events;

use Illuminate\Foundation\Events\Dispatchable;

class WebhookReceived
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public string $recordType, public array $payload)
    {
    }
}
