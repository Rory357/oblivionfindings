<?php

/*
 * EA-011: the Respite workspace sends chart alerts, allergies and medical
 * profiles. Every list is limited to the viewer's approved houses and people,
 * medication content passes the per-person rule (ClientPolicy::viewMedications,
 * the Medical-section gate), chart-alert text follows controlled concealment,
 * and allergies come from the canonical allergy record.
 */

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedicalProfile;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAlert;
use App\Models\Permission;
use App\Models\RespiteBooking;
use App\Models\RespiteBookingRequest;
use App\Models\RespiteReferral;
use App\Models\RespiteStay;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->houseA = Site::factory()->create(['name' => 'Kowhai House']);
    $this->houseB = Site::factory()->create(['name' => 'Rimu House']);

    $this->staffAt = function (Site $site, array $permissions, ?string $roleName = null): User {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        if ($roleName !== null) {
            $user->roles()->attach(Role::where('name', $roleName)->firstOrFail());
        } else {
            $role = Role::query()->create([
                'name' => 'respite_privacy_'.$user->id,
                'label' => 'Respite privacy '.$user->id,
                'level' => 20,
                'type' => 'custom',
            ]);
            $role->permissions()->sync(Permission::query()->whereIn('key', $permissions)->pluck('id'));
            $user->roles()->attach($role);
        }
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
        ]);

        return $user;
    };

    $this->resident = function (Site $site, string $first): Client {
        $client = Client::factory()->create(['site_id' => $site->id, 'first_name' => $first, 'last_name' => 'Ngata']);
        ClientMedicalProfile::query()->create([
            'client_id' => $client->id,
            'medical_history' => 'Epilepsy since childhood',
            'mental_health_history' => 'Anxiety',
            'allergies' => ['Peanuts'],
            'allergy_records' => [[
                'key' => 'peanut-'.$client->id,
                'allergen' => 'Peanuts',
                'severity' => 'life_threatening',
                'reaction' => 'Anaphylaxis',
                'notes' => null,
                'identified_date' => null,
                'identified_by' => null,
                'source_register_ids' => [],
                'removed_at' => null,
            ]],
            'allergies_canonical_at' => now(),
        ]);
        ClientMedicationAlert::query()->create([
            'client_id' => $client->id,
            'type' => 'administration',
            'title' => 'Crush tablets, never with dairy',
            'detail' => 'Give with water only.',
            'enabled' => true,
            'prompt_on_open' => true,
        ]);
        RespiteReferral::query()->create([
            'client_id' => $client->id,
            'referrer_name' => 'NASC Coordinator',
            'referral_reason' => 'Planned respite block',
            'urgency' => 'planned',
            'status' => 'received',
            'received_at' => now(),
        ]);
        RespiteBookingRequest::query()->create([
            'client_id' => $client->id,
            'requested_start' => now()->addDays(5),
            'requested_end' => now()->addDays(8),
            'requirements' => [],
            'status' => 'submitted',
        ]);
        $booking = RespiteBooking::factory()->create([
            'client_id' => $client->id,
            'location_id' => $site->id,
            'status' => 'confirmed',
            'start_at' => now()->subDay(),
            'end_at' => now()->addDays(3),
        ]);
        RespiteStay::query()->create([
            'booking_id' => $booking->id,
            'client_id' => $client->id,
            'status' => 'admitted',
            'actual_start' => now(),
        ]);

        return $client;
    };
});

test('every workspace list is limited to the viewer’s approved houses', function () {
    $a1 = ($this->resident)($this->houseA, 'Aroha');
    $b1 = ($this->resident)($this->houseB, 'Bella');
    $reader = ($this->staffAt)($this->houseA, ['respite.viewAny', 'clients.viewAny', 'medications.view']);

    $this->actingAs($reader)
        ->get('/respite')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('respite/index')
            ->has('referrals', 1)->where('referrals.0.clientId', $a1->id)
            ->has('requests', 1)->where('requests.0.clientId', $a1->id)
            ->has('bookings', 1)->where('bookings.0.clientId', $a1->id)
            ->has('stays', 1)->where('stays.0.clientId', $a1->id)
            ->where('clients', fn ($clients) => collect($clients)->pluck('id')->doesntContain($b1->id)
                && collect($clients)->pluck('id')->contains($a1->id)));
});

test('an ordinary support worker sees only the residents they are assigned to', function () {
    $a1 = ($this->resident)($this->houseA, 'Aroha');
    ($this->resident)($this->houseA, 'Anaru');
    $worker = ($this->staffAt)($this->houseA, [], 'support_worker');
    $a1->supportWorkers()->syncWithoutDetaching([$worker->id]);

    $this->actingAs($worker)
        ->get('/respite')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('referrals', 1)->where('referrals.0.clientId', $a1->id)
            ->has('stays', 1)->where('stays.0.clientId', $a1->id)
            ->where('stays.0.criticalAlerts.0.label', 'Peanuts'));
});

test('readers without the Medical section get no allergies, chart alerts or medical history', function () {
    ($this->resident)($this->houseA, 'Aroha');
    $reader = ($this->staffAt)($this->houseA, [
        'respite.viewAny', 'respite.update', 'respite.stays.manage', 'clients.viewAny', 'clients.update',
    ]);

    $this->actingAs($reader)
        ->get('/respite')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('stays', 1)
            ->has('stays.0.criticalAlerts', 0)
            ->has('bookings.0.criticalAlerts', 0)
            // The check-in safety gate stays truthful without disclosing the allergen.
            ->where('stays.0.anaphylaxisCheckRequired', true)
            ->where('referrals.0.clientProfileCanEditMedical', false)
            ->missing('referrals.0.clientProfilePrefill.medical')
            ->missing('requests.0.clientProfilePrefill.medical')
            ->missing('referrals.0.clientProfilePrefill.conditions'));
});

test('chart-alert text is concealed from readers without controlled view for a person on a controlled medicine', function () {
    $a1 = ($this->resident)($this->houseA, 'Aroha');
    ClientMedication::factory()->create([
        'client_id' => $a1->id,
        'name' => 'Oxycodone',
        'controlled_drug' => true,
        'active' => true,
        'state' => 'active',
        'approval_status' => 'verified',
    ]);
    $reader = ($this->staffAt)($this->houseA, ['respite.viewAny', 'clients.viewAny', 'medications.view']);

    $this->actingAs($reader)
        ->get('/respite')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('stays.0.criticalAlerts', fn ($alerts) => collect($alerts)->contains(
                fn ($alert) => $alert['type'] === 'medication_alert'
                    && $alert['label'] === 'Chart alert — details need controlled-medicine access'
                    && $alert['detail'] === null,
            ) && collect($alerts)->doesntContain(fn ($alert) => str_contains((string) $alert['label'], 'dairy')))
            // Canonical health-profile allergy, not the legacy register.
            ->where('stays.0.criticalAlerts.0.label', 'Peanuts')
            ->where('stays.0.criticalAlerts.0.requiresAcknowledgement', true));
});

test('a canonical-only life-threatening allergy still requires the anaphylaxis acknowledgement at check-in', function () {
    $a1 = ($this->resident)($this->houseA, 'Aroha');
    $stay = RespiteStay::query()->where('client_id', $a1->id)->firstOrFail();
    $manager = ($this->staffAt)($this->houseA, [], 'coordinator');

    $this->actingAs($manager)
        ->post(route('respite.stays.checkin', $stay))
        ->assertSessionHasErrors('anaphylaxis_acknowledgement');

    expect($stay->fresh()->status)->toBe('admitted');
});
