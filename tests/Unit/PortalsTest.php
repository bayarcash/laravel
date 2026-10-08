<?php

namespace Bayarcash\Laravel\Tests\Unit;

use Bayarcash\Laravel\Facades\Bayarcash;
use Bayarcash\Laravel\Tests\Fixtures\HttpFakeManager;
use Bayarcash\Laravel\Tests\Fixtures\TenantResolver;
use Bayarcash\Laravel\Tests\TestCase;
use Bayarcash\Resources\PortalResource;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Collection;
use Psr\Http\Message\RequestInterface;

class PortalsTest extends TestCase
{
    private function paginatedGateway(int $total = 35, int $perPage = 15): \Closure
    {
        return function (RequestInterface $request) use ($total, $perPage) {
            parse_str($request->getUri()->getQuery(), $query);
            $page = (int) ($query['page'] ?? 1);
            $lastPage = (int) ceil($total / $perPage);

            $data = [];
            for ($i = ($page - 1) * $perPage + 1; $i <= min($page * $perPage, $total); $i++) {
                $data[] = ['id' => 'prt_' . $i, 'portal_key' => 'key-' . $i, 'portal_name' => 'Portal ' . $i];
            }

            return new Response(200, [], json_encode([
                'data'  => $data,
                'links' => ['next' => $page < $lastPage ? 'https://api.test/v3/portals?page=' . ($page + 1) : null],
                'meta'  => ['current_page' => $page, 'last_page' => $lastPage, 'per_page' => $perPage, 'total' => $total],
            ]));
        };
    }

    private function pagesRequested(HttpFakeManager $fake): array
    {
        return array_map(function (RequestInterface $request) {
            parse_str($request->getUri()->getQuery(), $query);

            return (int) ($query['page'] ?? 1);
        }, $fake->requests);
    }

    public function test_portals_follows_every_page(): void
    {
        $fake = HttpFakeManager::install($this->paginatedGateway());

        $portals = Bayarcash::portals();

        $this->assertInstanceOf(Collection::class, $portals);
        $this->assertCount(35, $portals);
        $this->assertContainsOnlyInstancesOf(PortalResource::class, $portals);
        $this->assertSame('key-1', $portals->first()->portalKey);
        $this->assertSame('key-35', $portals->last()->portalKey);
        $this->assertSame([1, 2, 3], $this->pagesRequested($fake));
    }

    public function test_portals_uses_the_tenant_credentials(): void
    {
        $fake = HttpFakeManager::install($this->paginatedGateway(), new TenantResolver());

        $this->assertCount(35, app('bayarcash.manager')->portals('t2'));
        $this->assertSame(['Bearer tok-t2', 'Bearer tok-t2', 'Bearer tok-t2'], $fake->tokens());
    }

    public function test_portals_handles_a_single_unpaginated_page(): void
    {
        $fake = HttpFakeManager::install(fn () => new Response(200, [], json_encode([
            'data' => [['id' => 'prt_1', 'portal_key' => 'only-key']],
        ])));

        $this->assertSame(['only-key'], Bayarcash::portals()->pluck('portalKey')->all());
        $this->assertCount(1, $fake->requests);
    }

    public function test_has_portal_finds_a_key_beyond_the_first_page_and_stops_there(): void
    {
        $fake = HttpFakeManager::install($this->paginatedGateway(total: 50));

        $this->assertTrue(Bayarcash::hasPortal('key-20'));
        $this->assertSame([1, 2], $this->pagesRequested($fake));
    }

    public function test_has_portal_is_false_after_checking_every_page(): void
    {
        $fake = HttpFakeManager::install($this->paginatedGateway(), new TenantResolver());

        $this->assertFalse(Bayarcash::hasPortal('missing-key', 't1'));
        $this->assertSame([1, 2, 3], $this->pagesRequested($fake));
        $this->assertSame(['Bearer tok-t1'], array_values(array_unique($fake->tokens())));
    }

    public function test_has_portal_lets_a_rejected_token_throw(): void
    {
        HttpFakeManager::install(fn () => new Response(401, [], json_encode(['message' => 'Unauthenticated.'])));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Unauthenticated.');

        Bayarcash::hasPortal('key-1');
    }
}
