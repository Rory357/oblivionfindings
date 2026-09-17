<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteVendor;
use App\Models\User;
use App\Models\VendorAgreement;
use App\Models\VendorAgreementFile;
use App\Services\Files\MalwareScanDisposition;
use App\Services\Files\MalwareScanResult;
use App\Services\Files\MalwareScanner;
use App\Services\Sites\VendorAgreements;
use Database\Seeders\RbacSeeder;
use Database\Seeders\VendorVaultPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
    $this->seed([RbacSeeder::class, VendorVaultPermissionsSeeder::class]);
    $this->commercialSite = Site::factory()->create(['type' => 'house', 'is_active' => true]);
    $this->otherCommercialSite = Site::factory()->create(['type' => 'house', 'is_active' => true]);
    $this->commercialActor = commercialActor('finance', $this->commercialSite);
    $this->commercialVendor = SiteVendor::create(['site_id' => $this->commercialSite->id, 'service_type' => 'software',
        'company_name' => 'Synthetic vendor', 'preferred_contact_method' => 'email', 'is_active' => true]);
});

function commercialActor(string $role, Site $site): User {
    $actor = User::factory()->create(['role' => $role, 'approved_at' => now()]);
    $actor->roles()->sync([Role::firstOrCreate(['name' => $role], ['label' => $role, 'level' => 40, 'type' => 'system'])->id]);
    ensureCanonicalHrStaffProfile($actor, $site);
    return $actor;
}
function commercialData(User $owner, array $replace = []): array {
    return array_replace(['title' => 'Restricted renewal contract', 'kind' => 'licence', 'owner_user_id' => $owner->id,
        'visibility' => 'site', 'notice_days' => 30, 'renews_on' => today()->addDays(10)->toDateString(),
        'starts_on' => today()->subYear()->toDateString(), 'currency' => 'NZD', 'amount' => '1200.00',
        'terms' => 'Commercial test terms', 'evidence' => 'Synthetic approved agreement reference'], $replace);
}
function commercialAgreement($test, array $replace = []): VendorAgreement {
    return app(VendorAgreements::class)->save($test->commercialActor, $test->commercialVendor, commercialData($test->commercialActor, $replace));
}

test('only the explicit Finance and Management roles can read commercial data even with unrelated broad grants', function () {
    $agreement = commercialAgreement($this);
    foreach (['finance', 'ceo', 'coo', 'cfo', 'provider_manager', 'manager', 'coordinator', 'team_lead', 'admin'] as $role) {
        $actor = commercialActor($role, $this->commercialSite);
        $this->seed(VendorVaultPermissionsSeeder::class);
        $this->actingAs($actor->fresh())->getJson('/vendor-agreements/'.$agreement->id.'/files')->assertOk();
    }
    foreach (['auditor', 'it_manager', 'facilities_manager', 'roadmap_manager', 'support_worker', 'house_manager', 'site_manager'] as $role) {
        $actor = commercialActor($role, $this->commercialSite);
        $actor->permissionOverrides()->attach(Permission::whereIn('key', ['vendors.contracts.view', 'vendors.contracts.manage', 'finance.ap.view'])->pluck('id'), ['allowed' => true]);
        $this->actingAs($actor->fresh())->getJson('/vendor-agreements/'.$agreement->id.'/files')->assertNotFound();
        $this->actingAs($actor->fresh())->patchJson('/vendor-agreements/'.$agreement->id, commercialData($this->commercialActor, ['lock_version' => 1]))->assertNotFound();
    }
});

