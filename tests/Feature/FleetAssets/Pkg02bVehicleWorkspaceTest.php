<?php

namespace Tests\Feature\FleetAssets;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\AssetDocument;
use App\Models\AssetDocumentSet;
use App\Models\FleetCatalogueEntry;
use App\Models\FleetServiceCompletion;
use App\Models\FleetServiceSchedule;
use App\Models\FleetVehicleBooking;
use App\Models\FleetVehicleOdometerObservation;
use App\Models\FleetVehicleReminder;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\Files\MalwareScanDisposition;
use App\Services\Files\MalwareScanner;
use App\Services\Files\MalwareScanResult;
use App\Services\Sites\Calendar\Providers\FleetVehicleReminderObligationProvider;
use App\Services\Tasks\TaskAggregator;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * PKG-02B slice (a): vehicle details, private documents, reminders, service
 * schedules and reusable choices behind the Overview and Service & compliance tabs.
 */
class Pkg02bVehicleWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Site $foreignSite;

    private object $scanner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        Storage::fake('private');
        $this->travelTo(Carbon::parse('2026-09-22 09:30:00', 'Pacific/Auckland')->utc());
        $this->site = Site::factory()->create(['name' => 'Kōwhai House']);
        $this->foreignSite = Site::factory()->create(['name' => 'Rimu House']);
        // A controllable scanner: no real scanning binary is configured in tests.
        $this->scanner = new class extends MalwareScanner
        {
            public MalwareScanDisposition $next = MalwareScanDisposition::Clean;

            public int $calls = 0;

            public function scanPath(string $path, array $settings): MalwareScanResult
            {
                $this->calls++;

                return new MalwareScanResult($this->next, 'test-scanner', $this->next === MalwareScanDisposition::Unavailable ? 'scanner_unavailable' : null);
            }
        };
        $this->app->instance(MalwareScanner::class, $this->scanner);
    }

    public function test_workspace_page_presents_scoped_vehicle_data_and_permissions(): void
    {
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage', 'assets.viewAny', 'assets.documents.manage']);
        $viewer = $this->siteUser([$this->site], ['fleet.viewAny']);
        $vehicle = $this->vehicle($this->site);
        $foreign = $this->vehicle($this->foreignSite);

        $this->actingAs($manager)->get("/fleet-assets/vehicles/{$vehicle->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('fleet-assets/vehicles/show')
                ->where('workspace.vehicle.id', $vehicle->id)
                ->has('workspace.compliance', 4)
                // The approved design's order: WoF, Registration, RUC, CoF.
                ->where('workspace.compliance', fn ($rows) => collect($rows)->pluck('label')->all() === ['WoF', 'Registration', 'RUC', 'CoF'])
                ->where('workspace.readiness.status', 'blocked')
                ->where('workspace.can.manage', true)
                ->where('workspace.can.manage_documents', true)
                ->where('sites', fn ($sites) => collect($sites)->pluck('id')->all() === [$this->site->id])
                ->missing('readiness')
                ->etc());
        $this->actingAs($viewer)->get("/fleet-assets/vehicles/{$vehicle->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('workspace.can.manage', false)
                ->where('workspace.can.manage_documents', false)
                ->where('workspace.people', [])
                ->etc());
        $this->actingAs($manager)->get("/fleet-assets/vehicles/{$foreign->id}")->assertNotFound();
    }

    public function test_vehicle_details_need_a_reason_and_the_current_version(): void
    {
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage']);
        $responsible = $this->siteUser([$this->site], []);
        $outsider = $this->siteUser([$this->foreignSite], []);
        $vehicle = $this->vehicle($this->site);
        $details = [
            'profile_version' => 1, 'manufacturer' => 'Toyota', 'model' => 'Hiace', 'serial_number' => 'JTFHV02P200012345',
            'body_type' => 'Passenger van', 'use_purpose' => 'Community transport', 'ownership_arrangement' => 'Owned',
            'fleet_responsible_user_id' => $responsible->id, 'insurance_provider' => 'Example Mutual',
            'insurance_policy_reference' => 'POL-14', 'insurance_expires_at' => '2026-12-31', 'seating_capacity' => 8,
        ];

        $this->actingAs($manager)->put("/fleet-assets/vehicles/{$vehicle->id}", $details)->assertSessionHasErrors('reason');
        $this->actingAs($manager)->put("/fleet-assets/vehicles/{$vehicle->id}", ['reason' => 'Wrong owner'] + ['fleet_responsible_user_id' => $outsider->id] + $details)
            ->assertSessionHasErrors('fleet_responsible_user_id');
        $this->actingAs($manager)->put("/fleet-assets/vehicles/{$vehicle->id}", ['reason' => 'Registration papers checked'] + $details)
            ->assertSessionHasNoErrors();
        $this->actingAs($manager)->put("/fleet-assets/vehicles/{$vehicle->id}", ['reason' => 'Stale'] + $details)
            ->assertStatus(409);

        $vehicle->refresh();
        $this->assertSame(2, $vehicle->vehicle_profile_version);
        $this->assertSame('Community transport', $vehicle->use_purpose);
        $this->assertSame('2026-12-31', $vehicle->insurance_expires_at->toDateString());
        $this->actingAs($manager)->get("/fleet-assets/vehicles/{$vehicle->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('workspace.vehicle.responsible.id', $responsible->id)
                ->where('workspace.vehicle.history.0.reason', 'Registration papers checked')
                ->etc());

        // The workspace saves through fetch: JSON answers carry the new version.
        $this->actingAs($manager)->putJson("/fleet-assets/vehicles/{$vehicle->id}", ['profile_version' => 2, 'reason' => 'Seats counted', 'seating_capacity' => 7])
            ->assertOk()->assertJsonPath('vehicle.profile_version', 3);
        $this->actingAs($manager)->putJson("/fleet-assets/vehicles/{$vehicle->id}", ['profile_version' => 2, 'reason' => 'Stale', 'seating_capacity' => 6])
            ->assertStatus(409)->assertJsonPath('message', "This vehicle's details changed while you were editing. Reload before saving.");
        $this->actingAs($manager)->putJson("/fleet-assets/vehicles/{$vehicle->id}", ['profile_version' => 3, 'seating_capacity' => 6])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->assertSame(7, $vehicle->fresh()->seating_capacity);

        FleetVehicleBooking::query()->create([
            'asset_id' => $vehicle->id, 'user_id' => $manager->id, 'purpose' => 'Active trip',
            'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(), 'status' => 'approved',
        ]);
        $this->actingAs($manager)->put("/fleet-assets/vehicles/{$vehicle->id}", [
            'profile_version' => 3, 'reason' => 'Retire', 'status' => 'retired',
        ])->assertSessionHasErrors(['status' => 'Resolve active bookings and open Maintenance work before retiring this vehicle.']);
    }

    public function test_documents_are_private_and_only_clean_files_become_available(): void
    {
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage', 'assets.viewAny', 'assets.documents.manage']);
        $vehicle = $this->vehicle($this->site);

        $created = $this->actingAs($manager)->post("/fleet-assets/vehicles/{$vehicle->id}/documents", [
            'category' => 'Insurance policy', 'reference' => 'POL-14', 'document_date' => '2026-09-01',
            'expires_on' => '2026-12-31', 'reason' => 'New policy year', 'request_key' => 'docs-1',
            'files' => [UploadedFile::fake()->image('policy page 1.png'), $this->pdf('schedule.pdf')],
        ], ['Accept' => 'application/json'])->assertOk()->json();

        $this->assertCount(2, $created['files']);
        $this->assertSame(['available', 'available'], array_column($created['files'], 'state'));
        $file = AssetDocument::query()->findOrFail($created['files'][0]['id']);
        $this->assertSame('private', $file->storage_disk);
        $this->assertStringNotContainsString('policy', $file->storage_path);
        Storage::disk('private')->assertExists($file->storage_path);

        $download = $this->actingAs($manager)->get("/fleet-assets/vehicles/{$vehicle->id}/documents/{$file->id}/file");
        $download->assertOk();
        $this->assertSame('nosniff', $download->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', (string) $download->headers->get('Cache-Control'));

        // An identical retry returns the same set without storing again.
        $calls = $this->scanner->calls;
        $this->actingAs($manager)->post("/fleet-assets/vehicles/{$vehicle->id}/documents", [
            'category' => 'Insurance policy', 'reference' => 'POL-14', 'document_date' => '2026-09-01',
            'expires_on' => '2026-12-31', 'reason' => 'New policy year', 'request_key' => 'docs-1',
            'files' => [UploadedFile::fake()->image('policy page 1.png'), $this->pdf('schedule.pdf')],
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('set.id', $created['set']['id']);
        $this->assertSame($calls, $this->scanner->calls);

        // With checking unavailable, the upload is kept privately and cannot be opened.
        $this->scanner->next = MalwareScanDisposition::Unavailable;
        $pending = $this->actingAs($manager)->post("/fleet-assets/vehicles/{$vehicle->id}/documents", [
            'category' => 'Warranty', 'document_date' => '2026-09-01', 'reason' => 'Warranty card',
            'request_key' => 'docs-2', 'files' => [$this->pdf('warranty.pdf')],
        ], ['Accept' => 'application/json'])->assertOk()->json('files.0');
        $this->assertSame('scan_unavailable', $pending['state']);
        $this->actingAs($manager)->get("/fleet-assets/vehicles/{$vehicle->id}/documents/{$pending['id']}/file")->assertStatus(409);

        $this->scanner->next = MalwareScanDisposition::Clean;
        $this->actingAs($manager)->postJson("/fleet-assets/vehicles/{$vehicle->id}/document-files/{$pending['id']}/retry")
            ->assertOk()->assertJsonPath('file.state', 'available');

        $this->scanner->next = MalwareScanDisposition::Infected;
        $infected = $this->actingAs($manager)->post("/fleet-assets/vehicles/{$vehicle->id}/documents", [
            'category' => 'Invoice', 'document_date' => '2026-09-01', 'reason' => 'Invoice',
            'request_key' => 'docs-3', 'files' => [$this->pdf('invoice.pdf')],
        ], ['Accept' => 'application/json'])->assertOk()->json('files.0');
        $this->assertSame('quarantined', $infected['state']);
    }

    public function test_forged_oversized_and_empty_files_are_refused_before_anything_is_recorded(): void
    {
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage', 'assets.viewAny', 'assets.documents.manage']);
        $vehicle = $this->vehicle($this->site);
        $base = ['category' => 'Insurance policy', 'document_date' => '2026-09-01', 'reason' => 'Upload'];

        foreach ([
            UploadedFile::fake()->createWithContent('policy.png', '%PDF-1.4 not really an image'),
            UploadedFile::fake()->create('policy.pdf', 11 * 1024, 'application/pdf'),
            UploadedFile::fake()->createWithContent('empty.pdf', ''),
            UploadedFile::fake()->createWithContent('script.pdf', '<script>alert(1)</script>'),
        ] as $index => $file) {
            $this->actingAs($manager)->post("/fleet-assets/vehicles/{$vehicle->id}/documents",
                $base + ['request_key' => "bad-{$index}", 'files' => [$file]], ['Accept' => 'application/json'])
                ->assertUnprocessable()->assertJsonValidationErrors('files.0');
        }
        $this->assertSame(0, AssetDocumentSet::query()->where('asset_id', $vehicle->id)->count());
        $this->assertSame(0, AssetDocument::query()->where('asset_id', $vehicle->id)->count());
    }

    public function test_replacing_files_keeps_the_set_its_renewal_reminder_and_the_previous_files_until_clean(): void
    {
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage', 'assets.viewAny', 'assets.documents.manage']);
        $owner = $this->siteUser([$this->site], []);
        $vehicle = $this->vehicle($this->site);
        $created = $this->actingAs($manager)->post("/fleet-assets/vehicles/{$vehicle->id}/documents", [
            'category' => 'Insurance policy', 'document_date' => '2026-01-01', 'expires_on' => '2026-12-31',
            'reason' => 'Policy', 'request_key' => 'renewal-1', 'files' => [$this->pdf('policy.pdf')],
            'reminder' => ['enabled' => '1', 'remind_local' => '2026-12-01T09:00', 'owner_user_id' => $owner->id],
        ], ['Accept' => 'application/json'])->assertOk()->json();
        $reminder = FleetVehicleReminder::query()->where('source_type', 'document_set')->where('source_id', $created['set']['id'])->sole();
        $this->assertSame('Insurance policy renewal', $reminder->title);

        $this->scanner->next = MalwareScanDisposition::Unavailable;
        $this->actingAs($manager)->post("/fleet-assets/vehicles/{$vehicle->id}/documents/{$created['set']['id']}/revisions", [
            'reason' => 'Renewed policy', 'expected_version' => 1, 'request_key' => 'replace-1',
            'files' => [$this->pdf('policy-2027.pdf')],
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('set.current_revision', 1);
        $this->actingAs($manager)->post("/fleet-assets/vehicles/{$vehicle->id}/documents/{$created['set']['id']}/revisions", [
            'reason' => 'Stale', 'expected_version' => 1, 'request_key' => 'replace-2', 'files' => [$this->pdf('x.pdf')],
        ], ['Accept' => 'application/json'])->assertStatus(409);

        $pendingId = AssetDocument::query()->where('document_set_id', $created['set']['id'])->where('revision', 2)->value('id');
        $this->scanner->next = MalwareScanDisposition::Clean;
        $this->actingAs($manager)->postJson("/fleet-assets/vehicles/{$vehicle->id}/document-files/{$pendingId}/retry")->assertOk();
        $this->assertSame(2, AssetDocumentSet::query()->findOrFail($created['set']['id'])->current_revision);

        // Moving the expiry moves the same reminder; the history is retained.
        $this->actingAs($manager)->putJson("/fleet-assets/vehicles/{$vehicle->id}/documents/{$created['set']['id']}", [
            'category' => 'Insurance policy', 'document_date' => '2027-01-01', 'expires_on' => '2027-12-31',
            'reason' => 'Next policy year', 'expected_version' => 2, 'request_key' => 'edit-1',
            'reminder' => ['enabled' => true, 'remind_local' => '2027-12-01T09:00', 'owner_user_id' => $owner->id],
        ])->assertOk();
        $this->assertSame(1, FleetVehicleReminder::query()->where('source_type', 'document_set')->count());
        $this->assertSame('2027-11-30T20:00:00+00:00', $reminder->fresh()->due_at->toIso8601String());
        $this->actingAs($manager)->putJson("/fleet-assets/vehicles/{$vehicle->id}/documents/{$created['set']['id']}", [
            'category' => 'Insurance policy', 'document_date' => '2027-01-01', 'expires_on' => '2027-06-30',
            'reason' => 'Reminder after expiry', 'expected_version' => 3, 'request_key' => 'edit-2',
            'reminder' => ['enabled' => true, 'remind_local' => '2027-12-01T09:00', 'owner_user_id' => $owner->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('reminder.remind_local');
        $outsider = $this->siteUser([$this->foreignSite], []);
        $this->actingAs($manager)->putJson("/fleet-assets/vehicles/{$vehicle->id}/documents/{$created['set']['id']}", [
            'category' => 'Insurance policy', 'document_date' => '2027-01-01', 'expires_on' => '2027-06-30',
            'reason' => 'Owner elsewhere', 'expected_version' => 3, 'request_key' => 'edit-3',
            'reminder' => ['enabled' => true, 'remind_local' => '2027-06-01T09:00', 'owner_user_id' => $outsider->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('reminder.owner_user_id');
    }

    public function test_documents_need_document_authority_and_conceal_other_vehicles_files(): void
    {
        $fleetOnly = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage', 'assets.viewAny']);
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage', 'assets.viewAny', 'assets.documents.manage']);
        $vehicle = $this->vehicle($this->site);
        $other = $this->vehicle($this->site);
        $foreign = $this->vehicle($this->foreignSite);
        $payload = ['category' => 'Warranty', 'document_date' => '2026-09-01', 'reason' => 'Warranty', 'request_key' => 'w-1', 'files' => [$this->pdf('w.pdf')]];

        $this->actingAs($fleetOnly)->post("/fleet-assets/vehicles/{$vehicle->id}/documents", $payload, ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAs($manager)->post("/fleet-assets/vehicles/{$foreign->id}/documents", $payload, ['Accept' => 'application/json'])->assertNotFound();
        $fileId = $this->actingAs($manager)->post("/fleet-assets/vehicles/{$other->id}/documents", $payload, ['Accept' => 'application/json'])
            ->assertOk()->json('files.0.id');
        $this->actingAs($manager)->get("/fleet-assets/vehicles/{$vehicle->id}/documents/{$fileId}/file")->assertNotFound();
        $this->actingAs($manager)->postJson("/fleet-assets/vehicles/{$vehicle->id}/document-files/{$fileId}/archive", ['reason' => 'x', 'request_key' => 'a-1'])
            ->assertNotFound();
    }

    public function test_reminders_keep_their_identity_and_completing_one_never_completes_its_obligation(): void
    {
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage']);
        $owner = $this->siteUser([$this->site], []);
        $outsider = $this->siteUser([$this->foreignSite], []);
        $vehicle = $this->vehicle($this->site);
        $schedule = FleetServiceSchedule::query()->create(['asset_id' => $vehicle->id, 'name' => 'Routine service',
            'interval_km' => 10000, 'next_due_at' => '2026-10-01', 'is_active' => true]);
        $url = "/fleet-assets/vehicles/{$vehicle->id}/reminders";
        $base = ['title' => 'Service booking follow-up', 'action_text' => 'Call the workshop to book.',
            'source_type' => 'service_schedule', 'source_id' => $schedule->id, 'owner_user_id' => $owner->id];

        $this->actingAs($manager)->postJson($url, $base + ['remind_local' => '2026-09-22T08:00', 'request_key' => 'r-past'])
            ->assertUnprocessable()->assertJsonValidationErrors('remind_local');
        $this->actingAs($manager)->postJson($url, ['owner_user_id' => $outsider->id] + $base + ['remind_local' => '2026-09-23T09:00', 'request_key' => 'r-out'])
            ->assertUnprocessable()->assertJsonValidationErrors('owner_user_id');
        $oneOff = $this->actingAs($manager)->postJson($url, $base + ['remind_local' => '2026-09-23T09:00', 'request_key' => 'r-1'])
            ->assertOk()->json('reminder');
        $repeating = $this->actingAs($manager)->postJson($url, $base + ['remind_local' => '2026-01-31T09:00', 'repeat_months' => 1, 'request_key' => 'r-2'])
            ->assertUnprocessable();
        $repeating = $this->actingAs($manager)->postJson($url, $base + ['remind_local' => '2026-10-31T09:00', 'repeat_months' => 1, 'request_key' => 'r-2'])
            ->assertOk()->json('reminder');

        $this->actingAs($manager)->postJson("{$url}/{$oneOff['id']}/complete", ['note' => '', 'expected_version' => 1, 'request_key' => 'c-0'])
            ->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->actingAs($manager)->postJson("{$url}/{$oneOff['id']}/complete", ['note' => 'Booked for 1 Oct.', 'expected_version' => 1, 'request_key' => 'c-1'])
            ->assertOk()->assertJsonPath('reminder.state', 'completed');
        $this->actingAs($manager)->postJson("{$url}/{$oneOff['id']}/complete", ['note' => 'Booked for 1 Oct.', 'expected_version' => 1, 'request_key' => 'c-1'])
            ->assertOk()->assertJsonPath('reminder.state', 'completed');
        $this->actingAs($manager)->postJson("{$url}/{$oneOff['id']}/pause", ['note' => 'late', 'expected_version' => 1, 'request_key' => 'p-stale'])
            ->assertStatus(409);
        // The service obligation itself is untouched.
        $this->assertSame('2026-10-01', $schedule->fresh()->next_due_at->toDateString());

        // A repeating reminder moves on by a calendar month without overflowing.
        $this->actingAs($manager)->postJson("{$url}/{$repeating['id']}/complete", ['note' => 'Checked.', 'expected_version' => 1, 'request_key' => 'c-2'])
            ->assertOk()->assertJsonPath('reminder.state', 'scheduled');
        $this->assertSame('2026-11-30 09:00', FleetVehicleReminder::query()->findOrFail($repeating['id'])->due_at
            ->setTimezone('Pacific/Auckland')->format('Y-m-d H:i'));
        $this->actingAs($manager)->postJson("{$url}/{$repeating['id']}/pause", ['note' => 'Vehicle in storage.', 'expected_version' => 2, 'request_key' => 'p-1'])
            ->assertOk()->assertJsonPath('reminder.state', 'paused');
        $this->actingAs($manager)->postJson("{$url}/{$repeating['id']}/resume", ['note' => 'Back in service.', 'expected_version' => 3, 'request_key' => 'u-1'])
            ->assertOk()->assertJsonPath('reminder.state', 'scheduled');
        $this->assertSame(4, FleetVehicleReminder::query()->findOrFail($repeating['id'])->events()->count());
    }

    public function test_reminders_reach_all_tasks_and_the_site_calendar_for_permitted_readers(): void
    {
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage']);
        $foreignReader = $this->siteUser([$this->foreignSite], ['fleet.viewAny']);
        $vehicle = $this->vehicle($this->site);
        $reminder = FleetVehicleReminder::query()->create([
            'asset_id' => $vehicle->id, 'title' => 'Evidence review', 'action_text' => 'Check the WoF sheet.',
            'source_type' => 'vehicle', 'due_at' => now()->addDays(2), 'owner_user_id' => $manager->id,
            'state' => 'scheduled', 'lock_version' => 1,
        ]);

        $item = collect((new TaskAggregator)->itemsFor($manager, []))->firstWhere('id', 'fleet_vehicle_reminder-'.$reminder->id);
        $this->assertNotNull($item);
        $this->assertSame("/fleet-assets/vehicles/{$vehicle->id}?tab=service&view=reminders", $item->link);
        $this->assertSame($manager->id, $item->assignee['id']);
        $this->assertNull(collect((new TaskAggregator)->itemsFor($foreignReader, []))->firstWhere('id', 'fleet_vehicle_reminder-'.$reminder->id));
        $this->actingAs($manager)->getJson('/tasks/detail?source=fleet_vehicle_reminder&id='.$reminder->id)
            ->assertOk()->assertJsonPath('item.id', 'fleet_vehicle_reminder-'.$reminder->id);

        $calendar = (new FleetVehicleReminderObligationProvider)->obligations([$this->site->id], now()->subDay(), now()->addWeek());
        $this->assertSame(['fleet-reminder-'.$reminder->id], array_map(fn ($entry) => $entry->id, $calendar));
        $this->assertSame([], (new FleetVehicleReminderObligationProvider)->obligations([$this->foreignSite->id], now()->subDay(), now()->addWeek()));
    }

    public function test_service_schedules_use_calendar_months_and_move_on_from_the_actual_service(): void
    {
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.maintenance.manage']);
        $owner = $this->siteUser([$this->site], []);
        $vehicle = $this->vehicle($this->site);
        FleetVehicleOdometerObservation::query()->create([
            'asset_id' => $vehicle->id, 'value_km' => 59000, 'observed_at' => '2026-08-20 00:00:00',
            'source_kind' => 'dashboard_manual', 'request_key' => 'seed', 'request_fingerprint' => str_repeat('0', 64), 'created_at' => now(),
        ]);
        $url = "/fleet-assets/vehicles/{$vehicle->id}/service-schedules";

        $this->actingAs($manager)->postJson($url, ['name' => 'Routine service', 'owner_user_id' => $owner->id, 'next_due_at' => '2026-10-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('request_key');
        $this->actingAs($manager)->postJson($url, ['name' => 'Routine service', 'owner_user_id' => $owner->id, 'next_due_at' => '2026-10-01',
            'request_key' => 'schedule-no-interval'])->assertUnprocessable()->assertJsonValidationErrors('interval_months');
        $this->actingAs($manager)->postJson($url, ['name' => 'Routine service', 'owner_user_id' => $owner->id, 'interval_months' => 6,
            'request_key' => 'schedule-no-trigger'])->assertUnprocessable()->assertJsonValidationErrors('next_due_at');
        $create = [
            'name' => 'Routine service', 'interval_months' => 6, 'interval_km' => 10000,
            'next_due_at' => '2026-09-01', 'next_due_km' => 60000, 'owner_user_id' => $owner->id,
        ];
        $schedule = $this->actingAs($manager)->postJson($url, $create, ['Idempotency-Key' => 'schedule-create-01'])
            ->assertOk()->json('schedule');
        // A retried create returns the same schedule; the key can't be reused for other details.
        $this->actingAs($manager)->postJson($url, $create, ['Idempotency-Key' => 'schedule-create-01'])
            ->assertOk()->assertJsonPath('schedule.id', $schedule['id']);
        $this->actingAs($manager)->postJson($url, ['interval_km' => 12000] + $create, ['Idempotency-Key' => 'schedule-create-01'])
            ->assertStatus(409);
        $this->assertSame(1, FleetServiceSchedule::query()->where('asset_id', $vehicle->id)->count());

        $complete = "{$url}/{$schedule['id']}/completions";
        $this->actingAs($manager)->postJson($complete, ['completed_on' => '2026-09-23', 'notes' => 'x', 'expected_version' => 1, 'request_key' => 'f'])
            ->assertUnprocessable()->assertJsonValidationErrors('completed_on');
        $this->actingAs($manager)->postJson($complete, ['completed_on' => '2026-08-31', 'odometer_km' => 58000, 'notes' => 'x', 'expected_version' => 1, 'request_key' => 'low'])
            ->assertUnprocessable()->assertJsonValidationErrors('odometer_km');
        $this->actingAs($manager)->postJson($complete, [
            'completed_on' => '2026-08-31', 'odometer_km' => 60120, 'provider' => 'Example Motors',
            'evidence_reference' => 'INV-2231', 'notes' => 'Routine service and oil change.', 'expected_version' => 1, 'request_key' => 'done-1',
        ])->assertOk()->assertJsonPath('completion.next_due_at', '2027-02-28')->assertJsonPath('completion.next_due_km', 70120);

        $completion = FleetServiceCompletion::query()->sole();
        $this->assertSame('2026-09-01', $completion->previous_next_due_at->toDateString());
        $this->assertSame('fleet_service_completion', FleetVehicleOdometerObservation::query()->findOrFail($completion->odometer_observation_id)->source_type);
        $this->assertSame('2027-02-28', $schedule = FleetServiceSchedule::query()->findOrFail($schedule['id'])->next_due_at->toDateString());

        // The legacy list still records a service, without inventing a reading.
        $legacy = FleetServiceSchedule::query()->create(['asset_id' => $vehicle->id, 'name' => 'Tyres', 'interval_days' => 90,
            'interval_km' => 20000, 'next_due_at' => '2026-09-10', 'next_due_km' => 80000, 'is_active' => true]);
        $this->actingAs($manager)->post("/fleet-assets/maintenance/schedules/{$legacy->id}/mark-complete")->assertSessionHasNoErrors();
        $legacy->refresh();
        $this->assertSame('2026-12-21', $legacy->next_due_at->toDateString());
        $this->assertNull($legacy->next_due_km);
    }

    public function test_the_legacy_schedules_list_only_shows_accessible_vehicles(): void
    {
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.maintenance.manage']);
        $local = FleetServiceSchedule::query()->create(['asset_id' => $this->vehicle($this->site)->id, 'name' => 'Local', 'interval_days' => 30, 'is_active' => true]);
        FleetServiceSchedule::query()->create(['asset_id' => $this->vehicle($this->foreignSite)->id, 'name' => 'Foreign', 'interval_days' => 30, 'is_active' => true]);

        $this->actingAs($manager)->get('/fleet-assets/maintenance/schedules')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('schedules', fn ($rows) => collect($rows)->pluck('id')->all() === [$local->id])
                ->where('assets', fn ($rows) => count($rows) === 1)
                ->etc());
    }

    public function test_catalogue_additions_are_deduplicated_and_need_management_access(): void
    {
        $manager = $this->siteUser([$this->site], ['fleet.viewAny', 'fleet.manage']);
        $viewer = $this->siteUser([$this->site], ['fleet.viewAny']);

        $first = $this->actingAs($manager)->postJson('/fleet-assets/catalogue/service_type', ['label' => '  Wheelchair   hoist service '])
            ->assertOk()->json('entry');
        $this->assertSame('Wheelchair hoist service', $first['label']);
        $this->actingAs($manager)->postJson('/fleet-assets/catalogue/service_type', ['label' => 'wheelchair HOIST service'])
            ->assertOk()->assertJsonPath('entry.id', $first['id']);
        $this->actingAs($manager)->postJson('/fleet-assets/catalogue/interval_months', ['label' => '1.5'])->assertUnprocessable();
        $this->actingAs($manager)->postJson('/fleet-assets/catalogue/interval_months', ['label' => '18'])->assertOk();
        $this->actingAs($manager)->postJson('/fleet-assets/catalogue/unknown_kind', ['label' => 'x'])->assertNotFound();
        $this->actingAs($viewer)->postJson('/fleet-assets/catalogue/service_type', ['label' => 'Valet'])->assertForbidden();

        $this->assertSame(2, FleetCatalogueEntry::query()->count());
    }

    private function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
    }

    /**
     * @param  list<Site>  $sites
     * @param  list<string>  $permissions
     */
    private function siteUser(array $sites, array $permissions): User
    {
        $user = User::factory()->create(['approved_at' => now(), 'role' => 'manager']);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id, 'primary_site_id' => $sites[0]->id,
            'secondary_site_ids' => collect($sites)->skip(1)->pluck('id')->values()->all(),
            'start_date' => today()->subYear(), 'end_date' => null, 'is_active' => true,
            'created_by' => $user->id, 'updated_by' => $user->id,
        ]);
        $user->permissionOverrides()->syncWithoutDetaching(collect($permissions)->mapWithKeys(fn (string $key): array => [
            Permission::query()->firstOrCreate(['key' => $key], [
                'description' => $key, 'group' => str($key)->before('.')->value(), 'module' => str($key)->before('.')->value(),
            ])->id => ['allowed' => true],
        ])->all());
        $user->unsetRelation('permissionOverrides');

        return $user;
    }

    private function vehicle(Site $site): Asset
    {
        return Asset::factory()->vehicle()->create([
            'site_id' => $site->id, 'home_site_id' => $site->id, 'name' => 'Kōwhai van', 'status' => 'active',
        ]);
    }
}
