<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Models\ServiceContext;
use App\Services\ServiceContextResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceContextResolverTest extends TestCase
{
    use RefreshDatabase;

    protected ServiceContextResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new ServiceContextResolver();
    }

    public function test_resolve_returns_provided_value_when_valid(): void
    {
        $context = ServiceContext::factory()->create(['is_active' => true]);

        $result = $this->resolver->resolveForClient(null, $context->id);

        $this->assertEquals($context->id, $result);
    }

    public function test_resolve_falls_back_to_client_context(): void
    {
        $clientContext = ServiceContext::factory()->create(['is_active' => true]);
        $client = Client::factory()->create([
            'service_context_id' => $clientContext->id,
        ]);

        $result = $this->resolver->resolveForClient($client->id, null);

        $this->assertEquals($clientContext->id, $result);
    }

    public function test_resolve_ignores_inactive_context(): void
    {
        $inactiveContext = ServiceContext::factory()->create(['is_active' => false]);
        $activeContext = ServiceContext::factory()->create(['is_active' => true]);

        $client = Client::factory()->create([
            'service_context_id' => $inactiveContext->id,
        ]);

        // Should fall through to next available context
        $result = $this->resolver->resolveForClient($client->id, $inactiveContext->id);
        
        // Since inactive is invalid, it should try client's context (also invalid)
        // then fall back to org default (null) then first active
        $this->assertNotEquals($inactiveContext->id, $result);
    }

    public function test_is_valid_context_returns_true_for_active(): void
    {
        $context = ServiceContext::factory()->create(['is_active' => true]);

        $this->assertTrue($this->resolver->isValidContext($context->id));
    }

    public function test_is_valid_context_returns_false_for_inactive(): void
    {
        $context = ServiceContext::factory()->create(['is_active' => false]);

        $this->assertFalse($this->resolver->isValidContext($context->id));
    }

    public function test_is_valid_context_returns_false_for_nonexistent(): void
    {
        $this->assertFalse($this->resolver->isValidContext(99999));
    }

    public function test_get_default_returns_first_active(): void
    {
        ServiceContext::factory()->create(['is_active' => false]);
        $activeContext = ServiceContext::factory()->create(['is_active' => true]);

        $result = $this->resolver->getDefault();

        $this->assertEquals($activeContext->id, $result);
    }

    public function test_resolve_returns_null_when_no_contexts_exist(): void
    {
        $result = $this->resolver->resolveForClient(null, null);

        $this->assertNull($result);
    }

    public function test_resolve_prioritizes_provided_over_client(): void
    {
        $clientContext = ServiceContext::factory()->create(['is_active' => true]);
        $providedContext = ServiceContext::factory()->create(['is_active' => true]);

        $client = Client::factory()->create([
            'service_context_id' => $clientContext->id,
        ]);

        $result = $this->resolver->resolveForClient($client->id, $providedContext->id);

        $this->assertEquals($providedContext->id, $result);
    }
}
