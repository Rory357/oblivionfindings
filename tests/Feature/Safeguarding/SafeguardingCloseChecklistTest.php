<?php

namespace Tests\Feature\Safeguarding;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\Role;
use App\Models\SafeguardingConcern;
use App\Models\SafeguardingExternalReport;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\HsEventClosureService;
use App\Services\HealthSafety\HsEventService;
use App\Services\Safeguarding\SafeguardingCloseChecklist;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Readiness audit B5 (Stephan, 6 Nov 2026: "Keep checks, say what to close"). A new concern can't end as "No further
 * action" until the safety review and Control Room alert it made for itself are closed, and any flagged referral is
 * logged. The concern's detail now lists exactly those, each with a link; the checks themselves are unchanged.
 */
final class SafeguardingCloseChecklistTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_a_new_concern_lists_its_own_review_alert_and_referral_with_links(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $concern = SafeguardingConcern::factory()->create(['site_id' => $site->id, 'requires_external_referral' => true]);
        [$event, $alert] = $this->journey($concern, $site);

        $items = collect(app(SafeguardingCloseChecklist::class)->forConcern($concern))->keyBy('key');

        $this->assertSame(['hs_event', 'control_room', 'external_referral'], $items->keys()->all());
        $this->assertFalse($items['hs_event']['done']);
        $this->assertSame('/health-safety/events/'.$event->id, $items['hs_event']['href']);
        $this->assertStringContainsString($event->reference_number, $items['hs_event']['label']);
        $this->assertFalse($items['control_room']['done']);
        $this->assertSame('/control-room/alerts/'.$alert->id, $items['control_room']['href']);
        $this->assertFalse($items['external_referral']['done']);
    }

    public function test_closed_review_resolved_alert_and_logged_referral_read_as_done(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $actor = User::factory()->create(['approved_at' => now()]);
        $actor->roles()->syncWithoutDetaching([Role::query()->where('name', 'health_safety_officer')->firstOrFail()->id]);
        HrEmployeeProfile::factory()->create(['user_id' => $actor->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
            'position_role' => 'health_safety_officer', 'is_active' => true, 'start_date' => now()->subYear()->toDateString()]);
        $actor = $actor->fresh();
        $concern = SafeguardingConcern::factory()->create(['site_id' => $site->id, 'requires_external_referral' => true]);
        [$event, $alert] = $this->journey($concern, $site);
        $event->forceFill(['site_id' => $site->id, 'owner_user_id' => $actor->id, 'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED,
            'investigation_required' => false, 'worksafe_status' => null])->save();
        $alert->forceFill(['site_id' => $site->id, 'source' => 'safeguarding', 'status' => ControlRoomAlert::STATUS_RESOLVED,
            'resolved_at' => now(), 'resolved_by_user_id' => $actor->id, 'resolution_code' => 'safeguarding_response_complete'])->save();
        $this->actingAs($actor);
        $event = app(HsEventService::class)->recordWorksafeDecision($event->fresh(), false, 'Assessed as not meeting the WorkSafe notification threshold.', $actor);
        app(HsEventClosureService::class)->closeEvent($event->fresh(), 'Reviewed; no further H&S action.', $actor);
        SafeguardingExternalReport::query()->create(['safeguarding_concern_id' => $concern->id, 'authority_type' => 'police',
            'authority_name' => 'NZ Police', 'reported_at' => now(), 'report_method' => 'phone', 'reported_by_user_id' => $actor->id, 'report_summary' => 'Reported by phone.']);

        $items = app(SafeguardingCloseChecklist::class)->forConcern($concern->fresh());

        $this->assertNotSame([], $items);
        $this->assertSame([true], array_values(array_unique(array_column($items, 'done'))));
    }

    /** @return array{HsEvent, ControlRoomAlert} */
    private function journey(SafeguardingConcern $concern, Site $site): array
    {
        $event = HsEvent::query()->where('source_type', SafeguardingConcern::class)->where('source_id', $concern->id)->sole();
        $alert = $event->control_room_alert_id ? ControlRoomAlert::query()->findOrFail($event->control_room_alert_id)
            : ControlRoomAlert::factory()->create(['site_id' => $site->id, 'status' => ControlRoomAlert::STATUS_OPEN]);
        $alert->forceFill(['context' => [...($alert->context ?? []), 'concern_id' => $concern->id]])->save();
        $event->forceFill(['control_room_alert_id' => $alert->id, 'status' => HsEvent::STATUS_OPEN])->save();

        return [$event->fresh(), $alert->fresh()];
    }
}
