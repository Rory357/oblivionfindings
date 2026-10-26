<?php

namespace Tests\Feature\Incidents;

use App\Models\IncidentOwnerChange;
use App\Models\IncidentRoutingIntent;
use App\Services\Incidents\IncidentJourneyService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Actual outer commits are required: an inner savepoint is not delivery acceptance. */
final class IncidentRoutingSubmissionIntegrationTest extends IncidentRoutingTestCase
{
    protected bool $physicalCommits = true;

    public function test_new_linked_report_stages_atomically_and_routes_only_after_outer_commit(): void
    {
        $this->settings();
        $root = null;
        DB::transaction(function () use (&$root): void {
            $root = $this->incident(['severity' => 'low']);
            app(IncidentJourneyService::class)->ensureForSubmittedIncident($root, $this->reporter);
            $this->assertSame(1, IncidentRoutingIntent::where('incident_id', $root->id)->count());
            $this->assertNull($root->fresh()->incident_owner_user_id);
            $this->assertSame(0, IncidentOwnerChange::where('incident_id', $root->id)->count());
        });
        $this->assertSame($this->lead->id, $root->fresh()->incident_owner_user_id);
        $this->assertSame(1, IncidentOwnerChange::where('incident_id', $root->id)->count());
    }

    public function test_source_rollback_removes_intent_and_cannot_assign_an_owner(): void
    {
        $this->settings();
        $id = null;
        try {
            DB::transaction(function () use (&$id): void {
                $root = $this->incident(['severity' => 'low']);
                $id = $root->id;
                app(IncidentJourneyService::class)->ensureForSubmittedIncident($root, $this->reporter);
                $this->assertSame(1, IncidentRoutingIntent::where('incident_id', $id)->count());
                throw new RuntimeException('Synthetic report rollback');
            });
            $this->fail('The source transaction must roll back.');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic report rollback', $error->getMessage());
        }
        $this->assertDatabaseMissing('client_incidents', ['id' => $id]);
        $this->assertSame(0, IncidentRoutingIntent::where('incident_id', $id)->count());
        $this->assertSame(0, IncidentOwnerChange::where('incident_id', $id)->count());
    }

    public function test_historical_journey_repair_never_backfills_owner_routing(): void
    {
        $this->settings();
        $root = $this->incident(['severity' => 'low', 'submitted_at' => now()->subMonths(2)])->fresh();
        app(IncidentJourneyService::class)->ensureForSubmittedIncident($root, $this->reporter);
        $this->assertNull($root->fresh()->incident_owner_user_id);
        $this->assertSame(0, IncidentRoutingIntent::where('incident_id', $root->id)->count());
    }

    public function test_unconfigured_routing_preserves_committed_report_with_truthful_setup_state(): void
    {
        $root = null;
        DB::transaction(function () use (&$root): void {
            $root = $this->incident(['severity' => 'low']);
            app(IncidentJourneyService::class)->ensureForSubmittedIncident($root, $this->reporter);
        });
        $this->assertSame('submitted', $root->fresh()->status);
        $this->assertNull($root->fresh()->incident_owner_user_id);
        $intent = IncidentRoutingIntent::where('incident_id', $root->id)->sole();
        $this->assertSame('setup_required', $intent->state);
        $this->assertSame(0, IncidentOwnerChange::where('incident_id', $root->id)->count());
    }

    public function test_quiet_draft_submission_uses_explicit_hook_and_replay_preserves_one_assignment(): void
    {
        $this->settings();
        $root = $this->incident(['status' => 'draft', 'submitted_at' => null, 'severity' => 'low'])->fresh();
        DB::transaction(function () use ($root): void {
            $root->forceFill(['status' => 'submitted', 'submitted_at' => now()])->saveQuietly();
            app(IncidentJourneyService::class)->ensureForSubmittedIncident($root->fresh(), $this->reporter, newlySubmitted: true);
            $this->assertNull($root->fresh()->incident_owner_user_id);
        });
        app(IncidentJourneyService::class)->ensureForSubmittedIncident($root->fresh(), $this->reporter, newlySubmitted: true);
        $this->assertSame($this->lead->id, $root->fresh()->incident_owner_user_id);
        $this->assertSame(1, IncidentRoutingIntent::where('incident_id', $root->id)->count());
        $this->assertSame(1, IncidentOwnerChange::where('incident_id', $root->id)->count());
    }
}
