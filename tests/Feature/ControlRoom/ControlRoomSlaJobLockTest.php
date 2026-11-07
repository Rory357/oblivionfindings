<?php

namespace Tests\Feature\ControlRoom;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Jobs\CheckControlRoomSlaBreaches;
use App\Models\ControlRoom\AlertSla;
use App\Models\ControlRoom\Communication;
use App\Models\ControlRoom\SlaDefinition;
use App\Models\ControlRoomAlert;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Notifications\ControlRoomAlertNotification;
use App\Services\ControlRoom\AlertAutomationService;
use App\Services\ControlRoom\ControlRoomNotificationService;
use Database\Seeders\RbacSeeder;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Readiness audit O2 (Stephan, 6 Nov 2026, by pop-up via MASTER: "Yes, H&S builds it"). The Control Room SLA job now
 * locks only SLAs that are due, loads role holders once, sends its notices after each escalation commits, and never
 * runs twice at once. These tests prove the job escalates exactly the alerts the old full scan did, and tells the same
 * people.
 */
final class ControlRoomSlaJobLockTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $coordinator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['name' => 'Kauri House']);
        $this->coordinator = User::factory()->create(['role' => 'coordinator', 'approved_at' => now()]);
        $this->coordinator->roles()->attach(Role::where('name', 'coordinator')->firstOrFail());
        HrEmployeeProfile::factory()->create(['user_id' => $this->coordinator->id, 'primary_site_id' => $this->site->id,
            'secondary_site_ids' => []]);
    }

    private function definition(array $facts = []): SlaDefinition
    {
        static $n = 0;
        $n++;

        return SlaDefinition::query()->create([
            'name' => "SLA {$n}",
            'code' => "sla-lock-test-{$n}",
            'acknowledge_target_minutes' => 60,
            'response_target_minutes' => 120,
            'resolution_target_minutes' => 240,
            'escalate_on_acknowledge_breach' => true,
            'escalate_on_response_breach' => true,
            'escalate_on_resolution_breach' => true,
            'breach_notify_roles' => ['coordinator'],
            'is_active' => true,
            ...$facts,
        ]);
    }

    /** An open alert at the Site whose SLA cycle started $minutesAgo minutes ago. */
    private function alertWithSla(int $minutesAgo, ?SlaDefinition $definition = null, array $slaFacts = []): array
    {
        $alert = ControlRoomAlert::factory()->open()->create([
            'source' => 'external', 'alert_type' => 'SOS Button', 'severity' => 'critical', 'client_id' => null,
            'context' => [], 'site_id' => $this->site->id, 'triggered_at' => now()->subMinutes($minutesAgo),
            'escalation_level' => 0, 'escalated_at' => null,
        ]);
        $sla = AlertSla::createFromDefinition($alert, $definition ?? $this->definition());
        if ($slaFacts !== []) {
            $sla->forceFill($slaFacts)->save();
        }

        return [$alert, $sla->fresh()];
    }

    /** The old job's decision, computed without the pre-filter: every applicable open SLA on an actionable alert. */
    private function oldFullScanDecision(): array
    {
        $now = now();

        return AlertSla::query()->applicable()->whereHas('alert', fn ($q) => $q->actionable())->whereNull('resolved_at')
            ->get()->filter(function (AlertSla $sla) use ($now): bool {
                $definition = $sla->slaDefinition()->first();
                $due = fn ($done, $deadline, $breached) => ! $sla->{$done} && $sla->{$deadline} && $now->gt($sla->{$deadline}) && ! $sla->{$breached};

                return $definition !== null && (
                    ($due('acknowledged_at', 'acknowledge_deadline', 'acknowledge_breached') && $definition->escalate_on_acknowledge_breach)
                    || ($due('responded_at', 'response_deadline', 'response_breached') && $definition->escalate_on_response_breach)
                    || ($due('resolved_at', 'resolution_deadline', 'resolution_breached') && $definition->escalate_on_resolution_breach));
            })->pluck('alert_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    }

    private function runJob(): array
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = ['sql' => strtolower($query->sql), 'bindings' => $query->bindings];
        });
        app(CheckControlRoomSlaBreaches::class)->handle(app(ControlRoomNotificationService::class), app(AlertAutomationService::class));

        return $queries;
    }

    public function test_it_escalates_exactly_the_alerts_the_old_full_scan_did_and_tells_the_same_people(): void
    {
        Notification::fake();
        [$idle] = $this->alertWithSla(1);                                         // nothing due for an hour
        [$overdueAck] = $this->alertWithSla(90);                                  // acknowledge overdue
        [$overdueAll] = $this->alertWithSla(300);                                 // every milestone overdue
        [$alreadyTold] = $this->alertWithSla(90, null, ['acknowledge_breached' => true]);
        [$acknowledged] = $this->alertWithSla(90, null, ['acknowledged_at' => now()->subMinutes(80)]);
        [$noEscalation] = $this->alertWithSla(90, $this->definition([
            'escalate_on_acknowledge_breach' => false, 'escalate_on_response_breach' => false, 'escalate_on_resolution_breach' => false,
        ]));
        [$nearlyDue] = $this->alertWithSla(55);                                   // due in 5 minutes: inside the margin

        $expected = $this->oldFullScanDecision();
        $this->assertSame(collect([$overdueAck->id, $overdueAll->id])->sort()->values()->all(), $expected);

        $queries = $this->runJob();

        $escalated = ControlRoomAlert::query()->where('escalation_level', '>', 0)->pluck('id')->map(fn ($id) => (int) $id)
            ->sort()->values()->all();
        $this->assertSame($expected, $escalated);
        foreach ([$idle, $alreadyTold, $acknowledged, $noEscalation, $nearlyDue] as $quiet) {
            $this->assertSame(0, (int) $quiet->fresh()->escalation_level);
        }

        // The same people are told, once per escalation, after the escalation committed.
        Notification::assertSentToTimes($this->coordinator, ControlRoomAlertNotification::class, 2);
        $this->assertSame(2, Communication::query()->where('purpose', 'escalation')->where('status', 'sent')
            ->where('target_user_id', $this->coordinator->id)->count());
        $this->assertSame(0, Communication::query()->where('purpose', 'escalation')->where('status', '!=', 'sent')->count());

        // An SLA with nothing due is never locked.
        $lockedIdle = collect($queries)->filter(fn ($q) => str_contains($q['sql'], 'for update')
            && str_contains($q['sql'], 'control_room_alerts') && in_array($idle->id, $q['bindings'], false));
        $this->assertCount(0, $lockedIdle);

        // A second run escalates nothing more and sends nothing more.
        $this->runJob();
        Notification::assertSentToTimes($this->coordinator, ControlRoomAlertNotification::class, 2);
        $this->assertSame($expected, ControlRoomAlert::query()->where('escalation_level', '>', 0)->pluck('id')
            ->map(fn ($id) => (int) $id)->sort()->values()->all());
    }

    public function test_a_coordinator_at_another_site_is_not_told(): void
    {
        Notification::fake();
        $elsewhere = User::factory()->create(['role' => 'coordinator', 'approved_at' => now()]);
        $elsewhere->roles()->attach(Role::where('name', 'coordinator')->firstOrFail());
        HrEmployeeProfile::factory()->create(['user_id' => $elsewhere->id, 'primary_site_id' => Site::factory()->create()->id,
            'secondary_site_ids' => []]);
        $this->alertWithSla(90);

        $this->runJob();

        Notification::assertSentToTimes($this->coordinator, ControlRoomAlertNotification::class, 1);
        Notification::assertNotSentTo($elsewhere, ControlRoomAlertNotification::class);
    }

    public function test_two_runs_never_overlap(): void
    {
        $middleware = (new CheckControlRoomSlaBreaches)->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
    }
}
