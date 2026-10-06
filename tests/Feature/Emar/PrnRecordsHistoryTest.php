<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationFollowup;
use App\Models\MedicationPrnEffectiveness;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Covers the PRN Records additions: the server-paginated History archive (BK2),
 * the effectiveness re-record (updateOrCreate, BK4), and the near-limit
 * drill-down payload — today's per-dose timeline + the over-limit incident
 * reference (BK3).
 */
class PrnRecordsHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Keep the explicit fallback contract; public defaults are covered separately.
        config(['medications.person_record' => 'legacy']);
    }

    public function test_history_is_paginated_and_carries_detail_fields(): void
    {
        $user = $this->prnAdmin();
        $site = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $med = $this->prnMed($client);
        $dose = $this->dose($client, $med, $user, [
            'pulse_bpm' => 72,
            'blood_pressure_systolic' => 120,
            'blood_pressure_diastolic' => 80,
        ]);
        MedicationPrnEffectiveness::query()->create([
            'client_medication_administration_id' => $dose->id,
            'client_id' => $client->id,
            'client_medication_id' => $med->id,
            'effectiveness' => 'effective',
            'review_minutes_after' => 30,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/emar/prn?site_id='.$site->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('history.data', 1)
                ->where('history.meta.total', 1)
                ->where('history.meta.per_page', 25)
                ->where('history.meta.current_page', 1)
                ->where('history.data.0.id', $dose->id)
                ->where('history.data.0.effectiveness', 'effective')
                ->where('history.data.0.effectiveness_detail.review_minutes_after', 30)
                ->where('history.data.0.baseline.pulse_bpm', 72)
                ->where('history.data.0.mar_url', fn ($v) => is_string($v) && str_contains($v, 'client_id='))
                ->has('history_givers', 1)
            );
    }

    public function test_history_effectiveness_and_page_filters(): void
    {
        $user = $this->prnAdmin();
        $site = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $med = $this->prnMed($client);

        $reviewed = $this->dose($client, $med, $user, ['administered_at' => now()->subHours(2)]);
        MedicationPrnEffectiveness::query()->create([
            'client_medication_administration_id' => $reviewed->id,
            'client_id' => $client->id,
            'client_medication_id' => $med->id,
            'effectiveness' => 'effective',
            'review_minutes_after' => 30,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);
        $due = $this->dose($client, $med, $user, ['administered_at' => now()->subHour()]);

        // review_due → only the un-reviewed dose; echoes the active filter.
        $this->actingAs($user)
            ->get('/emar/prn?site_id='.$site->id.'&history_eff=review_due')
            ->assertInertia(fn (Assert $page) => $page
                ->has('history.data', 1)
                ->where('history.data.0.id', $due->id)
                ->where('history_active.eff', 'review_due')
            );

        // history_page beyond the result set paginates to an empty page.
        $this->actingAs($user)
            ->get('/emar/prn?site_id='.$site->id.'&history_page=2')
            ->assertInertia(fn (Assert $page) => $page
                ->has('history.data', 0)
                ->where('history.meta.current_page', 2)
                ->where('history.meta.total', 2)
            );
    }

    public function test_effectiveness_review_is_idempotent_on_re_record(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-02T20:00:00Z'));
        $user = $this->prnAdmin();
        $site = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $med = $this->prnMed($client);
        $dose = $this->dose($client, $med, $user, ['administered_at' => now()->subMinutes(30)]);
        $this->onShiftWith($user, $client);
        $first = [
            'request_uuid' => (string) Str::uuid(), 'revision' => 1,
            'client_medication_administration_id' => $dose->id,
            'effectiveness' => 'effective', 'review_minutes_after' => 30,
        ];
        $this->actingAs($user)->from('/emar/prn')->post('/meds/today/prn/effect', $first)
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('medication_prn_effectiveness', [
            'client_medication_administration_id' => $dose->id, 'effectiveness' => 'effective',
            'review_minutes_after' => 30, 'reviewed_by' => $user->id, 'reviewed_at' => '2026-10-02 20:00:00',
        ]);
        $work = MedicationFollowup::query()->where('administration_id', $dose->id)->where('type', 'effect')->sole();
        $originalEvent = $work->events()->where('action', 'effect')->sole();
        $originalEvidence = $originalEvent->getRawOriginal();
        $this->assertSame('effective', $originalEvent->data['outcome']);
        $this->travel(30)->minutes();
        $amendment = [
            'request_uuid' => (string) Str::uuid(), 'revision' => $work->fresh()->revision,
            'client_medication_administration_id' => $dose->id,
            'effectiveness' => 'not_effective', 'review_minutes_after' => 60,
            'told' => 'Synthetic on-call clinician', 'escalation_action' => 'Requested advice and stayed with the person.',
        ];
        // Amendment updates the one current summary and retains its earlier event.
        $this->actingAs($user)->from('/emar/prn')->post('/meds/today/prn/effect', $amendment)
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('medication_prn_effectiveness', 1);
        $this->assertDatabaseHas('medication_prn_effectiveness', [
            'client_medication_administration_id' => $dose->id, 'effectiveness' => 'not_effective',
            'review_minutes_after' => 60, 'reviewed_by' => $user->id, 'reviewed_at' => '2026-10-02 20:30:00',
            'escalation_needed' => true, 'escalation_action' => $amendment['escalation_action'],
        ]);
        $amended = $work->events()->where('action', 'amend_effect')->sole();
        $this->assertSame((int) $user->id, (int) $amended->actor_id);
        $this->assertSame('not_effective', $amended->data['outcome']);
        $this->assertSame($originalEvidence, $originalEvent->fresh()->getRawOriginal());
        $eventCount = $work->events()->count();
        $summaryBefore = MedicationPrnEffectiveness::query()->sole()->getRawOriginal();
        $this->actingAs($user)->from('/emar/prn')->post('/meds/today/prn/effect', $amendment)
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($eventCount, $work->events()->count());
        $this->assertSame($summaryBefore, MedicationPrnEffectiveness::query()->sole()->getRawOriginal());
        $this->assertSame($originalEvidence, $originalEvent->fresh()->getRawOriginal());
    }

    public function test_near_limit_payload_carries_timeline_and_over_limit_incident(): void
    {
        $user = $this->prnAdmin();
        $site = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $client = Client::factory()->create(['first_name' => 'Aroha', 'last_name' => 'Ngata', 'site_id' => $site->id, 'status' => 'active']);
        $med = $this->prnMed($client, ['name' => 'Lorazepam PRN', 'max_per_day' => 2]);
        $this->dose($client, $med, $user, ['administered_at' => now()->subHours(3)]);
        $this->dose($client, $med, $user, ['administered_at' => now()->subHour()]);
        ClientIncident::factory()->create([
            'client_id' => $client->id,
            'title' => 'PRN limit exceeded: Lorazepam PRN',
            'status' => 'draft',
            'occurred_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/emar/prn?site_id='.$site->id)
            ->assertInertia(fn (Assert $page) => $page
                ->where('prn_medications.0.over_limit', true)
                ->has('prn_medications.0.today_doses', 2)
                ->where('prn_medications.0.today_doses.0.given_by', $user->name)
                ->where('prn_medications.0.over_limit_incident.status', 'draft')
                ->where('prn_medications.0.over_limit_incident.url', fn ($v) => is_string($v) && str_contains($v, '/incidents'))
            );
    }

    private function prnAdmin(): User
    {
        $this->seed(RbacSeeder::class);
        $user = $this->makeRoleUser('admin');
        $this->grantPermissions($user, ['medications.view', 'medications.administer.record', 'medications.controlled.witness']);

        return $user;
    }

    /**
     * Recording an effectiveness review needs a current HR profile at the
     * client's Site and a clocked-in shift covering the client (or
     * break-glass), like any other medication record.
     */
    private function onShiftWith(User $user, Client $client): void
    {
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $client->site_id,
            'secondary_site_ids' => [],
            'start_date' => now()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ]);
        Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $client->site_id,
            'user_id' => $user->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(3),
            'actual_starts_at' => now()->subMinutes(30),
            'actual_ends_at' => null,
            'status' => 'in_progress',
            'started_by' => $user->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function prnMed(Client $client, array $overrides = []): ClientMedication
    {
        return ClientMedication::query()->create(array_merge([
            'client_id' => $client->id,
            'name' => 'Paracetamol PRN',
            'dosage' => '500mg',
            'frequency' => 'As needed',
            'is_prn' => true, 'controlled_drug' => false,
            'prn_reason' => 'Pain',
            'max_per_day' => 4,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function dose(Client $client, ClientMedication $med, User $by, array $overrides = []): ClientMedicationAdministration
    {
        return ClientMedicationAdministration::query()->create(array_merge([
            'client_id' => $client->id,
            'client_medication_id' => $med->id,
            'administered_by' => $by->id,
            'administered_at' => now()->subHour(),
            'status' => 'given',
            'dose_given' => '500mg',
            'reason' => 'Pain',
        ], $overrides));
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
