<?php

namespace Tests\Feature\Operations;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\Site;
use App\Models\User;
use App\Services\ShiftHandoverService;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HandoverControlledMedicationVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private ShiftHandover $handover;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true]);
        $client = Client::factory()->create([
            'site_id' => $this->site->id,
            'status' => 'active',
        ]);
        $outgoingStaff = $this->siteStaff();
        $controlledWitness = $this->siteStaff();
        $outgoingShift = Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $this->site->id,
            'user_id' => $outgoingStaff->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
            'status' => 'in_progress',
        ]);

        $this->handover = ShiftHandover::query()->create([
            'outgoing_shift_id' => $outgoingShift->id,
            'client_id' => $client->id,
            'outgoing_staff_id' => $outgoingStaff->id,
            'status' => ShiftHandoverService::STATUS_DRAFT,
            'handover_notes' => 'Routine clinical handover.',
            'medications_due' => [
                ['label' => 'Morphine 5mg — due 20:00'],
            ],
            'cd_required' => true,
            'cd_verification' => [
                'result' => 'discrepancy',
                'witness_id' => $controlledWitness->id,
                'witness_name' => $controlledWitness->name,
                'notes' => 'Morphine balance requires reconciliation.',
                'verified_at' => now()->toISOString(),
                'verified_by' => $outgoingStaff->id,
                'verified_by_name' => $outgoingStaff->name,
            ],
        ]);
    }

    public function test_non_controlled_reader_receives_no_cd_handover_fields_on_emar_or_operations_surfaces(): void
    {
        $viewer = $this->viewer(canViewControlled: false);

        $this->actingAs($viewer)
            ->get('/emar/handovers?site_id='.$this->site->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/Handovers')
                ->where('handovers.0.medications_due', [])
                ->where('handovers.0.cd_required', false)
                ->where('handovers.0.cd_verification', null)
            );

        $this->actingAs($viewer)
            ->get(route('operations.handovers.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('operations/handovers/Index')
                ->where('handovers.0.medications_due', [])
                ->where('handovers.0.cd_required', false)
                ->where('handovers.0.cd_verification', null)
            );

        $this->actingAs($viewer)
            ->get(route('operations.handovers.show', $this->handover))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('operations/handovers/Show')
                ->where('handover.medications_due', [])
                ->where('handover.cd_required', false)
                ->where('handover.cd_verification', null)
            );
    }

    public function test_exact_controlled_reader_retains_cd_handover_fields_on_emar_or_operations_surfaces(): void
    {
        $viewer = $this->viewer(canViewControlled: true);

        $this->actingAs($viewer)
            ->get('/emar/handovers?site_id='.$this->site->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('handovers.0.medications_due.0', 'Morphine 5mg — due 20:00')
                ->where('handovers.0.cd_required', true)
                ->where('handovers.0.cd_verification.result', 'discrepancy')
                ->where('handovers.0.cd_verification.notes', 'Morphine balance requires reconciliation.')
            );

        $this->actingAs($viewer)
            ->get(route('operations.handovers.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('handovers.0.medications_due.0', 'Morphine 5mg — due 20:00')
                ->where('handovers.0.cd_required', true)
                ->where('handovers.0.cd_verification.result', 'discrepancy')
                ->where('handovers.0.cd_verification.notes', 'Morphine balance requires reconciliation.')
            );

        $this->actingAs($viewer)
            ->get(route('operations.handovers.show', $this->handover))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('handover.medications_due.0', 'Morphine 5mg — due 20:00')
                ->where('handover.cd_required', true)
                ->where('handover.cd_verification.result', 'discrepancy')
                ->where('handover.cd_verification.notes', 'Morphine balance requires reconciliation.')
            );
    }

    public function test_handover_mar_link_uses_the_outgoing_shift_nz_day_and_exact_person_authority(): void
    {
        $viewer = $this->viewer(canViewControlled: false);
        $shift = $this->handover->outgoingShift;
        // 11:30 UTC is already the following New Zealand daylight-saving day.
        $shift->update(['starts_at' => Carbon::parse('2026-10-04 11:30:00', 'UTC'),
            'ends_at' => Carbon::parse('2026-10-04 15:30:00', 'UTC')]);
        $url = route('operations.handovers.show', $this->handover);
        $this->actingAs($viewer)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('handover.medication_date', '2026-10-05')->where('handover.mar_url', null));

        $this->handover->client->supportWorkers()->attach($viewer->id);
        $source = '/operations/handovers?site_id='.$this->site->id;
        $response = $this->actingAs($viewer->fresh())->get($url.'?'.http_build_query(['return_to' => $source]))->assertOk();
        parse_str(parse_url($response->inertiaProps('handover.mar_url'), PHP_URL_QUERY), $query);
        $this->assertSame((string) $this->handover->client_id, $query['client_id']);
        $this->assertSame((string) $this->site->id, $query['site_id']);
        $this->assertSame('2026-10-05', $query['date']);
        $this->assertSame($source, $query['return_to']);
    }

    public function test_handover_controlled_register_link_requires_exact_person_and_controlled_reader_and_keeps_day(): void
    {
        $viewer = $this->viewer(canViewControlled: true);
        $this->handover->outgoingShift->update(['starts_at' => Carbon::parse('2026-10-04 11:30:00', 'UTC'),
            'ends_at' => Carbon::parse('2026-10-04 15:30:00', 'UTC')]);
        $url = route('operations.handovers.show', $this->handover);
        $this->actingAs($viewer)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('handover.cd_register_url', null));
        $this->handover->client->supportWorkers()->attach($viewer->id);
        $source = '/operations/handovers?site_id='.$this->site->id;
        $response = $this->actingAs($viewer->fresh())->get($url.'?'.http_build_query(['return_to' => $source]))->assertOk();
        $link = $response->inertiaProps('handover.cd_register_url');
        $this->assertSame('/emar/controlled', parse_url($link, PHP_URL_PATH));
        parse_str(parse_url($link, PHP_URL_QUERY), $query);
        $this->assertSame(['client_id' => (string) $this->handover->client_id, 'site_id' => (string) $this->site->id,
            'date' => '2026-10-05', 'return_to' => $source], $query);
        $this->get($link)->assertOk();
        $viewer->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'medications.controlled.view')->sole()->id => ['allowed' => false]]);
        $this->actingAs($viewer->fresh())->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('handover.cd_register_url', null));
    }

    private function viewer(bool $canViewControlled): User
    {
        $viewer = $this->siteStaff();
        $permissions = Permission::query()
            ->whereIn('key', [
                'medications.view',
                'medications.controlled.view',
                'shifts.viewAny',
            ])
            ->get()
            ->mapWithKeys(fn (Permission $permission) => [
                $permission->id => [
                    'allowed' => $permission->key === 'medications.controlled.view'
                        ? $canViewControlled
                        : true,
                ],
            ])
            ->all();
        $viewer->permissionOverrides()->syncWithoutDetaching($permissions);

        return $viewer->unsetRelation('permissionOverrides')->unsetRelation('roles');
    }

    private function siteStaff(): User
    {
        $user = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => null,
        ]);

        return $user;
    }
}
