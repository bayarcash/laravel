<?php

namespace Bayarcash\Laravel;

use Bayarcash\Fpx;
use Bayarcash\FpxDirectDebit;
use Bayarcash\Laravel\Events\MandateApproved;
use Bayarcash\Laravel\Events\MandateAuthorized;
use Bayarcash\Laravel\Events\MandateRejected;
use Bayarcash\Laravel\Events\MandateTerminated;
use Bayarcash\Laravel\Models\BayarcashMandate;

class MandateRecorder
{
    public const STOPPED = [
        FpxDirectDebit::STATUS_FAILED_BANK_VERIFICATION,
        FpxDirectDebit::STATUS_TERMINATED,
        FpxDirectDebit::STATUS_REJECTED,
        FpxDirectDebit::STATUS_CANCELLED,
        FpxDirectDebit::STATUS_ERROR,
        FpxDirectDebit::STATUS_EXPIRED,
        FpxDirectDebit::STATUS_INACTIVE,
    ];

    public function record(array $data, string $recordType, ?string $tenantId = null): void
    {
        if (empty($data['mandate_id'])) {
            return;
        }

        /** @var class-string<BayarcashMandate> $model */
        $model = config('bayarcash.models.mandate', BayarcashMandate::class);

        // No amount: an authorization carries the RM1 verification fee, not the mandate amount.
        $attributes = array_filter([
            'tenant_id'                => $tenantId,
            'mandate_reference_number' => $data['mandate_reference_number'] ?? null,
            'order_number'             => $data['order_number'] ?? null,
            'currency'                 => $data['currency'] ?? null,
            'payer_name'               => $data['payer_name'] ?? null,
            'payer_email'              => $data['payer_email'] ?? null,
            'payer_telephone_number'   => $data['payer_telephone_number'] ?? null,
            'application_type'         => $data['application_type'] ?? null,
            'status_description'       => $data['status_description'] ?? null,
        ], fn ($value) => $value !== null);

        $mandate = config('bayarcash.store_records')
            ? $model::firstOrNew(['mandate_id' => $data['mandate_id']])
            : new $model(['mandate_id' => $data['mandate_id']]);

        $this->transition($mandate->fill($attributes), $this->statusFor($data, $recordType, $mandate->status));

        if ($recordType === 'authorization') {
            MandateAuthorized::dispatch($mandate);
        }
    }

    public function transition(BayarcashMandate $mandate, ?int $status): BayarcashMandate
    {
        $previous = $mandate->getOriginal('status');

        if ($status !== null) {
            $mandate->status = $status;
        }

        if (config('bayarcash.store_records')) {
            $mandate->save();
        }

        $this->fireEvent($mandate, $previous, $status);

        return $mandate;
    }

    protected function statusFor(array $data, string $recordType, ?int $current): ?int
    {
        $applicationType = (string) ($data['application_type'] ?? '');

        if ($recordType === 'authorization') {
            // Its status is the RM1 verification payment, so a paid enrolment only means waiting approval.
            $paidEnrolment = $applicationType === FpxDirectDebit::ENROLMENT
                && (int) ($data['status'] ?? -1) === Fpx::STATUS_SUCCESS;

            return $paidEnrolment && in_array($current, [null, FpxDirectDebit::STATUS_NEW], true)
                ? FpxDirectDebit::STATUS_WAITING_APPROVAL
                : null;
        }

        $approval = isset($data['approval_status']) ? (int) $data['approval_status'] : null;

        if ($approval === FpxDirectDebit::STATUS_APPROVED) {
            return $applicationType === FpxDirectDebit::TERMINATION
                ? FpxDirectDebit::STATUS_TERMINATED
                : FpxDirectDebit::STATUS_ACTIVE;
        }

        // A rejected maintenance or termination leaves the mandate as it was.
        $failed = in_array($approval, [FpxDirectDebit::STATUS_REJECTED, FpxDirectDebit::STATUS_ERROR], true);

        return $failed && $applicationType === FpxDirectDebit::ENROLMENT ? $approval : null;
    }

    protected function fireEvent(BayarcashMandate $mandate, ?int $previous, ?int $status): void
    {
        if ($status === null || $status === $previous) {
            return;
        }

        if ($status === FpxDirectDebit::STATUS_ACTIVE) {
            MandateApproved::dispatch($mandate);
        } elseif (in_array($status, self::STOPPED, true)) {
            // Stopping after it ran is a termination; failing before it ran is a rejection.
            $ended = $previous === FpxDirectDebit::STATUS_ACTIVE
                || in_array($status, [FpxDirectDebit::STATUS_TERMINATED, FpxDirectDebit::STATUS_INACTIVE], true);

            $ended ? MandateTerminated::dispatch($mandate) : MandateRejected::dispatch($mandate);
        }
    }
}
