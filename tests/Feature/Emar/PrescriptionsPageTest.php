<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\MedicationPrescriberOrder;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The redesigned Prescriptions & Orders page serves a flat order/covert payload
 * (+ medications for the covert/link selects, + active-site brand colour), and
 * verbal orders now capture read-back metadata + a countersignature method.
 */
class PrescriptionsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_serves_flat_payload_with_brand_colour(): void
    {
        $this->seed(RbacSeeder::class);
        $user = $this->makeRoleUser('admin');
        $this->grantPermissions($user, ['medications.view', 'medications.orders.manage', 'clients.update', 'medications.controlled.witness']);

        $site = Site::factory()->create(['type' => 'house', 'is_active' => true, 'brand_colour' => '#6D4C41']);
        $client = Client::factory()->create(['first_name' => 'Aroha', 'last_name' => 'Ngata', 'site_id' => $site->id, 'status' => 'active']);
        $this->assignUserToClient($user, $client);
        $med = ClientMedication::query()->create(['client_id' => $client->id, 'name' => 'Warfarin', 'dosage' => '3mg', 'frequency' => 'Once daily', 'active' => true, 'state' => 'active', 'approval_status' => 'verified']);
        MedicationPrescriberOrder::create([
            'client_id' => $client->id, 'client_medication_id' => $med->id, 'order_type' => 'new', 'status' => 'pending',
            'prescriber_name' => 'Dr Singh', 'medication_name' => 'Warfarin', 'dose' => '3mg', 'route' => 'Oral', 'frequency' => 'Once daily', 'order_date' => '2026-06-10',
        ]);

        $this->actingAs($user)
            ->get('/emar/prescriptions?site_id='.$site->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/Prescriptions')
                ->where('site_brand_colour', '#6D4C41')
                ->has('orders', 1)
                ->where('orders.0.medication_name', 'Warfarin')
                ->where('orders.0.client_name', 'Aroha Ngata')
                // Detail-modal enrichment: site (room is null here) is serialised.
                ->where('orders.0.client_site', $site->name)
                ->has('orders.0.client_room')
                ->has('medications', 1)
                ->where('medications.0.id', $med->id)
                ->has('covert')
                ->has('clients')
                // Client EntityFilter description is driven by site_name.
                ->where('clients.0.site_name', $site->name)
            );
    }

    public function test_verbal_order_captures_read_back_and_requires_countersign(): void
    {
        $this->seed(RbacSeeder::class);
        $user = $this->makeRoleUser('admin');
        $this->grantPermissions($user, ['medications.view', 'medications.orders.manage', 'clients.update']);
        $client = $this->makeScopedClient($user);

        $this->actingAs($user)
            ->post('/emar/prescriptions', [
                'client_id' => $client->id,
                'order_type' => 'verbal',
                'prescriber_name' => 'Dr Lee',
                'medication_name' => 'Amoxicillin',
                'dose' => '500mg',
                'route' => 'Oral',
                'frequency' => 'Three times daily',
                'order_date' => '2026-06-15',
                'read_back_confirmed' => true,
                'read_back_witnessed_by' => $user->id,
            ])
            ->assertSessionHasNoErrors();

        $order = MedicationPrescriberOrder::where('medication_name', 'Amoxicillin')->first();
        $this->assertNotNull($order);
        $this->assertTrue((bool) $order->requires_countersign);
        $this->assertTrue((bool) $order->read_back_confirmed);
        $this->assertSame($user->id, $order->read_back_witnessed_by);
    }

    public function test_countersign_persists_method_and_confirms(): void
    {
        $this->seed(RbacSeeder::class);
        $user = $this->makeRoleUser('admin');
        $this->grantPermissions($user, ['medications.view', 'medications.orders.manage', 'clients.update']);
        $client = $this->makeScopedClient($user);
        $order = MedicationPrescriberOrder::create([
            'client_id' => $client->id, 'order_type' => 'verbal', 'status' => 'pending', 'requires_countersign' => true,
            'prescriber_name' => 'Dr Lee', 'medication_name' => 'Amoxicillin', 'dose' => '500mg', 'route' => 'Oral', 'frequency' => 'TDS', 'order_date' => '2026-06-15',
        ]);

        $this->actingAs($user)
            ->post('/emar/prescriptions/'.$order->id.'/countersign', ['countersign_method' => 'electronic'])
            ->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertNotNull($order->countersigned_at);
        $this->assertSame('electronic', $order->countersign_method);
        $this->assertSame('confirmed', $order->status);
    }

    public function test_order_creation_defaults_prescriber_type_when_posted_blank(): void
    {
        $this->seed(RbacSeeder::class);
        $user = $this->makeRoleUser('admin');
        $this->grantPermissions($user, ['medications.view', 'medications.orders.manage', 'clients.update']);
        $client = $this->makeScopedClient($user);

        // The create dialog posts an empty prescriber_type; ConvertEmptyStringsToNull
        // turns it into null. The column is NOT NULL with a 'gp' default, so a blank
        // prescriber type must fall back to the default instead of a 500 (regression
        // for the integrity-constraint violation on every browser-submitted order).
        $this->actingAs($user)
            ->post('/emar/prescriptions', [
                'client_id' => $client->id,
                'order_type' => 'new',
                'prescriber_name' => 'Dr Lee',
                'prescriber_type' => '',
                'medication_name' => 'Paracetamol',
                'dose' => '1g',
                'route' => 'Oral',
                'frequency' => 'Twice daily',
                'order_date' => '2026-06-15',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $order = MedicationPrescriberOrder::where('medication_name', 'Paracetamol')->first();
        $this->assertNotNull($order);
        $this->assertSame('gp', $order->prescriber_type);
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

    protected function makeScopedClient(User $user): Client
    {
        $site = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $client = Client::factory()->create(['status' => 'active', 'site_id' => $site->id]);
        $this->assignUserToClient($user, $client);

        return $client;
    }

    protected function assignUserToClient(User $user, Client $client): void
    {
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $client->site_id,
            'secondary_site_ids' => [],
            'start_date' => today()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ]);

        Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $client->site_id,
            'user_id' => $user->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(2),
            'actual_starts_at' => now()->subHour(),
            'actual_ends_at' => null,
            'started_by' => $user->id,
            'status' => 'in_progress',
        ]);
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
