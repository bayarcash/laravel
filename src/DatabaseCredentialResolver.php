<?php

namespace Bayarcash\Laravel;

use Bayarcash\Laravel\Contracts\CredentialResolver;
use Bayarcash\Laravel\Models\BayarcashAccount;

class DatabaseCredentialResolver implements CredentialResolver
{
    public function resolve(mixed $tenant = null): array
    {
        /** @var class-string<BayarcashAccount> $model */
        $model = config('bayarcash.models.account', BayarcashAccount::class);

        $account = $model::query()->where('tenant_id', $tenant)->firstOrFail();

        return [
            'token'      => $account->token,
            'secret_key' => $account->secret_key,
            'sandbox'    => (bool) $account->sandbox,
        ];
    }
}
