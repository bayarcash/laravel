<?php

namespace Bayarcash\Laravel\Console;

use Bayarcash\Fpx;
use Bayarcash\FpxDirectDebit;
use Bayarcash\Laravel\MandateRecorder;
use Bayarcash\Laravel\Models\BayarcashMandate;
use Bayarcash\Laravel\PaymentRecorder;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Throwable;

class SyncMandatesCommand extends Command
{
    protected $signature = 'bayarcash:sync-mandates {--days= : How many days of deductions to look back over}';

    protected $description = 'Apply Direct Debit approvals, terminations and deductions whose callback never arrived.';

    private const MAX_PAGES = 200;

    private const FINAL_DEDUCTION = [Fpx::STATUS_SUCCESS, Fpx::STATUS_FAILED, Fpx::STATUS_CANCELLED];

    protected int $updated = 0;

    protected int $recorded = 0;

    public function handle(MandateRecorder $mandates, PaymentRecorder $payments): int
    {
        if (! config('bayarcash.store_records')) {
            $this->warn('Bayarcash mandate sync requires store_records to be enabled.');

            return self::SUCCESS;
        }

        // Artisan reuses this instance between calls.
        $this->updated = 0;
        $this->recorded = 0;

        /** @var class-string<BayarcashMandate> $model */
        $model = config('bayarcash.models.mandate', BayarcashMandate::class);

        foreach ($model::query()->whereNotNull('mandate_id')->distinct()->pluck('tenant_id') as $tenant) {
            $tenant = $tenant === null || $tenant === '' ? null : (string) $tenant;

            try {
                $sdk = app('bayarcash.manager')->for($tenant);
            } catch (Throwable $e) {
                continue;
            }

            $this->syncPending($model, $tenant, $sdk, $mandates);
            $this->syncActive($model, $tenant, $sdk, $mandates);
            $this->syncDeductions($model, $tenant, $sdk, $payments);
        }

        $this->info("Bayarcash mandate sync: {$this->updated} mandates updated, {$this->recorded} deductions recorded.");

        return self::SUCCESS;
    }

    protected function syncPending(string $model, ?string $tenant, mixed $sdk, MandateRecorder $mandates): void
    {
        $pending = $this->forTenant($model, $tenant)
            ->whereIn('status', [FpxDirectDebit::STATUS_NEW, FpxDirectDebit::STATUS_WAITING_APPROVAL])
            // Younger enrolments are left to their callbacks.
            ->whereBetween('created_at', [now()->subDays(30), now()->subHour()])
            ->get();

        foreach ($pending as $mandate) {
            $status = $this->remoteStatus($sdk, $mandate);

            if (in_array($status, [FpxDirectDebit::STATUS_ACTIVE, FpxDirectDebit::STATUS_APPROVED], true)) {
                $status = FpxDirectDebit::STATUS_ACTIVE;
            } elseif (! in_array($status, MandateRecorder::STOPPED, true)) {
                continue;
            }

            $mandates->transition($mandate, $status);
            $this->updated++;
        }
    }

    protected function syncActive(string $model, ?string $tenant, mixed $sdk, MandateRecorder $mandates): void
    {
        $active = $this->forTenant($model, $tenant)
            ->where('status', FpxDirectDebit::STATUS_ACTIVE)
            ->orderBy('updated_at')
            ->limit((int) config('bayarcash.mandate_sync.check_active', 20))
            ->get();

        foreach ($active as $mandate) {
            $status = $this->remoteStatus($sdk, $mandate);

            if (in_array($status, MandateRecorder::STOPPED, true)) {
                $mandates->transition($mandate, $status);
                $this->updated++;
            } elseif ($status !== null) {
                // Touched, so the next run checks the others first.
                $mandate->touch();
            }
        }
    }

    protected function syncDeductions(string $model, ?string $tenant, mixed $sdk, PaymentRecorder $payments): void
    {
        // Only mandates stored here: several apps can share one Bayarcash account.
        $ours = $this->forTenant($model, $tenant)->pluck('mandate_id')->flip();
        $cutoff = now()->subDays((int) ($this->option('days') ?: config('bayarcash.mandate_sync.days', 14)))->startOfDay();

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $response = $this->fetch(fn () => $sdk->get('mandates/transactions?page=' . $page));
            $rows = is_array($response) ? ($response['data'] ?? []) : [];
            $reachedCutoff = false;

            foreach ($rows as $row) {
                $date = $this->date($row['created_at'] ?? null);

                // Rows are newest first, so one past the cut-off ends the paging.
                if ($date === null || $date->lt($cutoff)) {
                    $reachedCutoff = true;

                    continue;
                }

                if (($row['transaction_type'] ?? null) === 'Deduction'
                    && isset($ours[$row['mandate']['id'] ?? null])
                    && in_array((int) ($row['status'] ?? -1), self::FINAL_DEDUCTION, true)) {
                    $this->recordDeduction($payments, $row, $tenant);
                }
            }

            if ($rows === [] || $reachedCutoff || $page >= (int) ($response['meta']['last_page'] ?? $page)) {
                return;
            }
        }
    }

    protected function recordDeduction(PaymentRecorder $payments, array $row, ?string $tenant): void
    {
        try {
            $payment = $payments->record([
                'record_type'        => 'transaction',
                'mandate_id'         => $row['mandate']['id'],
                'transaction_id'     => $row['id'] ?? null,
                'reference_number'   => $row['exchange_reference_number'] ?? null,
                'datetime'           => $row['created_at'] ?? null,
                'amount'             => $row['amount'] ?? null,
                'currency'           => $row['currency'] ?? null,
                'status'             => (int) $row['status'],
                'status_description' => $row['status_description'] ?? null,
                'cycle'              => $row['cycle'] ?? null,
            ], 'requery', $tenant);
        } catch (Throwable $e) {
            return;
        }

        if ($payment->wasRecentlyCreated || $payment->wasChanged('status')) {
            $this->recorded++;
        }
    }

    protected function forTenant(string $model, ?string $tenant): Builder
    {
        return $model::query()
            ->whereNotNull('mandate_id')
            ->when($tenant === null, fn ($query) => $query->whereNull('tenant_id'))
            ->when($tenant !== null, fn ($query) => $query->where('tenant_id', $tenant));
    }

    protected function remoteStatus(mixed $sdk, BayarcashMandate $mandate): ?int
    {
        $response = $this->fetch(fn () => $sdk->get('mandates/' . $mandate->mandate_id));

        return is_array($response) && isset($response['status']) ? (int) $response['status'] : null;
    }

    protected function fetch(callable $call): mixed
    {
        try {
            return $call();
        } catch (Throwable $e) {
            return null;
        } finally {
            // Spaced out to stay inside Bayarcash's rate limit.
            usleep((int) config('bayarcash.mandate_sync.pause_ms', 1000) * 1000);
        }
    }

    // Bayarcash list dates are Malaysia time.
    protected function date(mixed $value): ?Carbon
    {
        try {
            return $value ? Carbon::parse($value, 'Asia/Kuala_Lumpur') : null;
        } catch (Throwable) {
            return null;
        }
    }
}
