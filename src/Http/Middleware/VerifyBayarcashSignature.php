<?php

namespace Bayarcash\Laravel\Http\Middleware;

use Bayarcash\Laravel\Concerns\ResolvesTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies the checksum of an incoming Bayarcash callback (POST) and aborts 403 on
 * failure. In multi_tenant mode the tenant is resolved from the payload's local
 * record and the checksum is verified with THAT tenant's secret; no matching record
 * fails closed (403) — there is no secret we can trust.
 */
class VerifyBayarcashSignature
{
    use ResolvesTenant;

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

        if (config('bayarcash.multi_tenant')) {
            $tenantId = $this->resolveTenantFromPayload($data);

            // Fail closed: no local record means we cannot know whose secret to
            // trust, so we reject rather than fall back to the default secret.
            if ($tenantId === false) {
                abort(403, 'Unknown Bayarcash callback: no matching record.');
            }

            // The verifiers are pure — they only hash the payload with the given
            // secret — so the default SDK instance can verify a tenant payload.
            if (! $manager->sdk()->{$verifier}($data, $manager->secretKey($tenantId))) {
                abort(403, 'Invalid Bayarcash checksum.');
            }

            $request->attributes->set('bayarcash_tenant', $tenantId);

            return $next($request);
        }

        if (! $manager->sdk()->{$verifier}($data, $manager->secretKey())) {
            abort(403, 'Invalid Bayarcash checksum.');
        }

        return $next($request);
    }
}
