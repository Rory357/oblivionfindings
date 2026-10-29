<?php

namespace Tests\Feature\HealthSafety;

use App\Console\Commands\HealthSafetyDemoData;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\ChemicalCommandIntent;
use App\Models\Client;
use App\Models\FirstAidRecord;
use App\Models\HazardousSubstance;
use App\Models\HsRiskAssessment;
use App\Models\Permission;
use App\Models\RestraintEvent;
use App\Models\Site;
use App\Models\SubstanceExposureRecord;
use App\Models\User;
use App\Services\HealthSafety\ChemicalCommandService;
use App\Services\HealthSafety\ChemicalExposureCommandAccess;
use App\Services\HealthSafety\ChemicalExposureSourceService;
use App\Services\HealthSafety\FirstAidService;
use App\Services\HealthSafety\HsRiskAssessmentService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class HealthSafetyDemoDataCommandTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Site $fixtureSite;

    private User $admin;

    private User $firstAider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Queue::fake();
        Http::preventStrayRequests();
        Storage::fake('private');

        $this->site = Site::factory()->create(['name' => 'Kōwhai House', 'is_active' => true]);
        // A leftover test fixture Site: the command must never write there.
        $this->fixtureSite = Site::factory()->create(['name' => 'Playwright RELEASE house', 'is_active' => true]);

        $this->admin = $this->staff('Mere Tawhiri', ['role' => 'admin', 'email' => 'admin@demo.test'], [
            'hazards.view', 'hazards.create', 'hazards.manage', 'hr.wellbeing.view', 'healthSafety.viewAllSites',
            'restraints.view', 'restraints.create', 'restraints.review', 'restraints.manage', 'clients.viewAny',
            'incidents.viewAny', 'incidents.create', 'incidents.update',
        ]);
        $this->firstAider = $this->staff('Aroha Ngata', [], [], true);
        $this->staff('Sione Taufa');
        $this->staff('Priya Sharma');
        $this->staff('Tom Reilly', [], [], true, $this->fixtureSite);

        foreach ([['Wiremu', 'Parata'], ['Grace', 'Liu']] as [$first, $last]) {
            Client::factory()->create(['site_id' => $this->site->id, 'first_name' => $first, 'last_name' => $last, 'preferred_name' => null, 'status' => 'active']);
        }
        Client::factory()->create(['site_id' => $this->fixtureSite->id, 'status' => 'active']);

        foreach (['Bleach (sodium hypochlorite)', 'Oven cleaner'] as $name) {
            $substance = $this->substance($name);
            app(ChemicalCommandService::class)->createStorage($substance, $this->admin, ['idempotency_key' => (string) Str::uuid(),
                'parent_expected_version' => $substance->fresh()->source_revision, 'site_id' => $this->site->id,
                'location_description' => 'Locked laundry cupboard', 'current_quantity' => '2.00', 'properly_labelled' => true,
                'segregation_compliant' => true]);
        }
    }

    private function staff(string $name, array $attributes = [], array $permissions = [], bool $firstAider = false, ?Site $site = null): User
    {
        $user = User::factory()->create(['name' => $name, 'role' => 'support_worker', 'approved_at' => now(), 'external_clinical_account' => false, ...$attributes]);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => ($site ?? $this->site)->id, 'secondary_site_ids' => [],
            'is_active' => true, 'start_date' => now('Pacific/Auckland')->subYear()->toDateString(), 'end_date' => null, 'is_first_aider' => $firstAider]);
        foreach ($permissions as $key) {
            $user->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->sole()->id => ['allowed' => true]]);
        }

        return $user;
    }

    private function substance(string $name): HazardousSubstance
    {
        $result = app(ChemicalCommandService::class)->create($this->admin, ['idempotency_key' => (string) Str::uuid(), 'name' => $name, 'physical_form' => 'liquid']);

        return HazardousSubstance::findOrFail($result['root_id']);
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return [
            'first_aid' => FirstAidRecord::withTrashed()->count(),
            'restraint' => RestraintEvent::withTrashed()->count(),
            'chemical' => SubstanceExposureRecord::query()->count(),
            'risk' => HsRiskAssessment::withTrashed()->count(),
        ];
    }

    public function test_running_twice_creates_each_record_once_and_records_read_as_normal(): void
    {
        $this->artisan('health-safety:demo')->assertExitCode(0);
        $first = $this->counts();
        $this->assertSame(['first_aid' => 6, 'restraint' => 3, 'chemical' => 3, 'risk' => 3], $first);

        $this->artisan('health-safety:demo')->assertExitCode(0);
        $this->assertSame($first, $this->counts());

        // Found by key only; never at the leftover fixture Site.
        foreach (range(1, 6) as $n) {
            $row = FirstAidRecord::where('creation_key', HealthSafetyDemoData::key('first-aid-'.$n))->sole();
            $this->assertSame($this->site->id, (int) $row->site_id);
            $this->assertTrue($row->treatment_date->lt(now()));
            $proof = app(FirstAidService::class)->confirmationForViewer($this->admin, ['id' => (int) $row->id, 'lock_version' => 1, 'idempotency_key' => $row->creation_key]);
            $this->assertSame('create', $proof['command'] ?? null);
        }
        $this->assertSame(3, RestraintEvent::whereIn('creation_key', array_map(fn ($n) => HealthSafetyDemoData::key('restraint-'.$n), [1, 2, 3]))->where('site_id', $this->site->id)->count());
        $this->assertSame(3, ChemicalCommandIntent::whereIn('idempotency_key', array_map(fn ($n) => HealthSafetyDemoData::key('chemical-exposure-'.$n), [1, 2, 3]))->count());
        $this->assertSame(0, FirstAidRecord::where('site_id', $this->fixtureSite->id)->count() + RestraintEvent::where('site_id', $this->fixtureSite->id)->count());

        // Chemical exposures carry full accepted provenance (not "Needs fixing").
        foreach (SubstanceExposureRecord::all() as $exposure) {
            $proof = app(ChemicalExposureCommandAccess::class)->withCurrentViewer($this->admin,
                fn ($current, $reads, $sites) => app(ChemicalExposureSourceService::class)->sourceProof($exposure, $current, $reads, $sites));
            $this->assertSame('recorded', $proof['attribution_state']);
        }
        $exposure = SubstanceExposureRecord::where('exposure_type', 'eye_contact')->sole();
        $this->assertStringContainsString('Bleach', (string) $exposure->circumstances);

        // Two approved, one draft; all attached to the real Site.
        $this->assertSame(['active', 'active', 'draft'], HsRiskAssessment::orderBy('id')->pluck('status')->all());
        $this->assertSame([Site::class], HsRiskAssessment::distinct()->pluck('assessable_type')->all());
        $this->assertCount(3, AppSetting::where('key', HealthSafetyDemoData::REGISTRY_KEY)->sole()->value);

        // Nothing visible says it is example data.
        foreach ([FirstAidRecord::pluck('injury_illness_description'), RestraintEvent::pluck('restraint_description'),
            SubstanceExposureRecord::pluck('circumstances'), HsRiskAssessment::pluck('title')] as $texts) {
            foreach ($texts as $text) {
                $this->assertStringNotContainsStringIgnoringCase('demo', (string) $text);
            }
        }
    }

    public function test_dry_run_writes_nothing(): void
    {
        $audits = AuditLog::count();
        $this->artisan('health-safety:demo', ['--dry-run' => true])->assertExitCode(0);
        $this->assertSame(['first_aid' => 0, 'restraint' => 0, 'chemical' => 0, 'risk' => 0], $this->counts());
        $this->assertSame($audits, AuditLog::count());
        $this->assertFalse(AppSetting::where('key', HealthSafetyDemoData::REGISTRY_KEY)->exists());

        $this->artisan('health-safety:demo')->assertExitCode(0);
        $before = $this->counts();
        $audits = AuditLog::count();
        $this->artisan('health-safety:demo', ['--remove' => true, '--dry-run' => true])->assertExitCode(0);
        $this->assertSame(0, FirstAidRecord::onlyTrashed()->count());
        $this->assertSame(0, HsRiskAssessment::where('status', 'archived')->count());
        $this->assertSame($before, $this->counts());
        $this->assertSame($audits, AuditLog::count());
    }

    public function test_remove_archives_only_its_own_records_and_a_rerun_recreates_them(): void
    {
        $theirs = app(FirstAidService::class)->create($this->admin, ['site_id' => $this->site->id, 'treated_person_type' => 'staff',
            'treated_person_id' => $this->firstAider->id, 'treated_person_name' => 'Aroha Ngata', 'treatment_date' => now()->subDays(2)->toISOString(),
            'injury_illness_type' => 'bruise', 'injury_illness_description' => 'Bumped knee on the coffee table', 'treatment_given' => 'Ice pack',
            'treatment_outcome' => 'returned_to_activity', 'first_aider_id' => $this->firstAider->id, 'ambulance_called' => false,
            'incident_reported' => false, 'idempotency_key' => (string) Str::uuid()]);
        $theirRisk = app(HsRiskAssessmentService::class)->create(['assessable_type' => Site::class, 'assessable_id' => $this->site->id,
            'title' => 'Trip hazards in the garden', 'likelihood' => 2, 'consequence' => 2], $this->admin);

        $this->artisan('health-safety:demo')->assertExitCode(0);
        $this->artisan('health-safety:demo', ['--remove' => true])->assertExitCode(0);

        $this->assertSame(6, FirstAidRecord::onlyTrashed()->count());
        $this->assertFalse($theirs->fresh()->trashed());
        $this->assertSame(3, HsRiskAssessment::where('status', 'archived')->count());
        $this->assertSame('draft', $theirRisk->fresh()->status);
        // No archive command exists for these registers: they stay.
        $this->assertSame(3, RestraintEvent::count());
        $this->assertSame(3, SubstanceExposureRecord::count());

        // Removing again is a no-op.
        $this->artisan('health-safety:demo', ['--remove' => true])->assertExitCode(0);
        $this->assertSame(6, FirstAidRecord::onlyTrashed()->count());

        // A later run puts the removed kinds back under fresh keys, without duplicating the rest.
        $this->artisan('health-safety:demo')->assertExitCode(0);
        $this->assertSame(7, FirstAidRecord::count());
        $this->assertTrue(FirstAidRecord::where('creation_key', HealthSafetyDemoData::key('first-aid-1', 2))->exists());
        $this->assertSame(4, HsRiskAssessment::where('status', '!=', 'archived')->count());
        $this->assertSame(3, RestraintEvent::count());
        $this->assertSame(3, SubstanceExposureRecord::count());
    }

    public function test_it_refuses_without_a_usable_admin(): void
    {
        $this->admin->hrEmployeeProfile()->update(['is_active' => false]);
        $this->artisan('health-safety:demo')->assertExitCode(1);
        $this->assertSame(['first_aid' => 0, 'restraint' => 0, 'chemical' => 0, 'risk' => 0], $this->counts());
    }
}
