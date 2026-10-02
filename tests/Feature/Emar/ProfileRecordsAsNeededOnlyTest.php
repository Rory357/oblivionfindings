<?php

namespace Tests\Feature\Emar;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The client profile's recording dialog posts without a scheduled time, so
 * the recording guard refuses every scheduled dose (404). Until P02-6 moves
 * profile recording onto the shared recorder, the profile offers as-needed
 * doses only and links scheduled ones to the MAR chart / Meds today.
 */
class ProfileRecordsAsNeededOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_profile_offers_recording_for_as_needed_medicines_only(): void
    {
        $this->seed(RbacSeeder::class);
        $admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $admin->roles()->attach(Role::where('name', 'admin')->first());
        $site = Site::factory()->create(['is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id]);

        $scheduled = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'name' => 'Metformin',
            'is_prn' => false,
            'active' => true,
            'state' => 'active',
        ]);
        $asNeeded = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'name' => 'Paracetamol',
            'is_prn' => true,
            'active' => true,
            'state' => 'active',
        ]);

        $this->actingAs($admin)
            ->get("/operations/clients/{$client->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('medical.medications', function ($medications) use ($scheduled, $asNeeded): bool {
                    $byId = collect($medications)->keyBy('id');

                    return $byId->has($scheduled->id)
                        && $byId->has($asNeeded->id)
                        && $byId[$scheduled->id]['record_on_profile'] === false
                        && $byId[$asNeeded->id]['record_on_profile'] === true;
                }));
    }
}
