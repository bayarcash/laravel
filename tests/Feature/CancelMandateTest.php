<?php

namespace Bayarcash\Laravel\Tests\Feature;

use Bayarcash\FpxDirectDebit;
use Bayarcash\Laravel\Events\MandateTerminated;
use Bayarcash\Laravel\Exceptions\MandateNotCancellable;
use Bayarcash\Laravel\Models\BayarcashMandate;
use Bayarcash\Laravel\Tests\Fixtures\HttpFakeManager;
use Bayarcash\Laravel\Tests\TestCase;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Psr\Http\Message\RequestInterface;

class CancelMandateTest extends TestCase
{
    use RefreshDatabase;

    private function bayarcashReports(int $status): HttpFakeManager
    {
        return HttpFakeManager::install(fn (RequestInterface $request) => $request->getMethod() === 'PATCH'
            ? new Response(200, [], json_encode(['id' => 'md_1', 'status' => FpxDirectDebit::STATUS_INACTIVE]))
            : new Response(200, [], json_encode(['id' => 'md_1', 'status' => $status])));
    }

    private function mandate(int $status = FpxDirectDebit::STATUS_ACTIVE): BayarcashMandate
    {
        return BayarcashMandate::create([
            'mandate_id' => 'md_1', 'order_number' => 'SUB-1', 'amount' => '10.00', 'status' => $status,
        ]);
    }

    private function methods(HttpFakeManager $fake): array
    {
        return array_map(fn (RequestInterface $request) => $request->getMethod() . ' ' . $request->getUri()->getPath(), $fake->requests);
    }

    public function test_active_mandate_is_deactivated_on_bayarcash(): void
    {
        Event::fake([MandateTerminated::class]);
        $fake = $this->bayarcashReports(FpxDirectDebit::STATUS_ACTIVE);
        $mandate = $this->mandate();

        $mandate->cancel();

        $this->assertSame(['GET /v3/mandates/md_1', 'PATCH /v3/mandates/md_1/deactivate'], $this->methods($fake));
        $this->assertSame(FpxDirectDebit::STATUS_INACTIVE, $mandate->fresh()->status);
        Event::assertDispatched(MandateTerminated::class);
    }

    public function test_mandate_already_stopped_on_bayarcash_is_only_updated_here(): void
    {
        $fake = $this->bayarcashReports(FpxDirectDebit::STATUS_TERMINATED);
        $mandate = $this->mandate();

        $mandate->cancel();

        $this->assertSame(['GET /v3/mandates/md_1'], $this->methods($fake));
        $this->assertSame(FpxDirectDebit::STATUS_TERMINATED, $mandate->fresh()->status);
    }

    public function test_mandate_still_waiting_for_the_bank_cannot_be_cancelled(): void
    {
        $fake = $this->bayarcashReports(FpxDirectDebit::STATUS_WAITING_APPROVAL);
        $mandate = $this->mandate(FpxDirectDebit::STATUS_WAITING_APPROVAL);

        try {
            $mandate->cancel();
            $this->fail('Expected MandateNotCancellable');
        } catch (MandateNotCancellable $e) {
            $this->assertStringContainsString('waiting', $e->getMessage());
        }

        $this->assertSame(['GET /v3/mandates/md_1'], $this->methods($fake));
        $this->assertSame(FpxDirectDebit::STATUS_WAITING_APPROVAL, $mandate->fresh()->status);
    }

    public function test_mandate_without_a_bayarcash_id_cannot_be_cancelled(): void
    {
        $fake = $this->bayarcashReports(FpxDirectDebit::STATUS_ACTIVE);
        $mandate = BayarcashMandate::create(['order_number' => 'SUB-2', 'status' => FpxDirectDebit::STATUS_NEW]);

        $this->expectException(MandateNotCancellable::class);

        try {
            $mandate->cancel();
        } finally {
            $this->assertSame([], $fake->requests);
        }
    }
}
