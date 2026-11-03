<?php

namespace Bayarcash\Laravel\Tests\Feature;

use Bayarcash\Bayarcash;
use Bayarcash\Fpx;
use Bayarcash\FpxDirectDebit;
use Bayarcash\Laravel\Events\MandateApproved;
use Bayarcash\Laravel\Events\MandateAuthorized;
use Bayarcash\Laravel\Events\MandateRejected;
use Bayarcash\Laravel\Events\MandateTerminated;
use Bayarcash\Laravel\Models\BayarcashMandate;
use Bayarcash\Laravel\Models\BayarcashTransaction;
use Bayarcash\Laravel\Tests\Fixtures\TenantResolver;
use Bayarcash\Laravel\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

class MandateCallbackTest extends TestCase
{
    use RefreshDatabase;

    private function sign(array $fields, string $secret = 'test-secret'): array
    {
        $fields['checksum'] = (new Bayarcash('t'))->createChecksumValue($secret, $fields);

        return $fields;
    }

    private function authorization(string $applicationType = '01', int $status = 3): array
    {
        return $this->sign([
            'record_type' => 'authorization', 'transaction_id' => 'trx_auth', 'mandate_id' => 'md_1',
            'application_type' => $applicationType, 'exchange_reference_number' => 'REF', 'exchange_transaction_id' => 'EX',
            'order_number' => 'SUB-1', 'currency' => 'MYR', 'amount' => '1.00', 'payer_name' => 'Ahmad',
            'payer_email' => 'a@b.com', 'payer_bank_name' => 'Bank', 'status' => $status,
            'status_description' => 'ok', 'datetime' => '2026-11-01 10:00:00',
        ]);
    }

    private function bankApproval(string $applicationType, int $approvalStatus): array
    {
        return $this->sign([
            'record_type' => 'bank_approval', 'approval_date' => '2026-11-02', 'approval_status' => $approvalStatus,
            'mandate_id' => 'md_1', 'mandate_reference_number' => 'MANDATE-NO', 'order_number' => 'SUB-1',
            'payer_bank_code_hashed' => 'h', 'payer_bank_code' => 'MB2U0227', 'payer_bank_account_no' => '****1234',
            'application_type' => $applicationType,
        ]);
    }

    private function seedMandate(int $status, ?string $tenant = null): BayarcashMandate
    {
        return BayarcashMandate::create([
            'tenant_id' => $tenant, 'mandate_id' => 'md_1', 'order_number' => 'SUB-1',
            'amount' => '10.00', 'status' => $status,
        ]);
    }

    public function test_paid_authorization_moves_a_new_mandate_to_waiting_approval_without_touching_its_amount(): void
    {
        Event::fake([MandateAuthorized::class]);
        $mandate = $this->seedMandate(FpxDirectDebit::STATUS_NEW);

        $this->postJson('bayarcash/callback', $this->authorization())->assertOk();

        $mandate->refresh();
        $this->assertSame(FpxDirectDebit::STATUS_WAITING_APPROVAL, $mandate->status);
        $this->assertSame('10.00', $mandate->amount);
        Event::assertDispatched(MandateAuthorized::class);
    }

    public function test_authorization_never_changes_an_active_mandate_or_records_a_payment(): void
    {
        $mandate = $this->seedMandate(FpxDirectDebit::STATUS_ACTIVE);

        $this->postJson('bayarcash/callback', $this->authorization(FpxDirectDebit::TERMINATION))->assertOk();
        $this->postJson('bayarcash/callback', $this->authorization())->assertOk();

        $this->assertSame(FpxDirectDebit::STATUS_ACTIVE, $mandate->fresh()->status);
        $this->assertSame(0, BayarcashTransaction::count());
    }

    public function test_failed_authorization_leaves_the_status_for_a_retry(): void
    {
        $mandate = $this->seedMandate(FpxDirectDebit::STATUS_NEW);

        $this->postJson('bayarcash/callback', $this->authorization(status: Fpx::STATUS_FAILED))->assertOk();

        $this->assertSame(FpxDirectDebit::STATUS_NEW, $mandate->fresh()->status);
    }

