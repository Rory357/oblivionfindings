<?php

namespace Tests\Feature\Hr;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrTimeEntry;
use App\Jobs\RefreshWorkforceEligibility;
use App\Models\AppSetting;
use App\Models\Client;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MyHrTimeReadScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $worker;
    private Site $site;
    private ServiceContext $context;
    private ?Client $siteClient = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC', 'app.worker_timezone' => 'Pacific/Auckland', 'features.rostering.publish' => false]);
        AppSetting::query()->updateOrCreate(['key' => 'features.rostering.publish'], ['value' => false]);
        Carbon::setTestNow($this->at('2026-10-21 14:00'));
        Queue::fake([RefreshWorkforceEligibility::class]);
        Notification::fake();
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->worker = $this->staff($this->site);
        $this->context = ServiceContext::factory()->create(['site_id' => $this->site->id, 'name' => 'Scoped context']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_work_local_today_keeps_ledger_allocation_and_adds_actual_iso_instants(): void
    {
        Carbon::setTestNow($this->at('2026-10-21 00:30'));
        $entry = $this->entry(['clock_in' => $this->at('2026-10-20 23:50'), 'clock_out' => $this->at('2026-10-21 00:10'),
            'total_hours' => '0.33']);
        $this->entry(['entry_date' => '2026-10-20']);
        $response = $this->page();
        $this->assertSame('Pacific/Auckland', $response->inertiaProps('timezone'));
        $this->assertSame('2026-10-21', $response->inertiaProps('todayDate'));
        $this->assertSame('2026-10-19', $response->inertiaProps('weekStartDate'));
        $this->assertSame('2026-10-25', $response->inertiaProps('weekEndDate'));
        $entries = $response->inertiaProps('todayEntries');
        $this->assertSame([$entry->id], array_column($entries, 'id'));
        $this->assertSame('2026-10-21', $entries[0]['entry_date']);
        $this->assertSame('23:50', $entries[0]['clock_in']);
        $this->assertSame('00:10', $entries[0]['clock_out']);
        $this->assertSame($entry->clock_in->toIso8601String(), $entries[0]['clock_in_at']);
        $this->assertSame($entry->clock_out->toIso8601String(), $entries[0]['clock_out_at']);
        $this->assertSame('0.33', $entries[0]['total_hours']);
    }

    public function test_week_uses_local_overlap_bounds_and_preserves_completed_history_and_real_overnight_dates(): void
    {
        $carry = $this->shift('2026-10-18 23:00', '2026-10-19 01:00');
        $completed = $this->shift('2026-10-19 13:00', '2026-10-19 17:00');
        $completed->update(['status' => 'completed', 'actual_starts_at' => $completed->starts_at]);
        $this->shift('2026-10-18 22:00', '2026-10-19 00:00');
        $this->shift('2026-10-26 00:00', '2026-10-26 02:00');
        $roster = $this->page()->inertiaProps('weekRoster');
        $this->assertCount(7, $roster);
        $this->assertSame('2026-10-19', $roster[0]['date_key']);
        $this->assertSame([$carry->id, $completed->id], array_column($roster[0]['shifts'], 'id'));
        $this->assertTrue($roster[0]['shifts'][0]['continues_from_previous_week']);
        $this->assertFalse($roster[0]['shifts'][1]['continues_from_previous_week']);
        $this->assertSame($carry->starts_at->toIso8601String(), $roster[0]['shifts'][0]['starts_at']);
        $this->assertSame($carry->ends_at->toIso8601String(), $roster[0]['shifts'][0]['ends_at']);
        $this->assertSame('23:00–01:00', $roster[0]['shifts'][0]['time']);
        $this->assertTrue($roster[2]['today']);
        $this->assertCount(2, array_merge(...array_column($roster, 'shifts')));
    }

    public function test_own_scoped_work_preserves_related_metadata_without_workforce_view_grants(): void
    {
        $client = Client::factory()->create(['site_id' => $this->site->id, 'first_name' => 'Mere', 'last_name' => 'Example']);
        $shift = $this->shift('2026-10-21 15:00', '2026-10-22 01:00', ['client_id' => $client->id]);
        $entry = $this->entry(['shift_id' => $shift->id, 'client_id' => $client->id]);
        $this->assertFalse($this->worker->canDo('shifts.viewAssigned'));
        $this->assertFalse($this->worker->canDo('shifts.viewAny'));
        $response = $this->page();
        $this->assertSame($shift->id, $response->inertiaProps('upcomingShifts.0.id'));
        $this->assertSame('2026-10-21 15:00', $response->inertiaProps('upcomingShifts.0.starts_at'));
        $this->assertSame($shift->starts_at->toIso8601String(), $response->inertiaProps('upcomingShifts.0.starts_at_iso'));
        $this->assertSame('Scoped context', $response->inertiaProps('todayEntries.0.shift.service_context_name'));
        $this->assertSame('Mere Example', $response->inertiaProps('todayEntries.0.client_name'));
        $this->assertSame($entry->id, $response->inertiaProps('todayEntries.0.id'));
        $this->actingAs($this->worker)->get($this->calendar($shift))->assertOk();
    }

    #[DataProvider('deniedSources')]
    public function test_denied_related_work_never_hides_the_own_ledger_facts(string $shape): void
    {
        $foreign = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $shift = $this->shift('2026-10-21 15:00', '2026-10-21 18:00', ['client_id' => $client->id]);
        $entry = $this->entry(['shift_id' => $shift->id, 'client_id' => $client->id]);
        if ($shape === 'foreign site') {
            DB::table('shifts')->where('id', $shift->id)->update(['site_id' => $foreign->id]);
        } elseif ($shape === 'client conflict') {
            DB::table('clients')->where('id', $client->id)->update(['site_id' => $foreign->id]);
        } elseif ($shape === 'withdrawn site') {
            HrEmployeeProfile::where('user_id', $this->worker->id)->update(['primary_site_id' => null]);
        } else {
            $other = $this->staff($this->site);
            DB::table('shifts')->where('id', $shift->id)->update(['user_id' => $other->id]);
        }
        $response = $this->page();
        $this->assertSame($entry->id, $response->inertiaProps('todayEntries.0.id'));
        $this->assertSame('7.50', $response->inertiaProps('todayEntries.0.total_hours'));
        $this->assertSame('Retained own notes', $response->inertiaProps('todayEntries.0.notes'));
        $this->assertNull($response->inertiaProps('todayEntries.0.shift'));
        $this->assertNull($response->inertiaProps('todayEntries.0.client_name'));
        $this->assertSame([], $response->inertiaProps('upcomingShifts'));
        $this->assertSame([], array_merge(...array_column($response->inertiaProps('weekRoster'), 'shifts')));
        $this->actingAs($this->worker)->get($this->calendar($shift))->assertNotFound();
    }

    public static function deniedSources(): array
    {
        return [['foreign site'], ['client conflict'], ['withdrawn site'], ['other owner']];
    }

    public function test_active_entry_remains_an_hr_entry_and_foreign_related_records_are_withheld(): void
    {
        $foreign = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $client = Client::factory()->create(['site_id' => $foreign->id]);
        $shift = $this->shift('2026-10-21 13:00', '2026-10-21 18:00', ['site_id' => $foreign->id, 'client_id' => $client->id]);
        $entry = $this->entry(['clock_out' => null, 'total_hours' => null, 'status' => 'draft', 'shift_id' => $shift->id, 'client_id' => $client->id]);
        $response = $this->page();
        $this->assertSame($entry->id, $response->inertiaProps('activeClock.id'));
        $this->assertSame($entry->clock_in->toIso8601String(), $response->inertiaProps('activeClock.clock_in_at'));
        $this->assertNull($response->inertiaProps('activeClock.shift'));
        $this->assertNull($response->inertiaProps('todayEntries.0.clock_out_at'));
    }

    public function test_context_label_is_scoped_even_when_own_shift_is_authorized(): void
    {
        $foreign = Site::factory()->create();
        $context = ServiceContext::factory()->create(['site_id' => $foreign->id, 'name' => 'Foreign private context']);
        $shift = $this->shift('2026-10-21 15:00', '2026-10-21 18:00', ['service_context_id' => $context->id]);
        $response = $this->page();
        $roster = array_merge(...array_column($response->inertiaProps('weekRoster'), 'shifts'));
        $this->assertSame('Recorded location', $roster[0]['site']);
        $this->assertNull($roster[0]['service_context_id']);
        $this->assertNull($response->inertiaProps('upcomingShifts.0.service_context_name'));
        $ics = $this->actingAs($this->worker)->get($this->calendar($shift))->assertOk()->getContent();
        $this->assertStringNotContainsString('Foreign private context', $ics);
    }

    public function test_publication_applies_to_week_upcoming_entry_relation_and_calendar(): void
    {
        config(['features.rostering.publish' => true]);
        AppSetting::query()->updateOrCreate(['key' => 'features.rostering.publish'], ['value' => true]);
        $shift = $this->shift('2026-10-21 15:00', '2026-10-21 18:00');
        $entry = $this->entry(['shift_id' => $shift->id]);
        $response = $this->page();
        $this->assertSame($entry->id, $response->inertiaProps('todayEntries.0.id'));
        $this->assertNull($response->inertiaProps('todayEntries.0.shift'));
        $this->assertSame([], $response->inertiaProps('upcomingShifts'));
        $this->actingAs($this->worker)->get($this->calendar($shift))->assertNotFound();
        DB::table('shifts')->where('id', $shift->id)->update(['published_at' => now()]);
        $this->assertSame($shift->id, $this->page()->inertiaProps('upcomingShifts.0.id'));
        $this->actingAs($this->worker)->get($this->calendar($shift))->assertOk();
    }

    #[DataProvider('invalidIntervals')]
    public function test_invalid_intervals_do_not_export_a_fabricated_calendar(string $shape): void
    {
        $shift = $this->shift('2026-10-21 15:00', '2026-10-21 18:00');
        if ($shape === 'missing') {
            // Actual schema forbids NULL; exercise a missing retained projection.
            Shift::retrieved(function (Shift $row) use ($shift) { if ($row->id === $shift->id) $row->ends_at = null; });
        } else DB::table('shifts')->where('id', $shift->id)->update(['ends_at' =>
            $shape === 'equal' ? $shift->starts_at : $shift->starts_at->copy()->subMinute()]);
        $this->actingAs($this->worker)->get($this->calendar($shift))->assertNotFound();
        $this->assertSame([], $this->page()->inertiaProps('upcomingShifts'));
    }

    public static function invalidIntervals(): array { return [['missing'], ['equal'], ['reversed']]; }

    public function test_calendar_uses_actual_overnight_utc_bounds_and_escapes_labels(): void
    {
        $this->context->update(['name' => 'Whare, North; Care']);
        $shift = $this->shift('2026-10-21 23:00', '2026-10-22 07:00');
        $response = $this->actingAs($this->worker)->get($this->calendar($shift))->assertOk();
        $response->assertHeader('Content-Type', 'text/calendar; charset=utf-8');
        $this->assertStringContainsString('DTSTART:20261021T100000Z', $response->getContent());
        $this->assertStringContainsString('DTEND:20261021T180000Z', $response->getContent());
        $this->assertStringContainsString('SUMMARY:Whare\, North\; Care', $response->getContent());
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_actual_related_label_withdrawal_keeps_own_fact_but_retires_related_shift(): void
    {
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $shift = $this->shift('2026-10-21 15:00', '2026-10-21 18:00', ['client_id' => $client->id]);
        $entry = $this->entry(['shift_id' => $shift->id, 'client_id' => $client->id]);
        $reached = false;
        Client::retrieved(function ($row) use ($client, &$reached) {
            if ($reached || $row->id !== $client->id) return;
            if (! collect(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS))->contains(fn ($frame) => ($frame['function'] ?? '') === 'myHrTimeShiftData')) return;
            $reached = true;
            HrEmployeeProfile::where('user_id', $this->worker->id)->update(['primary_site_id' => null]);
        });
        $response = $this->page();
        $this->assertTrue($reached);
        $this->assertSame($entry->id, $response->inertiaProps('todayEntries.0.id'));
        $this->assertNull($response->inertiaProps('todayEntries.0.shift'));
        $this->assertSame([], $response->inertiaProps('upcomingShifts'));
        $this->assertSame([], array_merge(...array_column($response->inertiaProps('weekRoster'), 'shifts')));
    }

    private function at(string $local): Carbon { return Carbon::parse($local, 'Pacific/Auckland')->utc(); }
    private function page() { return $this->actingAs($this->worker)->get('/hr/my/time')->assertOk(); }
    private function calendar(Shift $shift): string { return '/hr/my/time/shifts/'.$shift->id.'/calendar'; }

    private function staff(Site $site): User
    {
        $worker = User::factory()->create(['role' => 'hr_self_only_fixture', 'approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $worker->id, 'primary_site_id' => $site->id,
            'secondary_site_ids' => [], 'is_active' => true, 'start_date' => '2026-01-01', 'end_date' => null]);
        return $worker;
    }

    /** shifts.client_id is required, so default fixtures use one Client at the same Site. */
    private function siteClient(): Client
    {
        return $this->siteClient ??= Client::factory()->create(['site_id' => $this->site->id]);
    }

    private function shift(string $start, string $end, array $extra = []): Shift
    {
        return Shift::create(['user_id' => $this->worker->id, 'site_id' => $this->site->id,
            'client_id' => array_key_exists('client_id', $extra) ? $extra['client_id'] : $this->siteClient()->id,
            'service_context_id' => $this->context->id, 'starts_at' => $this->at($start), 'ends_at' => $this->at($end),
            'location' => 'Recorded location', 'shift_type' => 'standard', 'status' => 'scheduled',
            'created_by' => $this->worker->id, ...$extra]);
    }

    private function entry(array $extra = []): HrTimeEntry
    {
        return HrTimeEntry::factory()->create(['user_id' => $this->worker->id, 'created_by' => $this->worker->id,
            'site_id' => $this->site->id, 'entry_date' => '2026-10-21', 'clock_in' => $this->at('2026-10-21 09:00'),
            'clock_out' => $this->at('2026-10-21 17:00'), 'break_minutes' => 30, 'total_hours' => '7.50',
            'status' => 'submitted', 'notes' => 'Retained own notes', ...$extra]);
    }
}