test('approved site leadership roles retain site and action boundaries without gaining vault permissions', function (string $roleName) {
    $agreement = commercialAgreement($this);
    $actor = commercialActor($roleName, $this->commercialSite);
    $role = Role::where('name', $roleName)->firstOrFail();
    $otherPermissions = fn () => $role->permissions()->whereNotIn('key', ['vendors.contracts.view', 'vendors.contracts.manage'])->orderBy('key')->pluck('key')->all();
    $before = $otherPermissions();
    $this->seed(VendorVaultPermissionsSeeder::class);
    expect($otherPermissions())->toBe($before);

    $actor = $actor->fresh();
    $navigation = collect(App\Domain\It\ItModuleNavigation::forUser($actor))->pluck('items')->flatten(1);
    expect($navigation->where('label', 'Vendors & Credentials'))->toHaveCount(1);
    if (! $actor->canDo('vendors.view') && ! $actor->canDo('credentials.view')) {
        expect($navigation->where('label', 'Vendors & Credentials')->first()['href'])->toBe('/vendors');
        $this->actingAs($actor)->get('/vendors')->assertRedirect('/vendors/renewals');
        $this->get('/vendors/renewals')->assertOk()->assertInertia(fn ($page) => $page
            ->where('auth.can.vendors.view', false)->where('auth.can.credentials.view', false)->where('auth.can.vendors.contracts_view', true));
    }

    // An existing all-site entitlement is independently revocable, including for administrators.
    $actor->permissionOverrides()->attach(Permission::where('key', 'sites.viewAll')->firstOrFail(), ['allowed' => false]);
    $actor = $actor->fresh();
    $this->actingAs($actor)->getJson('/vendor-agreements/'.$agreement->id.'/files')->assertOk();
    $this->patchJson('/vendor-agreements/'.$agreement->id, commercialData($this->commercialActor, ['lock_version' => 1, 'title' => 'Approved site contract update']))->assertOk();

    Storage::fake('private');
    $content = "%PDF-1.4\nSynthetic approved-site contract\n%%EOF";
    $file = VendorAgreementFile::create(['agreement_id' => $agreement->id, 'series_id' => (string) str()->uuid(), 'version' => 1,
        'name' => 'approved-site.pdf', 'path' => 'vendor_agreements/approved-site.pdf', 'mime' => 'application/pdf',
        'size' => strlen($content), 'sha256' => hash('sha256', $content), 'state' => 'ready',
        'uploaded_by_user_id' => $this->commercialActor->id, 'created_at' => now()]);
    Storage::disk('private')->put($file->path, $content);
    $this->get('/vendor-agreements/'.$agreement->id.'/files/'.$file->id.'/open')->assertOk();

    $otherOwner = commercialActor('finance', $this->otherCommercialSite);
    $otherVendor = SiteVendor::create(['site_id' => $this->otherCommercialSite->id, 'service_type' => 'software',
        'company_name' => 'Unapproved-site vendor', 'preferred_contact_method' => 'email', 'is_active' => true]);
    $otherAgreement = app(VendorAgreements::class)->save($otherOwner, $otherVendor, commercialData($otherOwner));
    $this->getJson('/vendor-agreements/'.$otherAgreement->id.'/files')->assertNotFound();
    $this->patchJson('/vendor-agreements/'.$otherAgreement->id, commercialData($otherOwner, ['lock_version' => 1]))->assertNotFound();
    $this->get('/vendor-agreements/'.$otherAgreement->id.'/files/'.$file->id.'/open')->assertNotFound();

    $actor->permissionOverrides()->attach(Permission::where('key', 'vendors.contracts.manage')->firstOrFail(), ['allowed' => false]);
    $this->actingAs($actor->fresh())->getJson('/vendor-agreements/'.$agreement->id.'/files')->assertOk();
    $this->patchJson('/vendor-agreements/'.$agreement->id, commercialData($this->commercialActor, ['lock_version' => 2]))->assertNotFound();
    $actor->permissionOverrides()->attach(Permission::where('key', 'vendors.contracts.view')->firstOrFail(), ['allowed' => false]);
    $this->actingAs($actor->fresh())->get('/vendor-agreements/'.$agreement->id.'/files/'.$file->id.'/open')->assertNotFound();
})->with(['manager', 'coordinator', 'team_lead', 'admin']);

