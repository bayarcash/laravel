<?php

namespace Bayarcash\Laravel\Http;

final class CallbackType
{
    public static function of(array $data): string
    {
        $recordType = (string) ($data['record_type'] ?? 'transaction');

        // Deductions are sent as "transaction"; mandate_id marks them.
        return $recordType === 'transaction' && ! empty($data['mandate_id']) ? 'deduction' : $recordType;
    }
}
