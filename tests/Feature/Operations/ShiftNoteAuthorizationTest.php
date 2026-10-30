<?php

namespace Tests\Feature\Operations;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\RoleScope;
use App\Jobs\RefreshWorkforceEligibility;
use App\Models\Client;
use App\Models\ClientNote;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ShiftNoteAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $supportWorker;

    private User $coordinatorWithoutShiftNotes;

    private Shift $shift;

    private ClientNote $note;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([RefreshWorkforceEligibility::class]);

        $this->site = Site::factory()->create();

        $adminRole = $this->roleWithPermissions('admin', [
            'shifts.viewAny',
            'shifts.viewAssigned',
            'shifts.manageAny',
            'progress_notes.review',
            'progress_notes.update',
        ], level: 100);
        $supportWorkerRole = $this->roleWithPermissions('support_worker', [
            'shifts.viewAssigned',
        ]);

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'approved_at' => now(),
            'organization_id' => 1,
        ]);
        $this->admin->roles()->attach($adminRole);

        $this->supportWorker = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
            'organization_id' => 1,
        ]);
        $this->supportWorker->roles()->attach($supportWorkerRole);
        $this->assignToSite($this->admin);
        $this->assignToSite($this->supportWorker);

        $this->coordinatorWithoutShiftNotes = $this->userWithPermissions(['rostering.viewAny']);

        $client = Client::factory()->create(['organization_id' => 1, 'site_id' => $this->site->id]);
        $serviceContext = ServiceContext::factory()->create(['is_active' => true, 'site_id' => $this->site->id]);
        $workerTimezone = config('app.worker_timezone') ?: config('app.timezone', 'UTC');
        $shiftStartsAt = Carbon::now($workerTimezone)
            ->startOfWeek(Carbon::MONDAY)
            ->addDay()
            ->setTime(9, 0)
            ->utc();
        $this->shift = Shift::factory()->create([
            'organization_id' => 1,
            'site_id' => $this->site->id,
            'client_id' => $client->id,
            'service_context_id' => $serviceContext->id,
            'user_id' => $this->supportWorker->id,
            'created_by' => $this->admin->id,
            // Within the current ISO week so the note lands in the default
            // (current-week) view, which scopes by the shift's start date.
            'starts_at' => $shiftStartsAt,
            'ends_at' => $shiftStartsAt->copy()->addHours(8),
        ]);

        $this->note = ClientNote::query()->create([
            'organization_id' => 1,
            'client_id' => $client->id,
            'shift_id' => $this->shift->id,
            'user_id' => $this->supportWorker->id,
            'type' => 'daily_note',
            'subject' => 'Shift summary',
            'body' => 'Sensitive support note',
            'is_private' => true,
            'is_flagged' => false,
            'visibility' => 'internal',
        ]);
    }

    public function test_frontline_staff_are_redirected_from_shift_note_read_routes(): void
    {
        $this->actingAs($this->supportWorker)
            ->get(route('operations.shift_notes.index'))
            ->assertRedirect(route('my-day'));

        $this->actingAs($this->supportWorker)
            ->get(route('operations.shift_notes.export'))
            ->assertRedirect(route('my-day'));
    }

    public function test_manager_with_shift_view_any_can_load_shift_notes(): void
    {
        $this->actingAs($this->admin)
            ->get(route('operations.shift_notes.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('operations/shift-notes/Index')
                ->has('notes', 1)
                ->has('catalogue')
                ->where('currentUser.is_manager', true));
    }

    public function test_non_frontline_user_without_shift_view_any_is_forbidden_from_read_routes(): void
    {
        $this->actingAs($this->coordinatorWithoutShiftNotes)
            ->get(route('operations.shift_notes.index'))
            ->assertForbidden();

        $this->actingAs($this->coordinatorWithoutShiftNotes)
            ->get(route('operations.shift_notes.export'))
            ->assertForbidden();
    }

    public function test_flag_and_review_routes_require_shift_view_any(): void
    {
        $this->actingAs($this->supportWorker)
            ->patch(route('operations.shift_notes.flag', $this->note), [
                'flagged_reason' => 'Needs coordinator review',
            ])
            ->assertForbidden();

        $this->actingAs($this->supportWorker)
            ->patch(route('operations.shift_notes.review', $this->note))
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->from(route('operations.shift_notes.index'))
            ->patch(route('operations.shift_notes.flag', $this->note), [
                'flagged_reason' => 'Needs coordinator review',
            ])
            ->assertRedirect(route('operations.shift_notes.index'));

        $this->assertDatabaseHas('client_notes', [
            'id' => $this->note->id,
            'is_flagged' => true,
            'flagged_reason' => 'Needs coordinator review',
        ]);

        $this->actingAs($this->admin)
            ->from(route('operations.shift_notes.index'))
            ->patch(route('operations.shift_notes.review', $this->note))
            ->assertRedirect(route('operations.shift_notes.index'));

        $this->assertDatabaseHas('client_notes', [
            'id' => $this->note->id,
            'reviewed_by' => $this->admin->id,
        ]);
    }

    public function test_manager_can_create_a_shift_note(): void
    {
        $this->actingAs($this->admin)
            ->from(route('operations.shift_notes.index'))
            ->post(route('operations.shift_notes.store'), [
                'shift_id' => $this->shift->id,
                'type' => 'shift_note',
                'body' => 'Settled morning shift, no concerns.',
            ])
            ->assertRedirect(route('operations.shift_notes.index'));

        $this->assertDatabaseHas('client_notes', [
            'shift_id' => $this->shift->id,
            'user_id' => $this->admin->id,
            'type' => 'shift_note',
            'body' => 'Settled morning shift, no concerns.',
        ]);
    }

    public function test_manager_can_update_any_note(): void
    {
        $this->actingAs($this->admin)
            ->from(route('operations.shift_notes.index'))
            ->put(route('operations.shift_notes.update', $this->note), [
                'type' => 'progress_note',
                'body' => 'Corrected by the coordinator.',
            ])
            ->assertRedirect(route('operations.shift_notes.index'));

        $this->assertDatabaseHas('client_notes', [
            'id' => $this->note->id,
            'type' => 'progress_note',
            'body' => 'Corrected by the coordinator.',
            'edited_by' => $this->admin->id,
        ]);
    }

    public function test_author_can_update_their_note_within_the_edit_window(): void
    {
        $author = $this->userWithPermissions(['shifts.viewAny']);
        $note = $this->authoredNote($author, now());

        $this->actingAs($author)
            ->from(route('operations.shift_notes.index'))
            ->put(route('operations.shift_notes.update', $note), [
                'type' => 'note',
                'body' => 'Author tidied up the wording.',
            ])
            ->assertRedirect(route('operations.shift_notes.index'));

        $this->assertDatabaseHas('client_notes', [
            'id' => $note->id,
            'body' => 'Author tidied up the wording.',
            'edited_by' => $author->id,
        ]);
    }

    public function test_author_cannot_update_their_note_after_the_edit_window_closes(): void
    {
        $author = $this->userWithPermissions(['shifts.viewAny']);
        $note = $this->authoredNote($author, now()->subDays(8));

        $this->actingAs($author)
            ->put(route('operations.shift_notes.update', $note), [
                'type' => 'note',
                'body' => 'Too late to edit this.',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('client_notes', [
            'id' => $note->id,
            'body' => 'Too late to edit this.',
        ]);
    }

    public function test_non_author_non_manager_cannot_update_a_note(): void
    {
        $coordinator = $this->userWithPermissions(['shifts.viewAny']);

        $this->actingAs($coordinator)
            ->put(route('operations.shift_notes.update', $this->note), [
                'type' => 'note',
                'body' => 'Not my note to edit.',
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('client_notes', [
            'id' => $this->note->id,
            'body' => 'Not my note to edit.',
        ]);
    }

    private function authoredNote(User $author, \Illuminate\Support\Carbon $createdAt): ClientNote
    {
        $note = ClientNote::query()->create([
            'organization_id' => 1,
            'client_id' => $this->note->client_id,
            'shift_id' => $this->shift->id,
            'user_id' => $author->id,
            'type' => 'shift_note',
            'body' => 'Original wording.',
            'visibility' => 'internal',
        ]);

        $note->forceFill(['created_at' => $createdAt])->saveQuietly();

        return $note->refresh();
    }

    public function test_controller_guards_still_require_shift_view_any_if_route_permission_middleware_is_bypassed(): void
    {
        $this->withoutMiddleware([EnsurePermission::class, RoleScope::class]);

        $this->actingAs($this->supportWorker)
            ->get(route('operations.shift_notes.index'))
            ->assertForbidden();

        $this->actingAs($this->supportWorker)
            ->get(route('operations.shift_notes.export'))
            ->assertForbidden();

        $this->actingAs($this->supportWorker)
            ->patch(route('operations.shift_notes.flag', $this->note), [
                'flagged_reason' => 'Needs coordinator review',
            ])
            ->assertForbidden();

        $this->actingAs($this->supportWorker)
            ->patch(route('operations.shift_notes.review', $this->note))
            ->assertForbidden();
    }

    private function userWithPermissions(array $permissionKeys): User
    {
        $user = User::factory()->create([
            'approved_at' => now(),
            'organization_id' => 1,
        ]);
        $this->assignToSite($user);

        foreach (array_unique([...$permissionKeys, 'rostering.viewAny']) as $key) {
            $permission = Permission::firstOrCreate(
                ['key' => $key],
                ['description' => $key]
            );

            $user->permissionOverrides()->syncWithoutDetaching([
                $permission->id => ['allowed' => true],
            ]);
        }

        return $user;
    }

    private function assignToSite(User $user, ?Site $site = null): void
    {
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => ($site ?? $this->site)->id, 'secondary_site_ids' => [], 'is_active' => true]);
    }

    public function test_private_notes_use_the_existing_author_and_review_policy(): void
    {
        $viewer = $this->userWithPermissions(['shifts.viewAny']);
        $own = $this->authoredNote($viewer, now());
        $own->update(['is_private' => true]);

        $this->actingAs($viewer)->get(route('operations.shift_notes.index'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('notes', 1)->where('notes.0.id', $own->id));
        $csv = $this->actingAs($viewer)->get(route('operations.shift_notes.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('Original wording.', $csv);
        $this->assertStringNotContainsString('Sensitive support note', $csv);
        $this->actingAs($viewer)->get(route('operations.shift_notes.index', ['q' => 'No matching text']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('notes', 0)
            ->where('catalogue.note_shift_ids', [$this->shift->id]));
        $this->actingAs($viewer)->patch(route('operations.shift_notes.flag', $this->note))->assertNotFound();
        $this->actingAs($viewer)->patch(route('operations.shift_notes.review', $own))->assertForbidden();

        $reviewer = $this->userWithPermissions(['shifts.viewAny', 'progress_notes.review']);
        $this->actingAs($reviewer)->get(route('operations.shift_notes.index'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('notes', 2));
        $this->actingAs($reviewer)->patch(route('operations.shift_notes.review', $this->note))->assertRedirect();
        $this->assertSame($reviewer->id, $this->note->fresh()->reviewed_by);
        $unrelated = $this->userWithPermissions(['shifts.viewAny']);
        $this->actingAs($unrelated)->get(route('operations.shift_notes.index'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('catalogue.note_shift_ids', []));
    }

    public function test_note_reads_writes_export_and_catalogue_reject_other_site_records(): void
    {
        $otherSite = Site::factory()->create();
        $otherWorker = User::factory()->create(['approved_at' => now()]);
        $this->assignToSite($otherWorker, $otherSite);
        $otherClient = Client::factory()->create(['site_id' => $otherSite->id]);
        $otherShift = Shift::factory()->create([
            'site_id' => $otherSite->id, 'client_id' => $otherClient->id, 'user_id' => $otherWorker->id,
            'starts_at' => $this->shift->starts_at, 'ends_at' => $this->shift->ends_at,
        ]);
        $otherNote = ClientNote::create(['shift_id' => $otherShift->id, 'client_id' => $otherClient->id, 'user_id' => $otherWorker->id,
            'type' => 'shift_note', 'body' => 'Other house private record', 'is_private' => false, 'visibility' => 'internal']);
        $reviewer = $this->userWithPermissions(['shifts.viewAny', 'shifts.manageAny', 'progress_notes.review']);

        $this->actingAs($reviewer)->get(route('operations.shift_notes.index'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('notes', 1)
            ->where('catalogue.sites', fn ($sites) => ! collect($sites)->pluck('id')->contains($otherSite->id))
            ->where('catalogue.clients', fn ($clients) => ! collect($clients)->pluck('id')->contains($otherClient->id))
            ->where('catalogue.staff', fn ($staff) => ! collect($staff)->pluck('id')->contains($otherWorker->id))
            ->where('catalogue.shifts', fn ($shifts) => ! collect($shifts)->pluck('id')->contains($otherShift->id)));
        $csv = $this->actingAs($reviewer)->get(route('operations.shift_notes.export'))->assertOk()->streamedContent();
        $this->assertStringNotContainsString('Other house private record', $csv);
        $this->actingAs($reviewer)->put(route('operations.shift_notes.update', $otherNote), ['type' => 'note', 'body' => 'Forbidden'])->assertNotFound();
        $this->actingAs($reviewer)->patch(route('operations.shift_notes.review', $otherNote))->assertNotFound();
        $this->actingAs($reviewer)->patch(route('operations.shift_notes.flag', $otherNote))->assertNotFound();
        $this->actingAs($reviewer)->post(route('operations.shift_notes.store'), ['shift_id' => $otherShift->id, 'type' => 'note', 'body' => 'Forbidden'])->assertNotFound();
    }

    public function test_filtered_export_and_index_use_the_effective_shift_date_and_worker_timezone(): void
    {
        $author = $this->userWithPermissions(['shifts.viewAny']);
        $note = $this->authoredNote($author, now()->addWeeks(2));
        $note->update(['body' => 'Late-written matching note', 'is_flagged' => true]);
        $filters = ['week' => $this->shift->starts_at->copy()->setTimezone('Pacific/Auckland')->toDateString(),
            'client_id' => $this->shift->client_id, 'author_id' => $author->id, 'type' => 'shift_note', 'q' => 'Late-written', 'status' => 'flagged'];

        $this->actingAs($author)->get(route('operations.shift_notes.index', $filters))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('notes', 1)->where('notes.0.id', $note->id)->where('results.total', 1));
        $expectedTime = $this->shift->starts_at->copy()->setTimezone('Pacific/Auckland')->format('Y-m-d H:i');
        $csv = $this->actingAs($author)->get(route('operations.shift_notes.export', $filters))->assertOk()->streamedContent();
        $this->assertStringContainsString('Late-written matching note', $csv);
        $this->assertStringContainsString($expectedTime, $csv);
        $this->assertStringNotContainsString('Sensitive support note', $csv);
    }

    public function test_report_read_bypass_preserves_private_notes_and_never_grants_note_mutation(): void
    {
        $viewer = $this->userWithPermissions(['shifts.viewAny', 'shifts.manageAny', 'reports.viewAny', 'progress_notes.update']);
        $otherSite = Site::factory()->create();
        $otherClient = Client::factory()->create(['site_id' => $otherSite->id]);
        $otherShift = Shift::factory()->create(['site_id' => $otherSite->id, 'client_id' => $otherClient->id,
            'user_id' => null, 'starts_at' => $this->shift->starts_at, 'ends_at' => $this->shift->ends_at]);
        $public = ClientNote::create(['shift_id' => $otherShift->id, 'client_id' => $otherClient->id, 'user_id' => $this->supportWorker->id,
            'type' => 'shift_note', 'body' => 'Report-visible public note', 'is_private' => false, 'visibility' => 'internal']);
        $private = ClientNote::create(['shift_id' => $otherShift->id, 'client_id' => $otherClient->id, 'user_id' => $this->supportWorker->id,
            'type' => 'shift_note', 'body' => 'Report-hidden private note', 'is_private' => true, 'visibility' => 'internal']);
        $this->actingAs($viewer)->get(route('operations.shift_notes.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('notes', 1)->where('notes.0.id', $public->id)
                ->where('notes.0.can_edit', false)->where('notes.0.can_flag', false)->where('notes.0.can_review', false));
        $csv = $this->actingAs($viewer)->get(route('operations.shift_notes.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('Report-visible public note', $csv);
        $this->assertStringNotContainsString('Report-hidden private note', $csv);
        $this->actingAs($viewer)->put(route('operations.shift_notes.update', $public), ['type' => 'note', 'body' => 'Forbidden'])->assertNotFound();
        $this->actingAs($viewer)->patch(route('operations.shift_notes.flag', $public))->assertNotFound();
        $this->actingAs($viewer)->patch(route('operations.shift_notes.review', $private))->assertNotFound();
        $this->actingAs($viewer)->post(route('operations.shift_notes.store'), ['shift_id' => $otherShift->id, 'type' => 'note', 'body' => 'Forbidden'])->assertNotFound();
    }

    public function test_result_limit_is_disclosed_and_export_includes_the_complete_filtered_result(): void
    {
        $author = $this->userWithPermissions(['shifts.viewAny']);
        $rows = [];
        for ($i = 0; $i < 405; $i++) {
            $rows[] = ['organization_id' => $this->shift->organization_id, 'client_id' => $this->shift->client_id, 'shift_id' => $this->shift->id, 'user_id' => $author->id,
                'type' => 'shift_note', 'body' => 'Limit marker '.$i, 'visibility' => 'internal', 'is_private' => false,
                'is_draft' => false, 'created_at' => now(), 'updated_at' => now()];
        }
        ClientNote::insert($rows);
        $this->actingAs($author)->get(route('operations.shift_notes.index', ['author_id' => $author->id]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('notes', 400)->where('results.total', 405)->where('results.truncated', true));
        $csv = $this->actingAs($author)->get(route('operations.shift_notes.export', ['author_id' => $author->id]))->assertOk()->streamedContent();
        $this->assertSame(405, substr_count($csv, 'Limit marker'));
    }

    private function roleWithPermissions(string $roleName, array $permissionKeys, int $level = 40): Role
    {
        $role = Role::firstOrCreate(
            ['name' => $roleName],
            [
                'label' => ucfirst(str_replace('_', ' ', $roleName)),
                'level' => $level,
                'type' => 'system',
            ],
        );

        foreach ($permissionKeys as $key) {
            $permission = Permission::firstOrCreate(
                ['key' => $key],
                ['description' => $key]
            );

            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return $role;
    }
}