test('commercial view and maintenance stay independent and unapproved sites conceal direct identifiers', function () {
    $agreement = commercialAgreement($this);
    $this->commercialActor->permissionOverrides()->attach(Permission::where('key', 'vendors.contracts.manage')->firstOrFail(), ['allowed' => false]);
    $actor = $this->commercialActor->fresh();
    $this->actingAs($actor)->getJson('/vendor-agreements/'.$agreement->id.'/files')->assertOk();
    $this->actingAs($actor)->patchJson('/vendor-agreements/'.$agreement->id, commercialData($actor, ['lock_version' => 1]))->assertNotFound();
    $other = commercialActor('finance', $this->otherCommercialSite);
    $this->actingAs($other)->getJson('/vendor-agreements/'.$agreement->id.'/files')->assertNotFound();
    $this->actingAs($other)->get('/vendors/renewals')->assertOk()->assertDontSee($agreement->title);
});

test('renewal review date changes and retirement reuse one owner followup and retain evidence', function () {
    $agreement = commercialAgreement($this);
    expect(DB::table('vendor_renewal_followups')->where('agreement_id', $agreement->id)->count())->toBe(1);
    $followup = DB::table('vendor_renewal_followups')->where('agreement_id', $agreement->id)->first();
    expect($followup->status)->toBe('due');
    app(VendorAgreements::class)->due(); app(VendorAgreements::class)->due();
    $this->actingAs($this->commercialActor)->postJson('/vendor-agreements/'.$agreement->id.'/transition', [
        'action' => 'review', 'lock_version' => 1, 'evidence' => 'Owner reviewed the renewal notice'])->assertOk();
    expect(DB::table('vendor_renewal_followups')->where('id', $followup->id)->value('status'))->toBe('reviewed');
    $this->actingAs($this->commercialActor)->postJson('/vendor-agreements/'.$agreement->id.'/transition', [
        'action' => 'renew', 'lock_version' => 2, 'renews_on' => today()->addYear()->toDateString(), 'evidence' => 'New signed evidence reference recorded'])->assertOk();
    expect(DB::table('vendor_renewal_followups')->where('agreement_id', $agreement->id)->count())->toBe(1);
    expect(DB::table('vendor_renewal_followups')->where('id', $followup->id)->value('status'))->toBe('scheduled');
    $this->actingAs($this->commercialActor)->postJson('/vendor-agreements/'.$agreement->id.'/transition', [
        'action' => 'retire', 'lock_version' => 3, 'evidence' => 'Coverage ended and approved for retirement'])->assertOk();
    expect(DB::table('vendor_renewal_followups')->where('id', $followup->id)->value('status'))->toBe('cancelled');
    expect(DB::table('vendor_agreement_events')->where('agreement_id', $agreement->id)->count())->toBe(4);
});

test('stale agreement edits and date-only renewal cannot overwrite evidence', function () {
    $agreement = commercialAgreement($this);
    $this->actingAs($this->commercialActor)->patchJson('/vendor-agreements/'.$agreement->id,
        commercialData($this->commercialActor, ['lock_version' => 1, 'title' => 'Current title']))->assertOk();
    $this->actingAs($this->commercialActor)->patchJson('/vendor-agreements/'.$agreement->id,
        commercialData($this->commercialActor, ['lock_version' => 1, 'title' => 'Stale title']))->assertUnprocessable();
    $this->actingAs($this->commercialActor)->postJson('/vendor-agreements/'.$agreement->id.'/transition',
        ['action' => 'renew', 'renews_on' => today()->addYear()->toDateString(), 'lock_version' => 2])->assertUnprocessable();
    expect($agreement->fresh()->title)->toBe('Current title');
});

