<?php

namespace Bayarcash\Laravel\Tests\Feature;

use Bayarcash\Fpx;
use Bayarcash\Laravel\Events\PaymentCreated;
use Bayarcash\Laravel\Models\BayarcashTransaction;
use Bayarcash\Laravel\Tests\Fixtures\Buyer;
use Bayarcash\Laravel\Tests\Fixtures\FakeManager;
use Bayarcash\Laravel\Tests\TestCase;
use Bayarcash\Resources\FpxDirectDebitApplicationResource;
use Bayarcash\Resources\PaymentIntentResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

class HasBayarcashPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private object $stub;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('buyers', function ($table) {
            $table->id();
            $table->timestamps();
        });

        $this->stub = new class {
            public ?string $checksumSecret = null;

            public function createPaymentIntentChecksumValue($secret, $data): string
            {
                $this->checksumSecret = $secret;

                return 'sig';
            }

            public function createPaymentIntent($data): PaymentIntentResource
            {
                return new PaymentIntentResource(
                    ['id' => 'pi_1', 'url' => 'https://pay.test', 'status' => 'unpaid'] + $data
                );
            }

            public ?array $signedEnrolment = null;

            public ?array $sentEnrolment = null;

            public function createFpxDirectDebitEnrolmentChecksumValue($secret, $data): string
            {
                $this->signedEnrolment = $data;

                return 'sig';
            }

            public function createFpxDirectDebitEnrollment($data): FpxDirectDebitApplicationResource
            {
                $this->sentEnrolment = $data;

                return new FpxDirectDebitApplicationResource(['id' => 'md_1', 'url' => 'https://pay.test/md']);
            }
        };

        $this->app->instance('bayarcash.manager', new FakeManager(
            $this->stub,
            tenantSecrets: ['t1' => 'secret-t1'],
        ));
    }

    public function test_enrol_points_mandate_callbacks_at_the_callback_route_and_cleans_signed_fields(): void
    {
        $buyer = Buyer::create([]);

        $buyer->enrollDirectDebit([
            'portal_key'             => 'pk',
            'order_number'           => 'SUB-9',
            'amount'                 => '10.00',
            'payer_name'             => "Ahmad A/L Ali @ O'Neil",
            'payer_email'            => 'a@b.com',
            'payer_telephone_number' => '+60 12-345 6789',
            'payer_id_type'          => 1,
            'payer_id'               => '900101011234',
            'application_reason'     => 'Monthly',
            'frequency_mode'         => 'MT',
        ]);

        $this->assertSame(route('bayarcash.callback'), $this->stub->sentEnrolment['return_url']);
        $this->assertSame('Ahmad AL Ali  ONeil', $this->stub->signedEnrolment['payer_name']);
        $this->assertSame('0123456789', $this->stub->signedEnrolment['payer_telephone_number']);
        $this->assertSame('0123456789', $this->stub->sentEnrolment['payer_telephone_number']);
        $this->assertSame('0123456789', $buyer->mandates()->sole()->payer_telephone_number);
    }

    public function test_enrol_keeps_a_return_url_the_app_passes(): void
    {
        Buyer::create([])->enrollDirectDebit([
            'order_number' => 'SUB-8', 'amount' => '10.00', 'payer_name' => 'A', 'payer_email' => 'a@b.com',
            'payer_telephone_number' => '0123456789', 'payer_id_type' => 1, 'payer_id' => '900101011234',
            'application_reason' => 'Monthly', 'frequency_mode' => 'MT', 'return_url' => 'https://app.test/hook',
        ]);

        $this->assertSame('https://app.test/hook', $this->stub->sentEnrolment['return_url']);
    }

    public function test_charge_creates_intent_and_persists_pending_transaction(): void
    {
        Event::fake([PaymentCreated::class]);

        $buyer = Buyer::create([]);

        $intent = $buyer->charge([
            'portal_key'     => 'pk',
            'payment_channel' => 1,
            'order_number'   => 'INV-9',
            'amount'         => '10.00',
            'payer_name'     => 'A',
            'payer_email'    => 'a@b.com',
        ]);

        $this->assertSame('https://pay.test', $intent->url);
        $this->assertSame('pi_1', $intent->id);

        $this->assertCount(1, $buyer->payments()->get());

        $trx = $buyer->payments()->first();
        $this->assertInstanceOf(BayarcashTransaction::class, $trx);
        $this->assertSame('INV-9', $trx->order_number);
        $this->assertSame('pi_1', $trx->payment_intent_id);
        $this->assertSame(1, $trx->payment_channel);
        $this->assertSame(Fpx::STATUS_NEW, $trx->status);
        $this->assertTrue($buyer->is($trx->owner));

        Event::assertDispatched(PaymentCreated::class);
    }

    public function test_charge_autogenerates_order_number_when_absent(): void
    {
        $buyer = Buyer::create([]);

        $buyer->charge([
            'payment_channel' => 1, 'amount' => '10.00', 'payer_name' => 'A', 'payer_email' => 'a@b.com',
        ]);

        $this->assertNotEmpty($buyer->payments()->first()->order_number);
    }

    public function test_charge_skips_storing_records_when_disabled(): void
    {
        config()->set('bayarcash.store_records', false);

        $buyer = Buyer::create([]);

        $intent = $buyer->charge([
            'payment_channel' => 1, 'order_number' => 'INV-10', 'amount' => '10.00',
            'payer_name' => 'A', 'payer_email' => 'a@b.com',
        ]);

        $this->assertSame('https://pay.test', $intent->url);
        $this->assertCount(0, $buyer->payments()->get());
    }

    public function test_charge_with_tenant_uses_tenant_secret_and_stamps_tenant_id(): void
    {
        $buyer = Buyer::create([]);

        $buyer->charge([
            'payment_channel' => 1, 'order_number' => 'INV-T1', 'amount' => '10.00',
            'payer_name' => 'A', 'payer_email' => 'a@b.com',
        ], tenant: 't1');

        // The checksum was generated with the tenant's secret, not the default.
        $this->assertSame('secret-t1', $this->stub->checksumSecret);

        $trx = $buyer->payments()->first();
        $this->assertSame('t1', $trx->tenant_id);
        $this->assertSame('INV-T1', $trx->order_number);
    }

    public function test_charge_without_tenant_stores_null_tenant_id(): void
    {
        $buyer = Buyer::create([]);

        $buyer->charge([
            'payment_channel' => 1, 'order_number' => 'INV-N', 'amount' => '10.00',
            'payer_name' => 'A', 'payer_email' => 'a@b.com',
        ]);

        $this->assertSame('test-secret', $this->stub->checksumSecret);
        $this->assertNull($buyer->payments()->first()->tenant_id);
    }
}
