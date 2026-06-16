<?php

namespace Tests\Feature\Operations;

use App\Models\Client;
use App\Models\Permission;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The mobile "care" page is retired (this is a web-only app). The old
 * /operations/clients/{client}/care URL now 302-redirects to the full client
 * profile, and the PRN write endpoint it used to host has been removed.
 */
class ClientCareRedirectTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private User $worker;

    protected function setUp(): void
    {
        parent::setUp();

        $site = Site::factory()->create();
        $serviceContext = ServiceContext::factory()->create(['is_active' => true]);
        $this->client = Client::factory()->create([
            'site_id' => $site->id,
            'service_context_id' => $serviceContext->id,
            'organization_id' => 1,
        ]);

        $this->worker = $this->userWithPermissions(['clients.viewAssigned']);
    }

    public function test_legacy_care_url_redirects_to_the_full_client_profile(): void
    {
        $this->actingAs($this->worker)
            ->get(route('operations.clients.care', $this->client))
            ->assertRedirect(route('operations.clients.show', $this->client));
    }

    public function test_the_care_prn_write_endpoint_no_longer_exists(): void
    {
        $this->assertFalse(Route::has('operations.clients.care.prn'));
    }

    /**
     * @param  array<int, string>  $permissionKeys
     * @param  array<string, mixed>  $attributes
     */
    private function userWithPermissions(array $permissionKeys, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'approved_at' => now(),
            'organization_id' => 1,
        ], $attributes));

        foreach ($permissionKeys as $key) {
            $permission = Permission::firstOrCreate(
                ['key' => $key],
                ['description' => $key]
            );

            $user->permissionOverrides()->syncWithoutDetaching([
                $permission->id => ['allowed' => true],
            ]);
        }

        return $user;
    }
}
