<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * EM-01: the eMAR dashboard counts due/overdue doses from the scheduled
 * dose slots on the worker's (NZ) day — never from `pending` administration
 * rows, which production does not write.
 *
 * C6(a): the dashboard and the /dashboard widget read the dose-slot
 * projection (P09): overdue = the dose window has ended with nothing
 * recorded; admin rate = given ÷ doses whose window has ended. Meds today
 * still builds its own board (time passed = overdue) until it moves onto the
 * projection, so mid-window the two differ on "overdue".
 */
class DashboardScheduleCountsTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Pacific/Auckland';

    protected User $worker;

    protected Client $client;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * 09:00 NZST is 21:00 UTC on the previous calendar day, so a UTC
     * whereDate would read the wrong day. 23:30 is the late-shift check.
     *
     * At 09:00 the 06:00 dose is given and 07:00's window (to 08:00) has
     * ended unrecorded: 1 overdue. 08:00 (window to 09:00, inclusive) and
     * 08:30 are inside their windows: due now, not overdue. Admin rate =
     * 1 given of the 2 doses whose window has ended. By 23:30 every window
     * has ended: 3 overdue, 1 of 4 given.
     *
     * @return array<string, array{string, string, int, int, float}>
     */
    public static function workerClockTimes(): array
    {
        return [
            'morning 09:00 NZ (previous UTC day)' => ['2026-06-15 09:00:00', '9:00 AM', 1, 2, 50.0],
            'late 23:30 NZ' => ['2026-06-15 23:30:00', '11:30 PM', 3, 4, 25.0],
        ];
    }

    #[DataProvider('workerClockTimes')]
    public function test_three_unrecorded_past_slots_show_as_three_on_the_dashboard_and_meds_today(
        string $localNow,
        string $nowLabel,
        int $overdue,
        int $due,
        float $adminRate,
    ): void {
        Carbon::setTestNow(Carbon::parse($localNow, self::TZ)->utc());
        $this->seedOneClientOnShift();

        $given = $this->order('Levothyroxine 50mcg', '06:00');
        $this->order('Metformin 500mg', '07:00');
        $this->order('Sertraline 50mg', '08:00');
        $this->order('Vitamin D 1000IU', '08:30');
        ClientMedicationAdministration::query()->create([
            'client_id' => $this->client->id,
            'client_medication_id' => $given->id,
            'administered_by' => $this->worker->id,
            'status' => 'given',
            'scheduled_for' => Carbon::parse('2026-06-15 06:00:00', self::TZ)->utc(),
            'administered_at' => Carbon::parse('2026-06-15 06:05:00', self::TZ)->utc(),
        ]);

        $this->actingAs($this->worker)
            ->get('/emar')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/Index')
                ->where('date', '2026-06-15')
                ->where('isToday', true)
                ->where('nowLabel', $nowLabel)
                ->where('stats.totalToday', 4)
                ->where('stats.givenToday', 1)
                ->where('stats.overdue', $overdue)
                ->where('stats.dueNow', 3)
                ->where('stats.pendingToday', 3)
                ->where('stats.eligibleToday', $due)
                ->where('stats.adminRate', fn ($rate) => (float) $rate === $adminRate)
                ->where('clientBoard', fn ($board) => collect($board)->count() === 1
                    && collect($board)->first()['overdue'] === $overdue
                    && collect($board)->first()['given'] === 1
                    && collect($board)->first()['done'] === 1
                    && collect($board)->first()['total'] === 4
                    && collect($board)->first()['status'] === 'attention')
                ->where('actionCentre', fn ($items) => collect($items)
                    ->where('type', 'overdue_dose')
                    ->count() === $overdue));

        // Meds today's own board (until it moves onto the projection): every
        // unrecorded dose whose time has passed is "overdue".
        $this->actingAs($this->worker)
            ->get('/meds/today')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('meds/today/index')
                ->where('schedule', fn ($rows) => collect($rows)->count() === 4
                    && collect($rows)->where('status', 'overdue')->count() === 3
                    && collect($rows)->where('status', 'given')->count() === 1));

        // NF-25: the home /dashboard eMAR widget shows the dashboard's numbers.
        $this->actingAs($this->asManager())
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('emarWidgets.dueNow', 3)
                ->where('emarWidgets.overdue', $overdue)
                ->where('emarWidgets.adminRate', fn ($rate) => (float) $rate === $adminRate));
    }

    public function test_admin_rate_is_not_applicable_before_any_dose_is_due(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 05:00:00', self::TZ)->utc());
        $this->seedOneClientOnShift();
        $this->order('Metformin 500mg', '07:00');

        $this->actingAs($this->worker)
            ->get('/emar')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.totalToday', 1)
                ->where('stats.eligibleToday', 0)
                ->where('stats.adminRate', null)
                ->where('stats.overdue', 0)
                ->where('stats.dueNow', 0));

        $this->actingAs($this->asManager())
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('emarWidgets.adminRate', null)
                ->where('emarWidgets.dueNow', 0));
    }

    private function seedOneClientOnShift(): void
    {
        $this->seed(RbacSeeder::class);
        Cache::flush();

        $this->worker = User::factory()->create([
            'role' => 'team_lead',
            'approved_at' => now(),
        ]);
        $role = Role::query()->where('name', 'team_lead')->first();
        if ($role) {
            $this->worker->roles()->syncWithoutDetaching([$role->id]);
        }
        $this->worker->permissionOverrides()->syncWithoutDetaching(
            Permission::query()
                ->whereIn('key', ['medications.view', 'medications.administer.record'])
                ->pluck('id')
                ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
                ->all(),
        );

        $site = Site::factory()->create(['is_active' => true]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->worker->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => now()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ]);
        $serviceContext = ServiceContext::factory()->create([
            'name' => 'Dashboard counts',
            'type' => 'residential',
            'is_active' => true,
        ]);
        $this->client = Client::factory()->create([
            'first_name' => 'Aroha',
            'last_name' => 'Ngata',
            'service_context_id' => $serviceContext->id,
            'site_id' => $site->id,
            'status' => 'active',
        ]);

        Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $site->id,
            'service_context_id' => $serviceContext->id,
            'user_id' => $this->worker->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(3),
            'actual_starts_at' => now()->subMinutes(30),
            'actual_ends_at' => null,
            'status' => 'in_progress',
        ]);
    }

    /** /dashboard (and its eMAR widget) is for rostering managers; frontline staff go to My Day. */
    private function asManager(): User
    {
        $this->worker->permissionOverrides()->syncWithoutDetaching(
            Permission::query()
                ->where('key', 'shifts.manageAny')
                ->pluck('id')
                ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
                ->all(),
        );

        return $this->worker->fresh();
    }

    private function order(string $name, string $doseTime): ClientMedication
    {
        return ClientMedication::query()->create([
            'client_id' => $this->client->id,
            'name' => $name,
            'dosage' => '1 tablet',
            'frequency' => 'Daily',
            'dose_times' => [$doseTime],
            'is_prn' => false,
            'active' => true,
            'state' => 'active',
        ]);
    }
}
