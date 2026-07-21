<?php

namespace Bayarcash\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies the checksum of an incoming Bayarcash callback (POST) using the
 * SDK verifier chosen by the payload's record_type. Aborts 403 on failure.
 */
class VerifyBayarcashSignature
{
    /**
     * Map of record_type => SDK verifier method.
     *
     * @var array<string, string>
     */
    private const VERIFIERS = [
        'pre_transaction' => 'verifyPreTransactionCallbackData',
        'transaction'     => 'verifyTransactionCallbackData',
        'bank_approval'   => 'verifyDirectDebitBankApprovalCallbackData',
        'authorization'   => 'verifyDirectDebitAuthorizationCallbackData',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $manager = app('bayarcash.manager');

        $data = $request->all();
        $recordType = $data['record_type'] ?? 'transaction';
        $verifier = self::VERIFIERS[$recordType] ?? self::VERIFIERS['transaction'];

        if (! $manager->sdk()->{$verifier}($data, $manager->secretKey())) {
            abort(403, 'Invalid Bayarcash checksum.');
        }

        return $next($request);
    }
}
