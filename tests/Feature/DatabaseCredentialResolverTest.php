<?php

namespace Bayarcash\Laravel\Tests\Feature;

use Bayarcash\Laravel\DatabaseCredentialResolver;
use Bayarcash\Laravel\Models\BayarcashAccount;
use Bayarcash\Laravel\Tests\TestCase;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

class DatabaseCredentialResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_a_tenants_credentials(): void
    {
        BayarcashAccount::create([
            'tenant_id'  => 't1',
            'token'      => 'tenant-1-token',
            'secret_key' => 'tenant-1-secret',
            'sandbox'    => true,
        ]);

        $creds = (new DatabaseCredentialResolver())->resolve('t1');

        $this->assertSame('tenant-1-token', $creds['token']);
        $this->assertSame('tenant-1-secret', $creds['secret_key']);
        $this->assertTrue($creds['sandbox']);
    }

    public function test_secrets_are_encrypted_at_rest(): void
    {
        BayarcashAccount::create([
            'tenant_id'  => 't2',
            'token'      => 'plain-token',
            'secret_key' => 'plain-secret',
        ]);

        $raw = DB::table('bayarcash_accounts')->where('tenant_id', 't2')->first();

        $this->assertNotSame('plain-token', $raw->token);
        $this->assertNotSame('plain-secret', $raw->secret_key);

        $this->assertSame('plain-secret', BayarcashAccount::where('tenant_id', 't2')->firstOrFail()->secret_key);
    }

    public function test_unknown_tenant_throws(): void
    {
        $this->expectException(ModelNotFoundException::class);

        (new DatabaseCredentialResolver())->resolve('unknown');
    }
}
