<?php

namespace Tests\Feature\Operations;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\MedicationGovernanceScopeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OperationsShiftAnalyticsMedicationGovernanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_shift_analytics_counts_only_canonical_site_medication_evidence_visible_to_the_viewer(): void
    {
        $this->travelTo(Carbon::parse('2026-04-15 12:00:00'));

        $localSite = Site::factory()->create();
        $foreignSite = Site::factory()->create();
        $localClient = Client::factory()->create([
            'organization_id' => 1,
            'site_id' => $localSite->id,
            'status' => 'active',
        ]);
        $foreignClient = Client::factory()->create([
            'organization_id' => 1,
            'site_id' => $foreignSite->id,
            'status' => 'active',
        ]);
        $recorder = User::factory()->create(['organization_id' => 1]);
        $localShift = Shift::factory()->create([
            'client_id' => $localClient->id,
            'site_id' => $localSite->id,
            'user_id' => $recorder->id,
            'created_by' => $recorder->id,
            'starts_at' => Carbon::parse('2026-04-10 09:00:00'),
            'ends_at' => Carbon::parse('2026-04-10 13:00:00'),
            'actual_starts_at' => Carbon::parse('2026-04-10 09:00:00'),
            'actual_ends_at' => Carbon::parse('2026-04-10 13:00:00'),
            'started_by' => $recorder->id,
            'completed_by' => $recorder->id,
            'status' => 'completed',
        ]);

        $ordinaryMedication = $this->medication($localClient, controlled: false);
        $controlledMedication = $this->medication($localClient, controlled: true);
        $foreignMedication = $this->medication($foreignClient, controlled: false);

        $this->administration($localClient, $ordinaryMedication, $localShift, $recorder);
        $this->administration($localClient, $controlledMedication, $localShift, $recorder);

        // A local shift reference never overrides the administration's canonical
        // Client -> medication ownership or the medication Client's Site.
        $this->administration($localClient, $foreignMedication, $localShift, $recorder);
        $this->administration($foreignClient, $foreignMedication, $localShift, $recorder);

        $ordinaryReader = $this->reportReader($localSite);
        $this->actingAs($ordinaryReader)
            ->get($this->shiftAnalyticsUrl())
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('data.execution_evidence.medication_records', 1)
            );

        $controlledReader = $this->reportReader(
            $localSite,
            [MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY],
        );
        $this->actingAs($controlledReader)
            ->get($this->shiftAnalyticsUrl())
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('data.execution_evidence.medication_records', 2)
            );
    }

    public function test_shift_analytics_medication_evidence_fails_closed_without_an_accessible_site(): void
    {
        $this->travelTo(Carbon::parse('2026-04-15 12:00:00'));

        $site = Site::factory()->create();
        $client = Client::factory()->create([
            'organization_id' => 1,
            'site_id' => $site->id,
            'status' => 'active',
        ]);
        $reader = $this->reportReader(site: null);
        $medication = $this->medication($client, controlled: false);
        $shift = Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'user_id' => $reader->id,
            'created_by' => $reader->id,
            'starts_at' => Carbon::parse('2026-04-10 09:00:00'),
            'ends_at' => Carbon::parse('2026-04-10 13:00:00'),
            'actual_starts_at' => Carbon::parse('2026-04-10 09:00:00'),
            'actual_ends_at' => Carbon::parse('2026-04-10 13:00:00'),
            'started_by' => $reader->id,
            'completed_by' => $reader->id,
            'status' => 'completed',
        ]);
        $this->administration($client, $medication, $shift, $reader);

        $this->actingAs($reader)
            ->get($this->shiftAnalyticsUrl())
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('data.total_shifts', 0)
                ->where('data.completed', 0)
                ->where('data.cancelled', 0)
                ->where('data.no_show', 0)
                ->where('data.assigned', 0)
                ->where('data.unassigned', 0)
                ->where('data.completion_rate', 0)
                ->where('data.cancellation_rate', 0)
                ->where('data.assignment_rate', 0)
                ->where('data.by_status', fn ($rows): bool => collect($rows)->isEmpty())
                ->where('data.by_shift_type', fn ($rows): bool => collect($rows)->isEmpty())
                ->where('data.by_service_context', fn ($rows): bool => collect($rows)->isEmpty())
                ->where('data.by_day_of_week', fn ($rows): bool => collect($rows)->isEmpty())
                ->where('data.by_staff', fn ($rows): bool => collect($rows)->isEmpty())
                ->where('data.timesheet_statuses', fn ($rows): bool => collect($rows)->isEmpty())
                ->where('data.execution_evidence.medication_records', 0)
                ->where('data.execution_evidence', fn ($evidence): bool => collect($evidence)
                    ->every(fn ($value): bool => (int) $value === 0))
                ->where('data.coverage_vs_actual_work', fn ($coverage): bool => collect($coverage)
                    ->every(fn ($value): bool => (float) $value === 0.0))
                ->where('data.cost_vs_staffing', fn ($costs): bool => collect($costs)
                    ->every(fn ($value): bool => (float) $value === 0.0))
                ->where('data.historical_site_breakdown', fn ($rows): bool => collect($rows)->isEmpty())
            );
    }

    private function medication(Client $client, bool $controlled): ClientMedication
    {
        return ClientMedication::factory()->create([
            'client_id' => $client->id,
            'name' => $controlled ? 'Controlled medication' : 'Ordinary medication',
            'controlled_drug' => $controlled,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'approved',
            'start_date' => today()->subMonth()->toDateString(),
            'end_date' => null,
        ]);
    }

    private function administration(
        Client $client,
        ClientMedication $medication,
        Shift $shift,
        User $recorder,
    ): ClientMedicationAdministration {
        return ClientMedicationAdministration::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'shift_id' => $shift->id,
            'administered_by' => $recorder->id,
            'scheduled_for' => Carbon::parse('2026-04-10 09:00:00'),
            'administered_at' => Carbon::parse('2026-04-10 09:05:00'),
            'status' => 'given',
            'dose_given' => '1 tablet',
        ]);
    }

    /**
     * @param  array<int, string>  $additionalPermissions
     */
    private function reportReader(?Site $site, array $additionalPermissions = []): User
    {
        $reader = User::factory()->create([
            'organization_id' => 1,
            'approved_at' => now(),
        ]);

        foreach (['operations.reports.view', ...$additionalPermissions] as $permissionKey) {
            $permission = Permission::query()->firstOrCreate(
                ['key' => $permissionKey],
                ['description' => $permissionKey, 'group' => 'operations', 'module' => 'operations'],
            );
            $reader->permissionOverrides()->attach($permission->id, ['allowed' => true]);
        }

        if ($site !== null) {
            HrEmployeeProfile::query()->create([
                'user_id' => $reader->id,
                'tenant_id' => 1,
                'employee_number' => 'EMP-OPS-REPORT-'.$reader->id,
                'work_email' => $reader->email,
                'position_title' => 'Operations report reader',
                'position_role' => 'manager',
                'employment_type' => 'full_time',
                'start_date' => today()->subMonth()->toDateString(),
                'is_active' => true,
                'primary_site_id' => $site->id,
                'secondary_site_ids' => [],
            ]);
        }

        return $reader;
    }

    private function shiftAnalyticsUrl(): string
    {
        return route('operations.reports.show', [
            'type' => 'shift-analytics',
            'date_from' => '2026-04-01',
            'date_to' => '2026-04-30',
        ]);
    }
}
