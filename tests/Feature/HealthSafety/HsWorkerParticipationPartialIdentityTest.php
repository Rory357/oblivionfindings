<?php

namespace Tests\Feature\HealthSafety;

use App\Models\HsRepresentative;
use App\Models\HsCommittee;
use App\Models\HsCommitteeMeeting;
use App\Models\HsConsultation;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Incidents\IncidentRoutingTestCase;

/** Native partial response identity; approved-Site/source tests remain a separate required suite. */
final class HsWorkerParticipationPartialIdentityTest extends IncidentRoutingTestCase
{
    private const BASE = '/health-safety/worker-participation';
    private const PRIVATE_PROPS = ['participation_viewer_id', 'filters', 'tab', 'tabCounts', 'rows', 'hero', 'detail', 'can', 'sites', 'staff', 'committees'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->allow($this->manager, 'hazards.view');
        $this->allow($this->manager, 'hazards.manage');
        $this->allow($this->reporter, 'hazards.view');
        $this->allow($this->reporter, 'hazards.manage', false);
    }

    public function test_detail_only_partial_refreshes_the_entire_private_cluster_with_its_source_identity(): void
    {
        $row = $this->rep();
        $response = $this->partial($this->manager, ['representative' => $row->id]);
        $this->assertCluster($response, $this->manager->id);
        $response->assertJsonPath('props.detail.kind', 'representative')->assertJsonPath('props.detail.data.id', $row->id)
            ->assertJsonPath('props.rows.data.0.id', $row->id)->assertJsonPath('props.can.manage', true);
    }

    public function test_partial_except_cannot_exclude_identity_or_rebind_old_private_props(): void
    {
        $this->rep();
        $headers = ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'health-safety/worker-participation/index',
            'X-Inertia-Partial-Except' => implode(',', self::PRIVATE_PROPS)];
        $response = $this->actingAs($this->reporter)->get(self::BASE.'?site_id='.$this->site->id.'&period=all', $headers);
        $this->assertCluster($response, $this->reporter->id);
        $response->assertJsonPath('props.can.manage', false)->assertJsonPath('props.detail', null);
    }

    public function test_close_partial_keeps_focus_and_site_and_clears_the_previous_detail(): void
    {
        $row = $this->rep();
        $query = ['focus' => 'training_below_two', 'representative' => $row->id];
        $this->partial($this->manager, $query)->assertOk()->assertJsonPath('props.detail.data.id', $row->id);
        unset($query['representative']);
        $response = $this->partial($this->manager, $query);
        $this->assertCluster($response, $this->manager->id);
        $response->assertJsonPath('props.detail', null)->assertJsonPath('props.filters.site_id', $this->site->id)
            ->assertJsonPath('props.filters.focus', 'training_below_two')->assertJsonPath('props.rows.data.0.id', $row->id);
    }

    public function test_warmed_manage_permission_is_refreshed_for_the_partial_private_cluster(): void
    {
        $this->rep();
        $this->assertTrue($this->manager->canDo('hazards.manage'));
        DB::table('permission_user')->where('user_id', $this->manager->id)
            ->where('permission_id', Permission::where('key', 'hazards.manage')->sole()->id)->update(['allowed' => false]);
        $response = $this->partial($this->manager);
        $this->assertCluster($response, $this->manager->id);
        $response->assertJsonPath('props.can.manage', false);
    }

    public static function invalidDownloadIds(): array
    {
        return [['0'], ['-1'], ['not-a-record'], ['1.5'], ['1e3'], [str_repeat('9', 40)]];
    }

    #[DataProvider('invalidDownloadIds')]
    public function test_malformed_or_overflow_download_ids_are_not_found_before_record_hydration(string $id): void
    {
        $dispatcher = Model::getEventDispatcher();
        Model::setEventDispatcher(clone $dispatcher);
        $hydrated = [];
        HsCommitteeMeeting::retrieved(function ($row) use (&$hydrated): void { $hydrated[] = ['meeting', $row->id]; });
        HsConsultation::retrieved(function ($row) use (&$hydrated): void { $hydrated[] = ['consultation', $row->id]; });
        try {
            foreach (['/meetings/'.$id.'/minutes/download', '/consultations/'.$id.'/documents/document', '/consultations/'.$id.'/documents/outcome'] as $suffix) {
                $this->actingAs($this->manager)->get(self::BASE.$suffix)->assertNotFound();
            }
            $this->assertSame([], $hydrated);
        } finally { Model::setEventDispatcher($dispatcher); }
    }

    public function test_leading_zero_decimal_download_id_preserves_the_original_direct_url(): void
    {
        Storage::fake('private');
        $committee = HsCommittee::create(['name' => 'Approved committee', 'site_id' => $this->site->id, 'established_at' => '2026-01-01']);
        $meeting = HsCommitteeMeeting::create(['hs_committee_id' => $committee->id, 'scheduled_at' => '2026-10-11 00:30:00', 'status' => 'completed']);
        $path = 'health-safety/meetings/'.$meeting->id.'/minutes.txt';
        $meeting->update(['minutes_document_path' => $path, 'minutes_document_name' => 'Recorded minutes.txt']);
        Storage::disk('private')->put($path, 'PRIVATE RECORDED MINUTES');
        $response = $this->actingAs($this->manager)->get(self::BASE.'/meetings/000'.$meeting->id.'/minutes/download')->assertOk();
        $this->assertSame('PRIVATE RECORDED MINUTES', $response->streamedContent());
    }

    private function partial(User $viewer, array $query = []): TestResponse
    {
        return $this->actingAs($viewer)->get(self::BASE.'?'.http_build_query(['tab' => 'representatives', 'site_id' => $this->site->id, 'period' => 'all', ...$query]),
            ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'health-safety/worker-participation/index', 'X-Inertia-Partial-Data' => 'detail']);
    }

    private function assertCluster(TestResponse $response, int $viewerId): void
    {
        $response->assertOk()->assertJsonPath('component', 'health-safety/worker-participation/index')->assertJsonPath('props.participation_viewer_id', $viewerId);
        foreach (self::PRIVATE_PROPS as $key) { $this->assertArrayHasKey($key, $response->json('props')); }
    }

    private function rep(): HsRepresentative
    {
        return HsRepresentative::create(['user_id' => $this->reporter->id, 'site_id' => $this->site->id, 'status' => 'active',
            'election_method' => 'elected', 'elected_at' => '2026-10-01', 'training_days_completed' => 1, 'created_by' => $this->manager->id]);
    }
}
