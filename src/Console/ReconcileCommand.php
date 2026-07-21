<?php

namespace Bayarcash\Laravel\Console;

use Bayarcash\Exceptions\RateLimitExceededException;
use Bayarcash\Fpx;
use Bayarcash\Laravel\Models\BayarcashTransaction;
use Bayarcash\Laravel\PaymentRecorder;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Recovers payments whose callback/return was missed.
 *
 * Re-queries pending rows via getPaymentIntent() and applies the result
 * through the shared PaymentRecorder; rows past the cancel window are
 * auto-cancelled. Scheduled to run every minute when reconcile is enabled.
 */
class ReconcileCommand extends Command
{
    protected $signature = 'bayarcash:reconcile {--limit=200 : Maximum rows to process this run}';

    protected $description = 'Reconcile pending Bayarcash payments against the gateway.';

    public function handle(PaymentRecorder $recorder): int
    {
        if (! config('bayarcash.persistence')) {
            $this->warn('Bayarcash reconciliation requires persistence to be enabled.');

            return self::SUCCESS;
        }

        $manager = app('bayarcash.manager');
        $requeryAfter = (int) config('bayarcash.reconcile.requery_after', 2);
        $cancelAfter = (int) config('bayarcash.reconcile.cancel_after', 60);
        $limit = (int) $this->option('limit');

        /** @var class-string<BayarcashTransaction> $model */
        $model = config('bayarcash.models.transaction', BayarcashTransaction::class);

        $requeried = 0;
        $cancelled = 0;
        $processed = 0;

        $this->pendingQuery($model, $requeryAfter)
            ->limit($limit)
            ->chunkById(100, function ($rows) use ($manager, $recorder, $cancelAfter, &$requeried, &$cancelled, &$processed, $limit) {
                foreach ($rows as $row) {
                    if ($processed >= $limit) {
                        return false;
                    }

                    $processed++;

                    try {
                        $intent = $manager->sdk()->getPaymentIntent($row->payment_intent_id);
                        $data = $this->intentToData($intent, $row);

                        if (isset($data['status'])) {
                            $recorder->record($data, 'requery');
                            $requeried++;
                        }
                    } catch (RateLimitExceededException $e) {
                        // Rate limited — skip this row and retry on the next tick.
                        continue;
                    } catch (Throwable $e) {
                        // Best-effort: never let one bad row abort the run.
                        continue;
                    }

                    $row->refresh();

                    if ($this->shouldCancel($row, $cancelAfter)) {
                        $this->cancel($manager, $recorder, $row);
                        $cancelled++;
                    }
                }

                return true;
            });

        $this->info("Bayarcash reconcile: {$requeried} re-queried, {$cancelled} auto-cancelled.");

        return self::SUCCESS;
    }

    /**
     * Pending rows old enough to re-query.
     *
     * @param  class-string<BayarcashTransaction>  $model
     */
    protected function pendingQuery(string $model, int $requeryAfter): Builder
    {
        return $model::query()
            ->whereNotNull('payment_intent_id')
            ->whereIn('status', [Fpx::STATUS_NEW, Fpx::STATUS_PENDING])
            ->where('created_at', '<=', now()->subMinutes($requeryAfter))
            ->orderBy('id');
    }

    /**
     * Map a payment intent resource into recorder input, using the latest
     * transaction attempt for the outcome.
     */
    protected function intentToData(mixed $intent, BayarcashTransaction $row): array
    {
        $data = [
            'payment_intent_id' => $intent->id ?? $row->payment_intent_id,
            'order_number'      => $row->order_number,
        ];

        $attempts = is_array($intent->attempts ?? null) ? $intent->attempts : [];
        $last = ! empty($attempts) ? end($attempts) : null;

        if (is_array($last)) {
            $data['transaction_id']            = $last['transaction_id'] ?? $last['id'] ?? null;
            $data['exchange_reference_number'] = $last['exchange_reference_number'] ?? null;
            $data['payer_bank_name']           = $last['payer_bank_name'] ?? null;
            $data['amount']                    = $last['amount'] ?? null;
            $data['status_description']        = $last['status_description'] ?? null;

            if (isset($last['status']) && $last['status'] !== null) {
                $data['status'] = (int) $last['status'];
            }
        }

        return $data;
    }

    /**
     * Whether a still-pending row has passed the cancel window.
     */
    protected function shouldCancel(BayarcashTransaction $row, int $cancelAfter): bool
    {
        if (! in_array((int) $row->status, [Fpx::STATUS_NEW, Fpx::STATUS_PENDING], true)) {
            return false;
        }

        return $row->created_at !== null
            && $row->created_at->lte(now()->subMinutes($cancelAfter));
    }

    /**
     * Auto-cancel a stuck row (fires PaymentCancelled) and best-effort cancel
     * the intent on the gateway.
     */
    protected function cancel(mixed $manager, PaymentRecorder $recorder, BayarcashTransaction $row): void
    {
        $recorder->record([
            'payment_intent_id'  => $row->payment_intent_id,
            'transaction_id'     => $row->transaction_id,
            'order_number'       => $row->order_number,
            'status'             => Fpx::STATUS_CANCELLED,
            'status_description' => 'Auto-cancelled by reconciliation (intent expired).',
        ], 'requery');

        try {
            $manager->sdk()->cancelPaymentIntent($row->payment_intent_id);
        } catch (Throwable $e) {
            // Best-effort only.
        }
    }
}
