<?php

namespace Tests\Feature\Sites;

use App\Models\EmergencyDrill;
use App\Models\FirstAidRecord;
use App\Models\HsRiskAssessment;
use App\Models\PpeInventory;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\SiteCredential;
use App\Models\SiteDocument;
use App\Models\SiteHazard;
use App\Models\SiteInspectionSchedule;
use App\Models\SiteVendor;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SiteProfilePayloadTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->admin = User::factory()->create([
            'role' => 'admin',
            'approved_at' => now(),
        ]);
        $this->admin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
        $this->site = Site::factory()->create([
            'type' => 'house',
            'brand_colour' => '#5E35B1',
        ]);
    }

    public function test_initial_page_contains_only_the_profile_shell_and_branded_hero(): void
    {
        $this->actingAs($this->admin)
            ->get(route('sites.show', $this->site))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('sites/show')
                ->where('site.brand_colour', '#5E35B1')
                ->where('hero.brand_colour', '#5E35B1')
                ->has('permissions')
                ->has('attention.summary')
                ->has('overview')
                ->has('readiness')
                ->has('uiPreferences.pinned_tabs')
                ->missing('peopleData')
                ->missing('safetyData')
                ->missing('operationsData')
                ->missing('adminData')
                ->missing('clients')
                ->missing('assets')
                ->missing('vendors')
                ->missing('credentials')
                ->missing('checklistsData')
            );
    }

    public function test_partial_request_materializes_only_the_requested_optional_group(): void
    {
        $this->actingAs($this->admin)
            ->get(route('sites.show', $this->site), $this->inertiaPartialHeaders('sites/show', 'safetyData'))
            ->assertOk()
            ->assertHeader('X-Inertia', 'true')
            ->assertJsonPath('component', 'sites/show')
            ->assertJsonStructure(['props' => ['safetyData']])
            ->assertJsonMissingPath('props.peopleData')
            ->assertJsonMissingPath('props.operationsData')
            ->assertJsonMissingPath('props.adminData');
    }

    public function test_admin_payload_never_serializes_credential_secret_material(): void
    {
        SiteCredential::query()->create([
            'site_id' => $this->site->id,
            'label' => 'Alarm panel',
            'credential_type' => 'password',
            'encrypted_value' => 'SITE_PROFILE_SECRET_SENTINEL',
            'iv' => 'SITE_PROFILE_IV_SENTINEL',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('sites.show', $this->site), $this->inertiaPartialHeaders('sites/show', 'adminData'))
            ->assertOk()
            ->assertJsonPath('props.adminData.vendors_credentials.summary.credentials', 1);

        $serialized = $response->getContent();
        $this->assertStringNotContainsString('SITE_PROFILE_SECRET_SENTINEL', $serialized);
        $this->assertStringNotContainsString('SITE_PROFILE_IV_SENTINEL', $serialized);
        $this->assertStringNotContainsString('encrypted_value', $serialized);
        $this->assertStringNotContainsString('totp_secret', $serialized);
    }

    public function test_admin_payload_is_bounded_and_links_to_canonical_owners(): void
    {
        foreach (range(1, 14) as $index) {
            SiteDocument::query()->create([
                'site_id' => $this->site->id,
                'uploaded_by_user_id' => $this->admin->id,
                'title' => "Admin document {$index}",
                'category' => 'compliance',
                'storage_disk' => 'private',
                'storage_path' => "sites/admin-document-{$index}.pdf",
                'original_name' => "admin-document-{$index}.pdf",
                'mime_type' => 'application/pdf',
                'size_bytes' => 100,
                'expiry_date' => now()->addDays($index),
            ]);

            ServiceContext::factory()->create([
                'site_id' => $this->site->id,
                'name' => "Admin service {$index}",
                'is_active' => $index !== 14,
            ]);
        }

        $vendor = SiteVendor::query()->create([
            'site_id' => $this->site->id,
            'service_type' => 'maintenance',
            'company_name' => 'Canonical Vendor',
            'is_active' => true,
        ]);
        SiteCredential::query()->create([
            'site_id' => $this->site->id,
            'vendor_id' => $vendor->id,
            'label' => 'Canonical Credential',
            'credential_type' => 'password',
            'encrypted_value' => 'NEVER_SERIALIZE_THIS_VALUE',
        ]);

        $this->actingAs($this->admin)
            ->get(route('sites.show', $this->site), $this->inertiaPartialHeaders('sites/show', 'adminData'))
            ->assertOk()
            ->assertJsonCount(12, 'props.adminData.documents.items')
            ->assertJsonPath('props.adminData.documents.summary.total', 14)
            ->assertJsonPath('props.adminData.documents.summary.shown', 12)
            ->assertJsonPath('props.adminData.documents.href', route('sites.documents.index', $this->site))
            ->assertJsonPath('props.adminData.financials.href', route('finance.sites.financial-dashboard', $this->site))
            ->assertJsonPath('props.adminData.financials.house_ledger.href', route('sites.ledger.index', $this->site))
            ->assertJsonPath('props.adminData.vendors_credentials.summary.vendors', 1)
            ->assertJsonPath('props.adminData.vendors_credentials.summary.credentials', 1)
            ->assertJsonPath('props.adminData.vendors_credentials.href', route('sites.vendors.global', ['site_id' => $this->site->id]))
            ->assertJsonCount(12, 'props.adminData.services.items')
            ->assertJsonPath('props.adminData.services.summary.total', 14)
            ->assertJsonPath('props.adminData.services.summary.active', 13)
            ->assertJsonPath('props.adminData.services.href', route('settings.service_contexts'));
    }

    public function test_shell_and_optional_groups_stay_within_explicit_query_ceilings(): void
    {
        foreach (range(1, 3) as $index) {
            SiteHazard::query()->create([
                'site_id' => $this->site->id,
                'reported_by_user_id' => $this->admin->id,
                'hazard_type' => 'other',
                'severity' => 'medium',
                'likelihood' => 'possible',
                'description' => "Profile query hazard {$index}",
                'status' => 'open',
            ]);
            SiteInspectionSchedule::query()->create([
                'site_id' => $this->site->id,
                'inspection_type' => 'house_safety',
                'title' => "Profile inspection {$index}",
                'frequency' => 'monthly',
                'first_due_date' => now()->toDateString(),
                'next_due_date' => now()->addDays($index)->toDateString(),
                'is_active' => true,
            ]);
            SiteDocument::query()->create([
                'site_id' => $this->site->id,
                'uploaded_by_user_id' => $this->admin->id,
                'title' => "Profile document {$index}",
                'category' => 'compliance',
                'storage_disk' => 'private',
                'storage_path' => "sites/profile-document-{$index}.pdf",
                'original_name' => "profile-document-{$index}.pdf",
                'mime_type' => 'application/pdf',
                'size_bytes' => 100,
            ]);
            $vendor = SiteVendor::query()->create([
                'site_id' => $this->site->id,
                'service_type' => 'maintenance',
                'company_name' => "Profile Vendor {$index}",
                'is_active' => true,
            ]);
            SiteCredential::query()->create([
                'site_id' => $this->site->id,
                'vendor_id' => $vendor->id,
                'label' => "Profile credential {$index}",
                'credential_type' => 'password',
                'encrypted_value' => "encrypted-{$index}",
            ]);
        }

        EmergencyDrill::factory()->count(3)->create(['site_id' => $this->site->id]);
        HsRiskAssessment::factory()->count(3)->forSite($this->site->id)->create();
        FirstAidRecord::factory()->count(3)->create(['site_id' => $this->site->id]);
        PpeInventory::factory()->count(3)->create(['site_id' => $this->site->id]);
        ServiceContext::factory()->count(3)->create(['site_id' => $this->site->id]);

        // Isolate the profile query budget from the global sidebar task badge.
        // Production caches that cross-module aggregation for five minutes.
        Cache::put("tasks.nav.{$this->admin->id}", [
            'view' => true,
            'badge' => 0,
        ], now()->addMinutes(5));

        DB::enableQueryLog();
        $this->actingAs($this->admin)
            ->get(route('sites.show', $this->site))
            ->assertOk();
        $shellLog = DB::getQueryLog();
        $shellQueries = count($shellLog);

        DB::flushQueryLog();
        $this->actingAs($this->admin)
            ->get(route('sites.show', $this->site), $this->inertiaPartialHeaders('sites/show', 'safetyData'))
            ->assertOk();
        $safetyQueries = count(DB::getQueryLog());

        DB::flushQueryLog();
        $this->actingAs($this->admin)
            ->get(route('sites.show', $this->site), $this->inertiaPartialHeaders('sites/show', 'adminData'))
            ->assertOk();
        $adminQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $shellTables = collect($shellLog)
            ->map(function (array $entry): string {
                preg_match('/(?:from|join) [`"]?([a-z0-9_]+)/i', $entry['query'], $matches);

                return $matches[1] ?? 'other';
            })
            ->countBy()
            ->sortDesc()
            ->toJson();

        $this->assertLessThanOrEqual(45, $shellQueries, "Site profile shell used {$shellQueries} queries: {$shellTables}");
        $this->assertLessThanOrEqual(21, $safetyQueries, "Safety group used {$safetyQueries} queries.");
        $this->assertLessThanOrEqual(20, $adminQueries, "Admin group used {$adminQueries} queries.");
    }
}
