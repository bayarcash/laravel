<?php

namespace Bayarcash\Laravel\Http\Middleware;

use Bayarcash\Laravel\Concerns\ResolvesTenant;
use Bayarcash\Laravel\Http\CallbackType;
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
     * Map of callback type (see CallbackType::of()) => SDK verifier method.
     *
     * @var array<string, string>
     */
    private const VERIFIERS = [
        'pre_transaction' => 'verifyPreTransactionCallbackData',
        'transaction'     => 'verifyTransactionCallbackData',
        'bank_approval'   => 'verifyDirectDebitBankApprovalCallbackData',
        'authorization'   => 'verifyDirectDebitAuthorizationCallbackData',
        'deduction'       => 'verifyDirectDebitTransactionCallbackData',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $manager = app('bayarcash.manager');

        $data = $request->all();

        if (config('bayarcash.multi_tenant')) {
            $tenantId = $this->resolveTenantFromPayload($data);

            // Fail closed: no local record means we cannot know whose secret to
            // trust, so we reject rather than fall back to the default secret.
            if ($tenantId === false) {
                abort(403, 'Unknown Bayarcash callback: no matching record.');
            }

            if (! $this->verify($data, $manager->secretKey($tenantId))) {
                abort(403, 'Invalid Bayarcash checksum.');
            }

            $request->attributes->set('bayarcash_tenant', $tenantId);

            return $next($request);
        }

        if (! $this->verify($data, $manager->secretKey())) {
            abort(403, 'Invalid Bayarcash checksum.');
        }

        return $next($request);
    }

    protected function verify(array $data, string $secret): bool
    {
        // The verifiers only hash the payload, so the default SDK can check any tenant's secret.
        $sdk = app('bayarcash.manager')->sdk();
        $verifier = self::VERIFIERS[CallbackType::of($data)] ?? null;

        if ($verifier !== null) {
            return (bool) $sdk->{$verifier}($data, $secret);
        }

        // Record types without an SDK verifier (e.g. incoming_transaction) are signed over every field.
        $fields = $data;
        unset($fields['checksum']);

        if (array_filter($fields, 'is_array') !== []) {
            return false;
        }

        return hash_equals($sdk->createChecksumValue($secret, $fields), (string) ($data['checksum'] ?? ''));
    }
}
