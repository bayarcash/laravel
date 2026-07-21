<?php

namespace Bayarcash\Laravel\Tests\Unit;

use Bayarcash\Laravel\Events\MandateApproved;
use Bayarcash\Laravel\Events\MandateAuthorized;
use Bayarcash\Laravel\Events\PaymentCancelled;
use Bayarcash\Laravel\Events\PaymentCreated;
use Bayarcash\Laravel\Events\PaymentFailed;
use Bayarcash\Laravel\Events\PaymentSucceeded;
use Bayarcash\Laravel\Events\WebhookReceived;
use Bayarcash\Laravel\Models\BayarcashMandate;
use Bayarcash\Laravel\Models\BayarcashTransaction;
use Bayarcash\Laravel\Tests\TestCase;

class EventsTest extends TestCase
{
    public function test_payment_events_carry_their_transaction(): void
    {
        $trx = new BayarcashTransaction(['order_number' => 'INV-1']);

        $this->assertSame($trx, (new PaymentCreated($trx))->transaction);
        $this->assertSame($trx, (new PaymentSucceeded($trx))->transaction);
        $this->assertSame($trx, (new PaymentFailed($trx))->transaction);
        $this->assertSame($trx, (new PaymentCancelled($trx))->transaction);
    }

    public function test_mandate_events_carry_their_mandate(): void
    {
        $mandate = new BayarcashMandate(['order_number' => 'INV-1']);

        $this->assertSame($mandate, (new MandateAuthorized($mandate))->mandate);
        $this->assertSame($mandate, (new MandateApproved($mandate))->mandate);
    }

    public function test_webhook_received_carries_type_and_payload(): void
    {
        $event = new WebhookReceived('transaction', ['a' => 1]);

        $this->assertSame('transaction', $event->recordType);
        $this->assertSame(['a' => 1], $event->payload);
    }
}