test('protected files open as originals and replacement versions retain history with direct object denial', function () {
    Storage::fake('private');
    $this->mock(MalwareScanner::class)->shouldReceive('scanPath')->andReturn(new MalwareScanResult(MalwareScanDisposition::Clean, 'synthetic'));
    $agreement = commercialAgreement($this);
    $upload = fn () => UploadedFile::fake()->createWithContent('commercial.pdf', "%PDF-1.4\nSynthetic commercial document\n%%EOF");
    $this->actingAs($this->commercialActor)->post('/vendor-agreements/'.$agreement->id.'/files',
        ['file' => $upload(), 'lock_version' => 1], ['Accept' => 'application/json'])->assertOk();
    $first = VendorAgreementFile::firstOrFail();
    $this->actingAs($this->commercialActor)->get('/vendor-agreements/'.$agreement->id.'/files/'.$first->id.'/open')->assertOk()->assertDownload('commercial.pdf');
    $this->actingAs($this->commercialActor)->post('/vendor-agreements/'.$agreement->id.'/files',
        ['file' => $upload(), 'replace_file_id' => $first->id, 'lock_version' => 2], ['Accept' => 'application/json'])->assertOk();
    expect(VendorAgreementFile::where('series_id', $first->series_id)->count())->toBe(2);
    $denied = commercialActor('auditor', $this->commercialSite);
    $this->actingAs($denied)->getJson('/vendor-agreements/'.$agreement->id.'/files')->assertNotFound()->assertDontSee('commercial.pdf');
    $this->actingAs($denied)->get('/vendor-agreements/'.$agreement->id.'/files/'.$first->id.'/open')->assertNotFound();
    Storage::disk('private')->assertExists($first->path);
});

test('commercial readers cannot discover restricted asset links or clear them by editing other fields', function () {
    $asset = \App\Models\Asset::factory()->create(['site_id' => $this->otherCommercialSite->id]);
    $agreement = commercialAgreement($this);
    $agreement->update(['asset_id' => $asset->id]);
    $projection = app(\App\Http\Controllers\Sites\VendorAgreementController::class)->payload($agreement->fresh(), $this->commercialActor);
    expect($projection['asset_id'])->toBeNull();
    $this->actingAs($this->commercialActor)->patchJson('/vendor-agreements/'.$agreement->id,
        commercialData($this->commercialActor, ['lock_version' => 1, 'title' => 'Edited accessible fields', 'asset_id' => null]))->assertOk();
    expect($agreement->fresh()->asset_id)->toBe($asset->id);
});

test('agreement creation retries reuse the original agreement and followup', function () {
    $data = commercialData($this->commercialActor, ['creation_key' => (string) str()->uuid()]);
    $url = '/vendors/'.$this->commercialVendor->id.'/agreements';
    $first = $this->actingAs($this->commercialActor)->postJson($url, $data)->assertCreated()->json('id');
    $this->actingAs($this->commercialActor)->postJson($url, $data)->assertCreated()->assertJsonPath('id', $first);
    $this->actingAs($this->commercialActor)->postJson($url, [...$data, 'title' => 'Changed retry'])->assertStatus(409);
    expect(VendorAgreement::where('vendor_id', $this->commercialVendor->id)->count())->toBe(1)
        ->and(DB::table('vendor_renewal_followups')->where('agreement_id', $first)->count())->toBe(1);
});

test('sharing requires both canonical vendor and agreement visibility and never grants remote maintenance', function () {
    $agreement = commercialAgreement($this);
    $other = commercialActor('finance', $this->otherCommercialSite);
    $this->commercialVendor->update(['visibility' => 'all_approved_sites']);
    $this->actingAs($other)->getJson('/vendor-agreements/'.$agreement->id.'/files')->assertNotFound();
    $agreement->update(['visibility' => 'all_approved_sites']);
    $this->actingAs($other)->getJson('/vendor-agreements/'.$agreement->id.'/files')->assertOk();
    $this->actingAs($other)->patchJson('/vendor-agreements/'.$agreement->id, commercialData($this->commercialActor, ['lock_version' => 1]))->assertNotFound();
    $this->commercialVendor->update(['visibility' => 'site']);
    $this->actingAs($other)->getJson('/vendor-agreements/'.$agreement->id.'/files')->assertNotFound();
});

