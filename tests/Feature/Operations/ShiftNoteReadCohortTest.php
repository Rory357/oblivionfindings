<?php

namespace Tests\Feature\Operations;

use App\Domain\Hr\Models\HrEmployeeProfile;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ShiftNoteReadCohortTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $viewer;

    private User $worker;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-07 05:00:00', 'UTC'));
        config(['app.worker_timezone' => 'Pacific/Auckland']);
        Queue::fake([RefreshWorkforceEligibility::class]);
        $this->site = Site::factory()->create(['name' => 'Visible House']);
        $this->viewer = $this->actor(['shifts.viewAny', 'rostering.viewAny']);
        $this->worker = $this->actor([]);
        $this->shift = $this->duty($this->site, $this->worker);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_summary_status_pagination_and_complete_csv_share_the_same_permitted_cohort(): void
    {
        $rows = [];
        for ($i = 0; $i < 401; $i++) {
            $rows[] = ['organization_id' => $this->shift->organization_id, 'shift_id' => $this->shift->id, 'client_id' => $this->shift->client_id, 'user_id' => $this->worker->id,
                'type' => 'shift_note', 'body' => 'Cohort marker '.$i, 'visibility' => 'internal', 'is_private' => false,
                'is_flagged' => $i === 0, 'reviewed_at' => $i === 0 ? now() : null,
                'reviewed_by' => $i === 0 ? $this->worker->id : null, 'created_at' => now(), 'updated_at' => now()];
        }
        ClientNote::insert($rows);
        $last = ClientNote::where('body', 'Cohort marker 0')->sole();
        $this->note(['body' => 'Hidden private cohort marker', 'is_private' => true, 'is_flagged' => true]);
        $foreignSite = Site::factory()->create();
        $foreignWorker = $this->actor([], $foreignSite);
        $foreign = $this->duty($foreignSite, $foreignWorker);
        $this->note(['body' => 'Foreign cohort marker', 'shift_id' => $foreign->id, 'client_id' => $foreign->client_id]);
        $this->actingAs($this->viewer)->get($this->index(['page' => 2]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('notes', 1)->where('notes.0.id', $last->id)
            ->where('summary', ['total' => 401, 'flagged' => 1, 'awaiting' => 400, 'reviewed' => 1])
            ->where('pagination.current_page', 2)->where('pagination.total', 401)
            ->where('pagination.from', 401)->where('pagination.to', 401)
            ->where('evidence.complete', true)->where('evidence.scope', 'permitted_sites'));
        $this->get($this->index(['status' => 'reviewed', 'page' => 99]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('notes', 1)->where('notes.0.id', $last->id)->where('summary.total', 401)
            ->where('filters.page', 1)->where('pagination.current_page', 1)->where('pagination.total', 1)
            ->where('pagination.links', fn ($links) => collect($links)->whereNotNull('url')->every(fn ($link) => str_contains($link['url'], 'status=reviewed'))));
        $all = $this->get($this->export())->assertOk()->streamedContent();
        $this->assertSame(401, substr_count($all, 'Cohort marker'));
        $this->assertStringNotContainsString('Hidden private', $all);
        $this->assertStringNotContainsString('Foreign cohort', $all);
        $reviewed = $this->get($this->export(['status' => 'reviewed', 'page' => 2]))->assertOk()->streamedContent();
        $this->assertSame(1, substr_count($reviewed, 'Cohort marker'));
        $this->assertStringContainsString('Cohort marker 0', $reviewed);
    }

    #[DataProvider('literalSearches')]
    public function test_literal_unicode_search_filters_summary_page_and_export_without_wildcard_expansion(string $needle): void
    {
        $matching = $this->note(['body' => 'Prefix '.$needle.' suffix']);
        $this->note(['body' => 'Unrelated public wording']);
        $filters = ['q' => $needle];
        $this->actingAs($this->viewer)->get($this->index($filters))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('notes', 1)->where('notes.0.id', $matching->id)->where('summary.total', 1)->where('pagination.total', 1));
        $csv = $this->get($this->export($filters))->assertOk()->streamedContent();
        $this->assertStringContainsString($needle, $csv);
        $this->assertStringNotContainsString('Unrelated public', $csv);
    }

    public static function literalSearches(): array
    {
        return ['percent' => ['50%'], 'underscore' => ['one_two'], 'backslash' => ['path\\leaf'], 'Unicode' => ['Māori care'], 'quote' => ["person's"]];
    }

    public function test_client_author_site_type_and_date_filters_are_identical_in_counts_rows_and_csv(): void
    {
        $target = $this->note(['body' => 'Exact selected note', 'is_flagged' => true]);
        $this->note(['body' => 'Wrong type', 'type' => 'incident']);
        $other = $this->duty($this->site, $this->worker);
        $this->note(['body' => 'Other Client', 'shift_id' => $other->id, 'client_id' => $other->client_id]);
        $this->note(['body' => 'Other author', 'user_id' => $this->viewer->id]);
        $filters = ['client_id' => $this->shift->client_id, 'author_id' => $this->worker->id, 'site_id' => $this->site->id,
            'type' => 'shift_note', 'date_from' => '2026-10-05', 'date_to' => '2026-10-05', 'flagged' => true];
        $this->actingAs($this->viewer)->get($this->index($filters))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('notes', 1)->where('notes.0.id', $target->id)->where('summary.total', 1)->where('summary.flagged', 1));
        $csv = $this->get($this->export($filters))->assertOk()->streamedContent();
        $this->assertStringContainsString('Exact selected note', $csv);
        foreach (['Wrong type', 'Other Client', 'Other author'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $csv);
        }
        $this->get($this->index(['site_id' => Site::factory()->create()->id]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('notes', 0)->where('summary.total', 0)->where('evidence.state', 'no_records'));
    }

    public function test_worker_local_half_open_week_keeps_late_notes_and_excludes_both_neighbor_boundaries(): void
    {
        config(['app.worker_timezone' => 'America/New_York']);
        $start = Carbon::parse('2026-10-05 00:00:00', 'America/New_York')->utc();
        $this->shift->forceFill(['starts_at' => $start, 'ends_at' => $start->copy()->addHour()])->saveQuietly();
        $target = $this->note(['body' => 'Late recorded first-minute note']);
        $target->forceFill(['created_at' => now()->addWeeks(2)])->saveQuietly();
        foreach ([$start->copy()->subSecond(), $start->copy()->addWeek()] as $instant) {
            $duty = $this->duty($this->site, $this->worker);
            $duty->forceFill(['starts_at' => $instant, 'ends_at' => $instant->copy()->addHour()])->saveQuietly();
            $this->note(['body' => 'Outside week boundary', 'shift_id' => $duty->id, 'client_id' => $duty->client_id]);
        }
        $this->actingAs($this->viewer)->get($this->index())->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('notes', 1)->where('notes.0.id', $target->id)->where('summary.total', 1)
            ->where('workerTimezone', 'America/New_York')->where('evidence.period_start', '2026-10-05T04:00:00+00:00')
            ->where('evidence.period_end_exclusive', '2026-10-12T04:00:00+00:00'));
        $csv = $this->get($this->export())->assertOk()->streamedContent();
        $this->assertStringContainsString('2026-10-05 00:00', $csv);
        $this->assertStringNotContainsString('Outside week boundary', $csv);
    }

    public function test_dst_week_evidence_uses_exact_worker_midnights_not_fixed_168_hours(): void
    {
        config(['app.worker_timezone' => 'America/New_York']);
        $start = Carbon::parse('2026-10-26 00:00:00', 'America/New_York')->utc();
        $this->shift->forceFill(['starts_at' => $start, 'ends_at' => $start->copy()->addHour()])->saveQuietly();
        $note = $this->note(['body' => 'DST week record']);
        $this->actingAs($this->viewer)->get($this->index(['week' => '2026-10-26']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('notes', 1)->where('notes.0.id', $note->id)->where('summary.total', 1)
            ->where('evidence.period_start', '2026-10-26T04:00:00+00:00')
            ->where('evidence.period_end_exclusive', '2026-11-02T05:00:00+00:00'));
        $csv = $this->get($this->export(['week' => '2026-10-26']))->assertOk()->streamedContent();
        $this->assertStringContainsString('DST week record', $csv);
        $this->assertStringContainsString('2026-10-26 00:00', $csv);
    }

    public function test_existing_legacy_context_intersections_are_retained_without_new_organisation_workflows(): void
    {
        $visible = $this->note(['body' => 'Visible legacy context']);
        $hidden = $this->note(['body' => 'Retained mismatched legacy context']);
        DB::table('client_notes')->where('id', $hidden->id)->update(['organization_id' => 7]);
        $otherClient = Client::factory()->create(['site_id' => $this->site->id]);
        DB::table('clients')->where('id', $otherClient->id)->update(['organization_id' => 7]);
        $otherUser = $this->actor([]);
        DB::table('users')->where('id', $otherUser->id)->update(['organization_id' => 7]);
        $this->actingAs($this->viewer)->get($this->index())->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('notes', 1)->where('notes.0.id', $visible->id)->where('summary.total', 1)
            ->where('catalogue.clients', fn ($rows) => ! collect($rows)->pluck('id')->contains($otherClient->id))
            ->where('catalogue.staff', fn ($rows) => ! collect($rows)->pluck('id')->contains($otherUser->id)));
        $this->assertStringNotContainsString('Retained mismatched', $this->get($this->export())->streamedContent());
        DB::table('shifts')->where('id', $this->shift->id)->update(['organization_id' => 7]);
        $this->post(route('operations.shift_notes.store'), ['shift_id' => $this->shift->id, 'type' => 'note', 'body' => 'Denied'])->assertNotFound();
        $this->assertSame('Retained mismatched legacy context', $hidden->fresh()->body);
    }

    public function test_catalogue_note_evidence_ignores_search_but_cannot_disclose_private_or_foreign_notes(): void
    {
        $visible = $this->note(['body' => 'Already visible documentation']);
        $other = $this->duty($this->site, $this->worker);
        $this->note(['shift_id' => $other->id, 'client_id' => $other->client_id, 'body' => 'Private documentation', 'is_private' => true]);
        $this->actingAs($this->viewer)->get($this->index(['q' => 'No matching public text']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('notes', 0)->where('summary.total', 0)->where('catalogue.note_shift_ids', [$visible->shift_id])
            ->where('catalogue.shift_results.truncated', false));
        $this->assertFalse($visible->is_private);
    }

    public function test_csv_quotes_all_cells_and_protects_spreadsheet_formula_prefixes_without_changing_storage(): void
    {
        $this->worker->forceFill(['name' => '+Author, "quoted"'])->saveQuietly();
        $this->shift->client->forceFill(['first_name' => '@Client', 'last_name' => 'Māori, "quoted"'])->saveQuietly();
        $note = $this->note(['body' => '=SUM(1,2)']);
        $csv = $this->actingAs($this->viewer)->get($this->export())->assertOk()->streamedContent();
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $csv);
        rewind($stream);
        fgetcsv($stream, null, ',', '"', '');
        $row = fgetcsv($stream, null, ',', '"', '');
        fclose($stream);
        $this->assertSame("'@Client Māori, \"quoted\"", $row[1]);
        $this->assertSame("'+Author, \"quoted\"", $row[2]);
        $this->assertSame("'=SUM(1,2)", $row[4]);
        $this->assertSame('=SUM(1,2)', $note->fresh()->body);
    }

    private function index(array $filters = []): string
    {
        return route('operations.shift_notes.index', ['week' => '2026-10-05', ...$filters]);
    }

    private function export(array $filters = []): string
    {
        return route('operations.shift_notes.export', ['week' => '2026-10-05', ...$filters]);
    }

    private function note(array $attributes = []): ClientNote
    {
        return ClientNote::create(['shift_id' => $this->shift->id, 'client_id' => $this->shift->client_id,
            'user_id' => $this->worker->id, 'type' => 'shift_note', 'body' => 'Visible note', 'visibility' => 'internal',
            'is_private' => false, 'is_flagged' => false, ...$attributes]);
    }

    private function actor(array $permissions, ?Site $site = null): User
    {
        $actor = User::factory()->create(['approved_at' => now(), 'role' => 'coordinator', 'organization_id' => 1]);
        if ($permissions !== []) {
            $role = Role::create(['name' => 'notes-cohort-'.Str::uuid(), 'label' => 'Notes fixture', 'type' => 'custom', 'level' => 40]);
            $role->permissions()->sync(collect($permissions)->map(fn ($key) => Permission::firstOrCreate(['key' => $key], ['description' => $key])->id));
            $actor->roles()->attach($role);
        }
        HrEmployeeProfile::factory()->create(['user_id' => $actor->id, 'primary_site_id' => ($site ?? $this->site)->id,
            'secondary_site_ids' => [], 'start_date' => '2020-01-01', 'end_date' => null, 'is_active' => true,
            'created_by' => $actor->id, 'updated_by' => $actor->id]);

        return $actor->fresh();
    }

    private function duty(Site $site, User $worker): Shift
    {
        $client = Client::factory()->create(['site_id' => $site->id, 'organization_id' => 1]);
        $context = ServiceContext::factory()->create(['site_id' => $site->id, 'is_active' => true]);

        return Shift::factory()->create(['site_id' => $site->id, 'client_id' => $client->id, 'user_id' => $worker->id,
            'organization_id' => 1, 'created_by' => $this->viewer->id, 'service_context_id' => $context->id,
            'starts_at' => Carbon::parse('2026-10-05 09:00:00', 'Pacific/Auckland')->utc(),
            'ends_at' => Carbon::parse('2026-10-05 13:00:00', 'Pacific/Auckland')->utc()]);
    }
}
