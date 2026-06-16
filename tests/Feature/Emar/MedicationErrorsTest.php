<?php

namespace Tests\Feature\Emar;

use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\MedicationError;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The redesigned Medication Errors register resolves the active site's brand
 * colour, surfaces near-miss + trend analytics, captures the NCC-MERP fields
 * (reached-client / harm band / open disclosure), and exposes the missing
 * close-out path (resolved → closed).
 */
class MedicationErrorsTest extends TestCase
{
    use RefreshDatabase;

    private function seedErrors(): array
    {
        $this->seed(RbacSeeder::class);
        $user = $this->makeRoleUser('admin');
        $this->grantPermissions($user, ['medications.view', 'medications.administer.record', 'medications.administer.correct', 'clients.update']);
        $site = Site::factory()->create(['type' => 'house', 'is_active' => true, 'brand_colour' => '#5E35B1']);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);

        return compact('user', 'site', 'client');
    }

    public function test_page_serves_brand_colour_and_stats(): void
    {
        ['user' => $user, 'site' => $site, 'client' => $client] = $this->seedErrors();
        MedicationError::query()->create([
            'client_id' => $client->id, 'error_type' => 'omission', 'severity' => 'near_miss', 'description' => 'Dose missed but caught.',
            'status' => 'reported', 'reported_by' => $user->id, 'reported_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/emar/errors?site_id='.$site->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/MedicationErrors')
                ->where('site_brand_colour', '#5E35B1')
                ->has('errors', 1)
                ->where('errors.0.ref', 'ERR-'.str_pad((string) MedicationError::query()->first()->id, 4, '0', STR_PAD_LEFT))
                ->has('stats.trend', 8)
                ->has('stats.by_severity')
                ->where('stats.near_miss', 1)
            );
    }

    public function test_store_persists_ncc_merp_fields(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedErrors();

        $this->actingAs($user)
            ->from('/emar/errors')
            ->post('/emar/errors', [
                'client_id' => $client->id, 'error_type' => 'wrong_dose', 'severity' => 'moderate',
                'description' => 'Double dose given.', 'reached_client' => 'yes', 'open_disclosure' => 'pending',
            ])
            ->assertSessionHasNoErrors();

        $error = MedicationError::query()->firstOrFail();
        $this->assertSame('yes', $error->reached_client);
        $this->assertSame('pending', $error->open_disclosure);
    }

    public function test_close_out_marks_closed(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedErrors();
        $error = MedicationError::query()->create([
            'client_id' => $client->id, 'error_type' => 'wrong_dose', 'severity' => 'moderate', 'description' => 'x',
            'status' => 'resolved', 'reported_by' => $user->id, 'reported_at' => now(),
        ]);

        $this->actingAs($user)
            ->from('/emar/errors')
            ->post("/emar/errors/{$error->id}/close", ['close_note' => 'Learning embedded.'])
            ->assertSessionHasNoErrors();

        $error->refresh();
        $this->assertSame('closed', $error->status);
        $this->assertNotNull($error->closed_at);
        $this->assertSame($user->id, $error->closed_by);
    }

    public function test_close_rejects_non_resolved_error(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedErrors();
        $error = MedicationError::query()->create([
            'client_id' => $client->id, 'error_type' => 'wrong_dose', 'severity' => 'moderate', 'description' => 'x',
            'status' => 'reported', 'reported_by' => $user->id, 'reported_at' => now(),
        ]);

        $this->actingAs($user)
            ->from('/emar/errors')
            ->post("/emar/errors/{$error->id}/close", ['close_note' => 'too soon'])
            ->assertSessionHasErrors('status');

        $this->assertSame('reported', $error->refresh()->status);
    }

    public function test_link_incident_creates_and_links_incident(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedErrors();
        $error = MedicationError::query()->create([
            'client_id' => $client->id, 'error_type' => 'wrong_dose', 'severity' => 'critical', 'description' => 'Wrong strength dispensed.',
            'status' => 'reported', 'reported_by' => $user->id, 'reported_at' => now(),
        ]);
        $this->assertNull($error->client_incident_id);
        $this->assertSame(0, ClientIncident::query()->count());

        $response = $this->actingAs($user)
            ->from('/emar/errors')
            ->post("/emar/errors/{$error->id}/link-incident");

        $error->refresh();
        $this->assertNotNull($error->client_incident_id, 'The error should be linked to the new incident.');
        $response->assertRedirect(route('incidents.show', $error->client_incident_id));

        $incident = ClientIncident::query()->findOrFail($error->client_incident_id);
        $this->assertSame($client->id, $incident->client_id);
        $this->assertSame('medication_error', $incident->type);
        $this->assertSame($user->id, (int) $incident->reported_by);
        $this->assertSame('critical', $incident->severity);
    }

    public function test_link_incident_is_idempotent_when_already_linked(): void
    {
        ['user' => $user, 'client' => $client] = $this->seedErrors();
        $error = MedicationError::query()->create([
            'client_id' => $client->id, 'error_type' => 'omission', 'severity' => 'minor', 'description' => 'Dose missed.',
            'status' => 'investigating', 'reported_by' => $user->id, 'reported_at' => now(),
        ]);

        // First call creates + links the incident.
        $this->actingAs($user)->from('/emar/errors')->post("/emar/errors/{$error->id}/link-incident");
        $firstIncidentId = $error->refresh()->client_incident_id;
        $this->assertNotNull($firstIncidentId);
        $this->assertSame(1, ClientIncident::query()->count());

        // Second call is idempotent: jumps to the existing incident, creates none.
        $response = $this->actingAs($user)->from('/emar/errors')->post("/emar/errors/{$error->id}/link-incident");

        $this->assertSame($firstIncidentId, $error->refresh()->client_incident_id);
        $this->assertSame(1, ClientIncident::query()->count(), 'A second link must not create a duplicate incident.');
        $response->assertRedirect(route('incidents.show', $firstIncidentId));
    }

    protected function makeRoleUser(string $roleName): User
    {
        $user = User::factory()->create(['role' => $roleName, 'approved_at' => now()]);
        $role = Role::query()->where('name', $roleName)->first();
        if ($role) {
            $user->roles()->syncWithoutDetaching([$role->id]);
        }

        return $user;
    }

    /**
     * @param  array<int, string>  $permissionKeys
     */
    protected function grantPermissions(User $user, array $permissionKeys): void
    {
        $permissionMap = Permission::query()
            ->whereIn('key', $permissionKeys)
            ->pluck('id')
            ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
            ->all();

        $user->permissionOverrides()->syncWithoutDetaching($permissionMap);
    }
}