test('renewal catchup uses the worker date and task access follows owner revocation and retired services', function () {
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-09-13 12:30:00', 'UTC'));
    config(['app.worker_timezone' => 'Pacific/Auckland']);
    $agreement = commercialAgreement($this, ['renews_on' => '2026-09-14', 'notice_days' => 0]);
    expect(DB::table('vendor_renewal_followups')->where('agreement_id', $agreement->id)->value('status'))->toBe('due');
    $provider = app(\App\Services\Tasks\Providers\VendorRenewalTaskProvider::class);
    expect($provider->authorizedTasks($this->commercialActor))->toHaveCount(1);
    $this->commercialActor->permissionOverrides()->attach(Permission::where('key', 'vendors.contracts.view')->firstOrFail(), ['allowed' => false]);
    $manager = commercialActor('cfo', $this->commercialSite);
    expect($provider->authorizedTasks($this->commercialActor->fresh()))->toBe([]);
    $task = $provider->authorizedTasks($manager)[0];
    expect($task->assignee)->toBeNull()->and($task->status)->toBe('owner_unavailable');
    $service = \App\Models\ItService::create(['key' => 'retirement-fixture', 'name' => 'Synthetic service', 'status' => 'retired', 'is_active' => false, 'criticality' => 'low']);
    $this->commercialVendor->update(['related_records' => [['type' => 'service', 'id' => $service->id]]]);
    // Projection stops offering work immediately, before the next scheduled sweep.
    expect($provider->authorizedTasks($manager))->toBe([]);
    app(VendorAgreements::class)->due(); app(VendorAgreements::class)->due();
    expect(DB::table('vendor_renewal_followups')->where('agreement_id', $agreement->id)->count())->toBe(1)
        ->and(DB::table('vendor_renewal_followups')->where('agreement_id', $agreement->id)->value('status'))->toBe('cancelled');
    $this->travelBack();
});

test('Word originals have retained private versions and reject corrupted stored bytes', function () {
    Storage::fake('private');
    $this->mock(MalwareScanner::class)->shouldReceive('scanPath')->andReturn(new MalwareScanResult(MalwareScanDisposition::Clean, 'synthetic'));
    $agreement = commercialAgreement($this);
    $path = tempnam(sys_get_temp_dir(), 'vendor-word-');
    try {
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Synthetic agreement</w:t></w:r></w:p></w:body></w:document>');
        $zip->close();
        $this->actingAs($this->commercialActor)->post('/vendor-agreements/'.$agreement->id.'/files',
            ['file' => new UploadedFile($path, 'commercial.docx', null, null, true), 'lock_version' => 1], ['Accept' => 'application/json'])->assertOk();
        $file = VendorAgreementFile::firstOrFail();
        $this->actingAs($this->commercialActor)->get('/vendor-agreements/'.$agreement->id.'/files/'.$file->id.'/open')->assertDownload('commercial.docx');
        Storage::disk('private')->put($file->path, 'corrupted synthetic bytes');
        $this->actingAs($this->commercialActor)->get('/vendor-agreements/'.$agreement->id.'/files/'.$file->id.'/open')->assertNotFound();
    } finally { if (is_file($path)) unlink($path); }
});

test('unavailable malware scanning never publishes a file', function () {
    Storage::fake('private');
    $this->mock(MalwareScanner::class)->shouldReceive('scanPath')->andReturn(new MalwareScanResult(MalwareScanDisposition::Unavailable, 'synthetic', 'unavailable'));
    $agreement = commercialAgreement($this);
    $this->actingAs($this->commercialActor)->post('/vendor-agreements/'.$agreement->id.'/files',
        ['file' => UploadedFile::fake()->createWithContent('commercial.pdf', "%PDF-1.4\nSynthetic\n%%EOF"), 'lock_version' => 1], ['Accept' => 'application/json'])->assertUnprocessable();
    $file = VendorAgreementFile::firstOrFail();
    expect($file->state)->toBe('scan_unavailable');
    $this->actingAs($this->commercialActor)->get('/vendor-agreements/'.$agreement->id.'/files/'.$file->id.'/open')->assertNotFound();
    expect($agreement->fresh()->lock_version)->toBe(1);
});
