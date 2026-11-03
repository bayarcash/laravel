<?php

namespace Bayarcash\Laravel\Tests\Feature;

use Bayarcash\Bayarcash;
use Bayarcash\Fpx;
use Bayarcash\FpxDirectDebit;
use Bayarcash\Laravel\Events\MandateApproved;
use Bayarcash\Laravel\Events\MandateRejected;
use Bayarcash\Laravel\Events\MandateTerminated;
use Bayarcash\Laravel\Events\PaymentSucceeded;
use Bayarcash\Laravel\Models\BayarcashMandate;
use Bayarcash\Laravel\Models\BayarcashTransaction;
use Bayarcash\Laravel\PaymentRecorder;
use Bayarcash\Laravel\Tests\Fixtures\HttpFakeManager;
use Bayarcash\Laravel\Tests\Fixtures\TenantResolver;
use Bayarcash\Laravel\Tests\TestCase;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Psr\Http\Message\RequestInterface;

class SyncMandatesCommandTest extends TestCase
{
    use RefreshDatabase;

    private array $statusOnBayarcash = [];

    private array $deductions = [];

    private HttpFakeManager $fake;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('bayarcash.mandate_sync.pause_ms', 0);

        $this->fake = HttpFakeManager::install($this->gateway());
    }

    private function gateway(): \Closure
    {
        return function (RequestInterface $request) {
            $path = $request->getUri()->getPath();

            if (str_ends_with($path, '/mandates/transactions')) {
                parse_str($request->getUri()->getQuery(), $query);
                $page = (int) ($query['page'] ?? 1);
                $pages = array_chunk($this->deductions, 2) ?: [[]];

                return new Response(200, [], json_encode([
                    'data' => $pages[$page - 1] ?? [],
                    'meta' => ['current_page' => $page, 'last_page' => count($pages)],
                ]));
            }

            $id = basename($path);

            return isset($this->statusOnBayarcash[$id])
                ? new Response(200, [], json_encode(['id' => $id, 'status' => $this->statusOnBayarcash[$id]]))
                : new Response(404, [], '');
        };
    }

    private function mandate(string $id, int $status, string $age = '-2 hours', ?string $tenant = null): BayarcashMandate
    {
        return BayarcashMandate::create([
            'tenant_id' => $tenant, 'mandate_id' => $id, 'order_number' => 'SUB-' . $id, 'amount' => '10.00',
            'status' => $status, 'created_at' => now()->modify($age), 'updated_at' => now()->modify($age),
        ]);
    }

    private function deduction(string $mandateId, string $trx, string $daysAgo = '2', int $status = 3, string $type = 'Deduction'): array
    {
        return [
            'id' => $trx, 'transaction_type' => $type, 'exchange_reference_number' => 'REF-' . $trx,
            'created_at' => now('Asia/Kuala_Lumpur')->subDays((int) $daysAgo)->format('Y-m-d H:i:s'),
            'amount' => '10.00', 'currency' => 'MYR', 'status' => $status, 'status_description' => 'Successful',
            'mandate' => ['id' => $mandateId, 'order_number' => 'SUB-' . $mandateId],
        ];
    }

    private function requestedPaths(): array
    {
        return array_map(fn (RequestInterface $request) => $request->getMethod() . ' ' . $request->getUri()->getPath(), $this->fake->requests);
    }

    public function test_pending_enrolment_approved_on_bayarcash_is_activated(): void
    {
        Event::fake([MandateApproved::class]);
        $mandate = $this->mandate('md_1', FpxDirectDebit::STATUS_WAITING_APPROVAL);
        $this->statusOnBayarcash['md_1'] = FpxDirectDebit::STATUS_ACTIVE;

        $this->artisan('bayarcash:sync-mandates')->assertSuccessful();

        $this->assertSame(FpxDirectDebit::STATUS_ACTIVE, $mandate->fresh()->status);
        Event::assertDispatched(MandateApproved::class);
    }

    public function test_pending_enrolment_rejected_on_bayarcash_is_rejected(): void
    {
        Event::fake([MandateRejected::class]);
        $mandate = $this->mandate('md_1', FpxDirectDebit::STATUS_WAITING_APPROVAL);
        $this->statusOnBayarcash['md_1'] = FpxDirectDebit::STATUS_REJECTED;

        $this->artisan('bayarcash:sync-mandates')->assertSuccessful();

        $this->assertSame(FpxDirectDebit::STATUS_REJECTED, $mandate->fresh()->status);
        Event::assertDispatched(MandateRejected::class);
    }

    public function test_enrolments_younger_than_an_hour_are_left_to_their_callbacks(): void
    {
        $this->mandate('md_1', FpxDirectDebit::STATUS_NEW, '-10 minutes');
        $this->statusOnBayarcash['md_1'] = FpxDirectDebit::STATUS_ACTIVE;

        $this->artisan('bayarcash:sync-mandates')->assertSuccessful();

        $this->assertNotContains('GET /v3/mandates/md_1', $this->requestedPaths());
    }

    public function test_active_mandate_stopped_on_bayarcash_is_terminated(): void
    {
        Event::fake([MandateTerminated::class]);
        $stopped = $this->mandate('md_1', FpxDirectDebit::STATUS_ACTIVE, '-3 days');
        $running = $this->mandate('md_2', FpxDirectDebit::STATUS_ACTIVE, '-3 days');
        $this->statusOnBayarcash = ['md_1' => FpxDirectDebit::STATUS_TERMINATED, 'md_2' => FpxDirectDebit::STATUS_ACTIVE];

        $this->artisan('bayarcash:sync-mandates')->assertSuccessful();

        $this->assertSame(FpxDirectDebit::STATUS_TERMINATED, $stopped->fresh()->status);
        $this->assertSame(FpxDirectDebit::STATUS_ACTIVE, $running->fresh()->status);
        $this->assertTrue($running->fresh()->updated_at->gt($running->updated_at));
        Event::assertDispatchedTimes(MandateTerminated::class, 1);
    }

    public function test_active_mandates_are_checked_a_few_at_a_time_least_recently_checked_first(): void
    {
        config()->set('bayarcash.mandate_sync.check_active', 1);
        $this->mandate('md_new', FpxDirectDebit::STATUS_ACTIVE, '-1 day');
        $this->mandate('md_old', FpxDirectDebit::STATUS_ACTIVE, '-9 days');
        $this->statusOnBayarcash = ['md_new' => FpxDirectDebit::STATUS_ACTIVE, 'md_old' => FpxDirectDebit::STATUS_ACTIVE];

        $this->artisan('bayarcash:sync-mandates')->assertSuccessful();

        $this->assertContains('GET /v3/mandates/md_old', $this->requestedPaths());
        $this->assertNotContains('GET /v3/mandates/md_new', $this->requestedPaths());
    }

    public function test_missed_deductions_are_recorded_as_payments(): void
    {
        Event::fake([PaymentSucceeded::class]);
        $mandate = $this->mandate('md_1', FpxDirectDebit::STATUS_ACTIVE, '-30 days');
        $this->statusOnBayarcash['md_1'] = FpxDirectDebit::STATUS_ACTIVE;
        $this->deductions = [
            $this->deduction('md_1', 'trx_new'),
            $this->deduction('md_other_app', 'trx_foreign'),
            $this->deduction('md_1', 'trx_enrol', type: 'Enrollment Application'),
            $this->deduction('md_1', 'trx_pending', status: Fpx::STATUS_PENDING),
        ];

        $this->artisan('bayarcash:sync-mandates')->assertSuccessful();

        $payment = BayarcashTransaction::sole();
        $this->assertSame('trx_new', $payment->transaction_id);
        $this->assertSame(Bayarcash::FPX_DIRECT_DEBIT, $payment->payment_channel);
        $this->assertSame('REF-trx_new', $payment->exchange_reference_number);
        $this->assertSame('SUB-md_1', $payment->order_number);
        $this->assertTrue($payment->mandate->is($mandate));
        $this->assertNull($payment->raw_callback);
        Event::assertDispatched(PaymentSucceeded::class);
    }

    public function test_deductions_already_recorded_by_their_callback_are_not_duplicated(): void
    {
        Event::fake([PaymentSucceeded::class]);
        $this->mandate('md_1', FpxDirectDebit::STATUS_ACTIVE, '-30 days');
        $this->statusOnBayarcash['md_1'] = FpxDirectDebit::STATUS_ACTIVE;
        app(PaymentRecorder::class)->record([
            'record_type' => 'transaction', 'mandate_id' => 'md_1', 'transaction_id' => 'trx_1',
            'reference_number' => 'REF-trx_1', 'amount' => '10.00', 'status' => 3,
        ], 'callback');
        $this->deductions = [$this->deduction('md_1', 'trx_1')];

        $this->artisan('bayarcash:sync-mandates')->assertSuccessful();

        $this->assertSame(1, BayarcashTransaction::count());
        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
    }

    public function test_paging_stops_at_deductions_older_than_the_window(): void
    {
        $this->mandate('md_1', FpxDirectDebit::STATUS_ACTIVE, '-90 days');
        $this->statusOnBayarcash['md_1'] = FpxDirectDebit::STATUS_ACTIVE;
        $this->deductions = [
            $this->deduction('md_1', 'trx_a', '1'), $this->deduction('md_1', 'trx_b', '20'),
            $this->deduction('md_1', 'trx_c', '40'), $this->deduction('md_1', 'trx_d', '50'),
        ];

        $this->artisan('bayarcash:sync-mandates', ['--days' => 14])->assertSuccessful();

        $this->assertSame(['trx_a'], BayarcashTransaction::pluck('transaction_id')->all());
        $pages = array_filter($this->requestedPaths(), fn ($path) => str_contains($path, 'transactions'));
        $this->assertCount(1, $pages);
    }

    public function test_each_tenant_is_synced_with_its_own_credentials(): void
    {
        $this->fake = HttpFakeManager::install($this->gateway(), new TenantResolver());
        $this->mandate('md_1', FpxDirectDebit::STATUS_WAITING_APPROVAL, tenant: 't1');
        $this->mandate('md_2', FpxDirectDebit::STATUS_WAITING_APPROVAL, tenant: 't2');
        $this->statusOnBayarcash = ['md_1' => FpxDirectDebit::STATUS_ACTIVE, 'md_2' => FpxDirectDebit::STATUS_ACTIVE];

        $this->artisan('bayarcash:sync-mandates')->assertSuccessful();

        $tokens = array_combine($this->requestedPaths(), $this->fake->tokens());
        $this->assertSame('Bearer tok-t1', $tokens['GET /v3/mandates/md_1']);
        $this->assertSame('Bearer tok-t2', $tokens['GET /v3/mandates/md_2']);
    }

    public function test_nothing_is_requested_without_mandates(): void
    {
        $this->artisan('bayarcash:sync-mandates')->assertSuccessful();

        $this->assertSame([], $this->fake->requests);
    }

    public function test_a_failing_lookup_does_not_stop_the_run(): void
    {
        $missing = $this->mandate('md_missing', FpxDirectDebit::STATUS_WAITING_APPROVAL);
        $found = $this->mandate('md_found', FpxDirectDebit::STATUS_WAITING_APPROVAL);
        $this->statusOnBayarcash['md_found'] = FpxDirectDebit::STATUS_ACTIVE;

        $this->artisan('bayarcash:sync-mandates')->assertSuccessful();

        $this->assertSame(FpxDirectDebit::STATUS_WAITING_APPROVAL, $missing->fresh()->status);
        $this->assertSame(FpxDirectDebit::STATUS_ACTIVE, $found->fresh()->status);
    }

    public function test_command_requires_store_records(): void
    {
        config()->set('bayarcash.store_records', false);

        $this->artisan('bayarcash:sync-mandates')->assertSuccessful();

        $this->assertSame([], $this->fake->requests);
    }
}
