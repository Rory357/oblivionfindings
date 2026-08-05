<?php

namespace Tests\Feature\Console;

use App\Console\Commands\ReportHsWorksafeDecisionCounts;
use App\Models\HsEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReportHsWorksafeDecisionCountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_json_report_returns_exact_decision_lifecycle_counts_without_mutation(): void
    {
        $this->assertTrue(Schema::hasColumn('hs_events', 'worksafe_decided_at'));
        $this->assertTrue(class_exists(ReportHsWorksafeDecisionCounts::class));

        $actor = User::factory()->create();
        HsEvent::factory()->create(['worksafe_notifiable' => null]);
        HsEvent::factory()->create([
            'worksafe_notifiable' => false,
            'worksafe_decided_at' => now(),
            'worksafe_decided_by_user_id' => $actor->id,
            'worksafe_decision_reason' => 'Assessed against the statutory threshold.',
            'worksafe_decision_source' => 'manual',
        ]);
        HsEvent::factory()->worksafeNotifiable($actor)->create();
        HsEvent::factory()->worksafeNotifiable($actor)->create([
            'worksafe_status' => HsEvent::WORKSAFE_NOTIFIED,
            'worksafe_notified_at' => now(),
            'worksafe_method' => 'online',
        ]);
        HsEvent::factory()->worksafeNotifiable($actor)->create([
            'worksafe_status' => HsEvent::WORKSAFE_ACKNOWLEDGED,
            'worksafe_notified_at' => now()->subHour(),
            'worksafe_method' => 'online',
            'worksafe_acknowledged_at' => now(),
        ]);
        HsEvent::factory()->closed()->create([
            'worksafe_notifiable' => false,
            'worksafe_decided_at' => null,
            'worksafe_decided_by_user_id' => null,
            'worksafe_decision_reason' => null,
            'worksafe_decision_source' => null,
        ]);

        $before = HsEvent::query()->orderBy('id')->get()->map->getAttributes()->all();

        $this->artisan('health-safety:worksafe-decision-counts', ['--json' => true])
            ->expectsOutput(json_encode([
                'undecided' => 1,
                'explicit_not_notifiable' => 1,
                'notifiable_pending' => 1,
                'notified' => 1,
                'acknowledged' => 1,
                'closed_legacy_false' => 1,
                'inconsistent' => 0,
            ], JSON_UNESCAPED_SLASHES))
            ->assertExitCode(0);

        $after = HsEvent::query()->orderBy('id')->get()->map->getAttributes()->all();

        $this->assertSame($before, $after);
    }

    public function test_report_fails_when_notification_state_contradicts_the_decision(): void
    {
        $this->assertTrue(Schema::hasColumn('hs_events', 'worksafe_decided_at'));
        $this->assertTrue(class_exists(ReportHsWorksafeDecisionCounts::class));

        $event = HsEvent::factory()->create();
        DB::table('hs_events')->where('id', $event->id)->update([
            'worksafe_notifiable' => false,
            'worksafe_status' => HsEvent::WORKSAFE_NOTIFIED,
            'worksafe_notified_at' => now(),
            'worksafe_method' => 'online',
        ]);

        $this->artisan('health-safety:worksafe-decision-counts', ['--json' => true])
            ->assertExitCode(1);
    }
}