    public function test_approved_enrolment_activates_the_mandate_once(): void
    {
        Event::fake([MandateApproved::class]);
        $mandate = $this->seedMandate(FpxDirectDebit::STATUS_WAITING_APPROVAL);

        $this->postJson('bayarcash/callback', $this->bankApproval('01', FpxDirectDebit::STATUS_APPROVED))->assertOk();
        $this->postJson('bayarcash/callback', $this->bankApproval('01', FpxDirectDebit::STATUS_APPROVED))->assertOk();

        $mandate->refresh();
        $this->assertSame(FpxDirectDebit::STATUS_ACTIVE, $mandate->status);
        $this->assertSame('MANDATE-NO', $mandate->mandate_reference_number);
        Event::assertDispatchedTimes(MandateApproved::class, 1);
    }

    public function test_approved_termination_terminates_the_mandate(): void
    {
        Event::fake([MandateApproved::class, MandateTerminated::class]);
        $mandate = $this->seedMandate(FpxDirectDebit::STATUS_ACTIVE);

        $this->postJson('bayarcash/callback', $this->bankApproval(FpxDirectDebit::TERMINATION, FpxDirectDebit::STATUS_APPROVED))->assertOk();

        $this->assertSame(FpxDirectDebit::STATUS_TERMINATED, $mandate->fresh()->status);
        Event::assertDispatched(MandateTerminated::class, fn ($event) => $event->mandate->is($mandate));
        Event::assertNotDispatched(MandateApproved::class);
    }

    public function test_rejected_enrolment_rejects_the_mandate(): void
    {
        Event::fake([MandateApproved::class, MandateRejected::class]);
        $mandate = $this->seedMandate(FpxDirectDebit::STATUS_WAITING_APPROVAL);

        $this->postJson('bayarcash/callback', $this->bankApproval('01', FpxDirectDebit::STATUS_REJECTED))->assertOk();

        $this->assertSame(FpxDirectDebit::STATUS_REJECTED, $mandate->fresh()->status);
        Event::assertDispatched(MandateRejected::class);
        Event::assertNotDispatched(MandateApproved::class);
    }

    public function test_rejected_maintenance_keeps_the_mandate_active(): void
    {
        Event::fake([MandateApproved::class, MandateRejected::class, MandateTerminated::class]);
        $mandate = $this->seedMandate(FpxDirectDebit::STATUS_ACTIVE);

        $this->postJson('bayarcash/callback', $this->bankApproval(FpxDirectDebit::MAINTENANCE, FpxDirectDebit::STATUS_REJECTED))->assertOk();

        $this->assertSame(FpxDirectDebit::STATUS_ACTIVE, $mandate->fresh()->status);
        Event::assertNothingDispatched();
    }

    private function incomingTransaction(string $secret = 'test-secret'): array
    {
        return $this->sign([
            'record_type' => 'incoming_transaction', 'batch_number' => 'HLB1', 'mandate_id' => 'md_1',
            'mandate_reference_number' => 'SUB-1', 'transaction_id' => 'trx_dd_1',
            'scheduled_datetime' => '2026-11-05 00:00:00', 'reference_number' => 'REF1', 'amount' => '10.00',
            'status' => 1, 'status_description' => 'Pending', 'payment_gateway_id' => 3, 'cycle' => 2,
        ], $secret);
    }

    public function test_incoming_transaction_notice_is_acknowledged_without_recording_anything(): void
    {
        $this->postJson('bayarcash/callback', $this->incomingTransaction())->assertOk();

        $this->assertSame(0, BayarcashTransaction::count());
    }

    public function test_tampered_incoming_transaction_notice_is_rejected(): void
    {
        $payload = $this->incomingTransaction();
        $payload['amount'] = '999.00';

        $this->postJson('bayarcash/callback', $payload)->assertForbidden();
    }

    public function test_multi_tenant_incoming_transaction_verifies_with_the_mandate_tenant_secret(): void
    {
        config()->set('bayarcash.multi_tenant', true);
        config()->set('bayarcash.credential_resolver', TenantResolver::class);
        $this->seedMandate(FpxDirectDebit::STATUS_ACTIVE, 't2');

        $this->postJson('bayarcash/callback', $this->incomingTransaction('secret-t1'))->assertForbidden();
        $this->postJson('bayarcash/callback', $this->incomingTransaction('secret-t2'))->assertOk();
    }
}
