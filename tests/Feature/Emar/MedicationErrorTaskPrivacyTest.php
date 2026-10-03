<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\MedicationError;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\MedicationRecordAccess;
use App\Services\Tasks\Providers\MedicationErrorProvider;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/** Regression for every Tasks surface using the medication-error provider. */
class MedicationErrorTaskPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unrelated_person_in_the_same_house_is_hidden_before_task_projection(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-30 09:30:00', 'Pacific/Auckland')->utc());
        try {
            $this->seed(RbacSeeder::class);
            Cache::flush();
            $site = Site::factory()->create(['is_active' => true]);
            $worker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
            // Explicitly narrow the fixture: seeded operations/report rights can
            // legitimately broaden same-house medication access.
            $worker->permissionOverrides()->sync(
                Permission::query()->get()->mapWithKeys(fn ($permission) => [
                    $permission->id => ['allowed' => $permission->key === 'medications.view'],
                ])->all(),
            );
            $worker->unsetRelation('permissionOverrides')->unsetRelation('roles');
            HrEmployeeProfile::factory()->create([
                'user_id' => $worker->id, 'primary_site_id' => $site->id,
                'secondary_site_ids' => [], 'start_date' => now()->subMonth(),
                'end_date' => null, 'is_active' => true,
            ]);
            $assigned = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
            $hidden = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
            $assigned->supportWorkers()->attach($worker->id);
            $medicine = ClientMedication::query()->create([
                'client_id' => $hidden->id, 'name' => 'Synthetic ordinary medicine',
                'dosage' => '1 tablet', 'frequency' => 'Daily',
                'active' => true, 'controlled_drug' => false,
            ]);
            $error = MedicationError::query()->create([
                'client_id' => $hidden->id, 'client_medication_id' => $medicine->id,
                'error_type' => 'wrong_dose', 'severity' => 'minor',
                'description' => 'Synthetic private error account.',
                'immediate_action' => 'Synthetic response.',
                'reported_by' => $worker->id, 'reported_at' => now(), 'status' => 'reported',
            ]);

            $this->assertTrue(Gate::forUser($worker)->allows('viewMedications', $assigned));
            $this->assertFalse(Gate::forUser($worker)->allows('viewMedications', $hidden));
            $this->assertSame([$assigned->id],
                app(MedicationRecordAccess::class)->readableClientIds($worker, [$assigned->id, $hidden->id]));
            $provider = app(MedicationErrorProvider::class);
            $this->assertSame([], $provider->authorizedTasks($worker),
                'Tasks must not disclose a person whose medication record is denied.');
            $this->assertSame([], $provider->authorizedTasks($worker, ['id' => $error->id, 'include_done' => true]),
                'A direct Task lookup must enforce the same person privacy rule.');
        } finally {
            Carbon::setTestNow();
        }
    }
}
