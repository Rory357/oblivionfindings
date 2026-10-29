<?php

namespace Tests\Feature\HealthSafety;

use App\Console\Commands\HealthSafetyDemoData;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\ChemicalCommandIntent;
use App\Models\ChemicalCommandReceipt;
use App\Models\Client;
use App\Models\FirstAidRecord;
use App\Models\HazardousSubstance;
use App\Models\HsRiskAssessment;
use App\Models\Permission;
use App\Models\RestraintEvent;
use App\Models\Site;
use App\Models\SubstanceExposureRecord;
use App\Models\SubstanceStorageLocation;
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

    private Site $secondSite;

    private Site $fixtureSite;

    private User $admin;

    /** Team lead at Kōwhai House, first-aider flag off: the prepare step should pick them. */
    private User $teamLead;

    /** Already a first aider at Tōtara House before the command runs. */
    private User $existingFirstAider;

    private User $secondSiteWorker;

    private SubstanceStorageLocation $fixtureStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Queue::fake();
        Http::preventStrayRequests();
        Storage::fake('private');

        // Like the .com server: nobody at the real houses is flagged a first aider at Kōwhai House,
        // and no real house has a stored substance.
        $this->site = Site::factory()->create(['name' => 'Kōwhai House', 'is_active' => true]);
        $this->secondSite = Site::factory()->create(['name' => 'Tōtara House', 'is_active' => true]);
        // A leftover test fixture Site: the command must never write there.
        $this->fixtureSite = Site::factory()->create(['name' => 'Playwright RELEASE house', 'is_active' => true]);

        $this->admin = $this->staff('Mere Tawhiri', ['role' => 'admin', 'email' => 'admin@demo.test'], [
            'hazards.view', 'hazards.create', 'hazards.manage', 'hr.wellbeing.view', 'healthSafety.viewAllSites',
            'restraints.view', 'restraints.create', 'restraints.review', 'restraints.manage', 'clients.viewAny',
            'incidents.viewAny', 'incidents.create', 'incidents.update',
        ]);
        $this->staff('Aroha Ngata');
        $this->teamLead = $this->staff('Sione Taufa', ['role' => 'team_lead']);
        $this->staff('Priya Sharma');
        $this->existingFirstAider = $this->staff('Hemi Walker', [], [], true, $this->secondSite);
        $this->secondSiteWorker = $this->staff('Ana Fifita', [], [], false, $this->secondSite);
        $this->staff('Tom Reilly', [], [], false, $this->fixtureSite);

        foreach ([['Wiremu', 'Parata'], ['Grace', 'Liu']] as [$first, $last]) {
            Client::factory()->create(['site_id' => $this->site->id, 'first_name' => $first, 'last_name' => $last, 'preferred_name' => null, 'status' => 'active']);
        }
        Client::factory()->create(['site_id' => $this->fixtureSite->id, 'status' => 'active']);

        $bleach = $this->substance('Bleach (sodium hypochlorite)');
        $this->substance('Oven cleaner');
        // Stored only at the fixture Site, which the command ignores and must leave alone.
        $result = app(ChemicalCommandService::class)->createStorage($bleach, $this->admin, ['idempotency_key' => (string) Str::uuid(),
            'parent_expected_version' => $bleach->fresh()->source_revision, 'site_id' => $this->fixtureSite->id,
            'location_description' => 'Test shelf', 'current_quantity' => '1.00']);
        $this->fixtureStorage = SubstanceStorageLocation::findOrFail($result['source_id']);
    }

    private function staff(string $name, array $attributes = [], array $permissions = [], bool $firstAider = false, ?Site $site = null): User
    {
        $user = User::factory()->create(['name' => $name, 'role' => 'support_worker', 'approved_at' => now(), 'external_clinical_account' => false, ...$attributes]);
        // The profile factory picks a random job title (team lead, coordinator…); pin it so only the intended lead looks like one.
        $role = $user->role === 'team_lead' ? 'team_lead' : 'support_worker';
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => ($site ?? $this->site)->id, 'secondary_site_ids' => [],
            'position_title' => ucwords(str_replace('_', ' ', $role)), 'position_role' => $role,
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

    private function firstAiderFlag(User $user): bool
    {
        return HrEmployeeProfile::where('user_id', $user->id)->sole()->is_first_aider;
    }

    /** @return array<int, int> ids of the real-house storage locations */
    private function houseStorage(bool $withTrashed = false): array
    {
        return ($withTrashed ? SubstanceStorageLocation::withTrashed() : SubstanceStorageLocation::query())
            ->whereIn('site_id', [$this->site->id, $this->secondSite->id])->orderBy('id')->pluck('id')->all();
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return [
            'first_aid' => FirstAidRecord::withTrashed()->count(),
            'restraint' => RestraintEvent::withTrashed()->count(),
            'chemical' => SubstanceExposureRecord::query()->count(),
            'risk' => HsRiskAssessment::withTrashed()->count(),
            'storage' => SubstanceStorageLocation::withTrashed()->count(),
        ];
    }

    public function test_running_twice_prepares_and_creates_each_record_once_and_records_read_as_normal(): void
    {
        $this->artisan('health-safety:demo')->assertExitCode(0);
        $first = $this->counts();
        $this->assertSame(['first_aid' => 6, 'restraint' => 3, 'chemical' => 3, 'risk' => 3, 'storage' => 3], $first);

        // Prepare: the team lead at Kōwhai House is flagged; Tōtara House already had a first aider.
        $this->assertTrue($this->firstAiderFlag($this->teamLead));
        $this->assertTrue($this->firstAiderFlag($this->existingFirstAider));
        $this->assertFalse($this->firstAiderFlag($this->secondSiteWorker));
        $this->assertSame([(int) $this->teamLead->hrEmployeeProfile->id], AppSetting::where('key', HealthSafetyDemoData::FIRST_AIDERS_KEY)->sole()->value);
        // Prepare: one storage location at each real house, each under its fixed command key.
        $this->assertCount(2, $this->houseStorage());
        $this->assertSame(2, ChemicalCommandReceipt::whereIn('idempotency_key', [HealthSafetyDemoData::key('storage-1'), HealthSafetyDemoData::key('storage-2')])->count());
        $this->assertSame('Locked cleaning cupboard, laundry', SubstanceStorageLocation::where('site_id', $this->site->id)->sole()->location_description);

        $receipts = ChemicalCommandReceipt::count();
        $this->artisan('health-safety:demo')->expectsOutputToContain('storage-1: already there (command replayed)')->assertExitCode(0);
        $this->assertSame($first, $this->counts());
        $this->assertSame($receipts, ChemicalCommandReceipt::count());
        $this->assertSame([(int) $this->teamLead->hrEmployeeProfile->id], AppSetting::where('key', HealthSafetyDemoData::FIRST_AIDERS_KEY)->sole()->value);

        // Found by key only; never at the leftover fixture Site.
        foreach (range(1, 6) as $n) {
            $row = FirstAidRecord::where('creation_key', HealthSafetyDemoData::key('first-aid-'.$n))->sole();
            $this->assertContains((int) $row->site_id, [$this->site->id, $this->secondSite->id]);
            $this->assertContains((int) $row->first_aider_id, [$this->teamLead->id, $this->existingFirstAider->id]);
            $this->assertTrue($row->treatment_date->lt(now()));
            $proof = app(FirstAidService::class)->confirmationForViewer($this->admin, ['id' => (int) $row->id, 'lock_version' => 1, 'idempotency_key' => $row->creation_key]);
            $this->assertSame('create', $proof['command'] ?? null);
        }
        $this->assertSame(3, RestraintEvent::whereIn('creation_key', array_map(fn ($n) => HealthSafetyDemoData::key('restraint-'.$n), [1, 2, 3]))->where('site_id', $this->site->id)->count());
        $this->assertSame(3, ChemicalCommandIntent::whereIn('idempotency_key', array_map(fn ($n) => HealthSafetyDemoData::key('chemical-exposure-'.$n), [1, 2, 3]))->count());
        $this->assertSame(0, FirstAidRecord::where('site_id', $this->fixtureSite->id)->count() + RestraintEvent::where('site_id', $this->fixtureSite->id)->count()
            + SubstanceExposureRecord::where('site_id', $this->fixtureSite->id)->count());

        // Chemical exposures carry full accepted provenance (not "Needs fixing").
        foreach (SubstanceExposureRecord::all() as $exposure) {
            $proof = app(ChemicalExposureCommandAccess::class)->withCurrentViewer($this->admin,
                fn ($current, $reads, $sites) => app(ChemicalExposureSourceService::class)->sourceProof($exposure, $current, $reads, $sites));
            $this->assertSame('recorded', $proof['attribution_state']);
        }
        $exposure = SubstanceExposureRecord::where('exposure_type', 'eye_contact')->sole();
        $this->assertStringContainsString('Bleach', (string) $exposure->circumstances);

        // Two approved, one draft; all attached to a real Site.
        $this->assertSame(['active', 'active', 'draft'], HsRiskAssessment::orderBy('id')->pluck('status')->all());
        $this->assertSame([Site::class], HsRiskAssessment::distinct()->pluck('assessable_type')->all());
        $this->assertCount(3, AppSetting::where('key', HealthSafetyDemoData::REGISTRY_KEY)->sole()->value);

        // Nothing visible says it is example data.
        foreach ([FirstAidRecord::pluck('injury_illness_description'), RestraintEvent::pluck('restraint_description'),
            SubstanceExposureRecord::pluck('circumstances'), HsRiskAssessment::pluck('title'), SubstanceStorageLocation::pluck('location_description')] as $texts) {
            foreach ($texts as $text) {
                $this->assertStringNotContainsStringIgnoringCase('demo', (string) $text);
            }
        }
    }

    public function test_dry_run_writes_nothing(): void
    {
        $audits = AuditLog::count();
        $before = $this->counts();
        $this->artisan('health-safety:demo', ['--dry-run' => true])
            ->expectsOutputToContain('would mark Sione Taufa')
            ->assertExitCode(0);
        $this->assertSame($before, $this->counts());
        $this->assertSame($audits, AuditLog::count());
        $this->assertFalse($this->firstAiderFlag($this->teamLead));
        $this->assertSame(0, AppSetting::where('key', 'like', 'health_safety.demo_data.%')->count());

        $this->artisan('health-safety:demo')->assertExitCode(0);
        $before = $this->counts();
        $audits = AuditLog::count();
        $this->artisan('health-safety:demo', ['--remove' => true, '--dry-run' => true])->assertExitCode(0);
        $this->assertSame(0, FirstAidRecord::onlyTrashed()->count());
        $this->assertSame(0, SubstanceStorageLocation::onlyTrashed()->count());
        $this->assertSame(0, HsRiskAssessment::where('status', 'archived')->count());
        $this->assertTrue($this->firstAiderFlag($this->teamLead));
        $this->assertSame($before, $this->counts());
        $this->assertSame($audits, AuditLog::count());
    }

    public function test_remove_undoes_only_its_own_changes_and_a_rerun_recreates_them(): void
    {
        $theirs = app(FirstAidService::class)->create($this->admin, ['site_id' => $this->secondSite->id, 'treated_person_type' => 'staff',
            'treated_person_id' => $this->secondSiteWorker->id, 'treated_person_name' => 'Ana Fifita', 'treatment_date' => now()->subDays(2)->toISOString(),
            'injury_illness_type' => 'bruise', 'injury_illness_description' => 'Bumped knee on the coffee table', 'treatment_given' => 'Ice pack',
            'treatment_outcome' => 'returned_to_activity', 'first_aider_id' => $this->existingFirstAider->id, 'ambulance_called' => false,
            'incident_reported' => false, 'idempotency_key' => (string) Str::uuid()]);
        $theirRisk = app(HsRiskAssessmentService::class)->create(['assessable_type' => Site::class, 'assessable_id' => $this->site->id,
            'title' => 'Trip hazards in the garden', 'likelihood' => 2, 'consequence' => 2], $this->admin);

        $this->artisan('health-safety:demo')->assertExitCode(0);
        $ourStorage = $this->houseStorage();
        $this->artisan('health-safety:demo', ['--remove' => true])->assertExitCode(0);

        $this->assertSame(6, FirstAidRecord::onlyTrashed()->count());
        $this->assertFalse($theirs->fresh()->trashed());
        $this->assertSame(3, HsRiskAssessment::where('status', 'archived')->count());
        $this->assertSame('draft', $theirRisk->fresh()->status);
        // Only the flag this command turned on goes back off.
        $this->assertFalse($this->firstAiderFlag($this->teamLead));
        $this->assertTrue($this->firstAiderFlag($this->existingFirstAider));
        $this->assertSame([], AppSetting::where('key', HealthSafetyDemoData::FIRST_AIDERS_KEY)->sole()->value);
        // Our storage locations are archived through the chemical command; the fixture Site's is untouched.
        $this->assertSame($ourStorage, SubstanceStorageLocation::onlyTrashed()->orderBy('id')->pluck('id')->all());
        $this->assertFalse($this->fixtureStorage->fresh()->trashed());
        // No archive command exists for these registers: they stay.
        $this->assertSame(3, RestraintEvent::count());
        $this->assertSame(3, SubstanceExposureRecord::count());

        // Removing again is a no-op.
        $this->artisan('health-safety:demo', ['--remove' => true])->assertExitCode(0);
        $this->assertSame(6, FirstAidRecord::onlyTrashed()->count());
        $this->assertSame(2, SubstanceStorageLocation::onlyTrashed()->count());

        // A later run prepares again and puts the removed kinds back under fresh keys, without duplicating the rest.
        $this->artisan('health-safety:demo')->assertExitCode(0);
        $this->assertTrue($this->firstAiderFlag($this->teamLead));
        $this->assertCount(2, $this->houseStorage());
        $this->assertTrue(ChemicalCommandReceipt::where('idempotency_key', HealthSafetyDemoData::key('storage-1', 2))->exists());
        $this->assertSame(7, FirstAidRecord::count());
        $this->assertTrue(FirstAidRecord::where('creation_key', HealthSafetyDemoData::key('first-aid-1', 2))->exists());
        $this->assertSame(4, HsRiskAssessment::where('status', '!=', 'archived')->count());
        $this->assertSame(3, RestraintEvent::count());
        $this->assertSame(3, SubstanceExposureRecord::count());
    }

    public function test_it_refuses_without_a_usable_admin(): void
    {
        $this->admin->hrEmployeeProfile()->update(['is_active' => false]);
        $before = $this->counts();
        $this->artisan('health-safety:demo')->assertExitCode(1);
        $this->assertSame($before, $this->counts());
        $this->assertFalse($this->firstAiderFlag($this->teamLead));
    }
}
