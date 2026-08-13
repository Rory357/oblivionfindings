<?php

namespace Tests\Feature\HealthSafety;

use App\Models\AuditLog;
use App\Models\HsEvent;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\HsEventService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WorkSafe NZ notification workflow (E-Gap 2) — record notification
 * (pending → notified, date/method/reference + site preservation) and
 * acknowledgement (notified → acknowledged), with guards + permission gating.
 */
class HsEventWorksafeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    protected function hsOfficer(): User
    {
        $user = User::factory()->create(['role' => 'health_safety_officer', 'approved_at' => now()]);
        if ($role = Role::where('name', 'health_safety_officer')->first()) {
            $user->roles()->attach($role);
        }

        return $user;
    }

    public function test_record_notification_transitions_pending_to_notified(): void
    {
        $event = HsEvent::factory()->worksafeNotifiable()->create();

        $this->actingAs($this->hsOfficer())
            ->from('/health-safety/events')
            ->post("/health-safety/events/{$event->id}/worksafe/notify", [
                'notified_at' => '2026-06-18',
                'method' => 'phone',
                'reference' => 'WS-2026-0099',
                'site_preserved' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $event->refresh();
        $this->assertEquals(HsEvent::WORKSAFE_NOTIFIED, $event->worksafe_status);
        $this->assertNotNull($event->worksafe_notified_at);
        $this->assertEquals('phone', $event->worksafe_method);
        $this->assertEquals('WS-2026-0099', $event->worksafe_reference);
        $this->assertTrue($event->worksafe_site_preserved);
        $this->assertSame(HsEvent::SITE_PRESERVATION_ACTIVE, $event->worksafe_site_preservation_status);
        $this->assertNotNull($event->worksafe_site_preservation_decided_at);
        $this->assertNotNull($event->worksafe_site_preservation_decided_by_user_id);
    }

    public function test_acknowledge_transitions_notified_to_acknowledged(): void
    {
        $event = HsEvent::factory()->worksafeNotifiable()->create([
            'worksafe_status' => HsEvent::WORKSAFE_NOTIFIED,
            'worksafe_notified_at' => now(),
            'worksafe_method' => 'online',
        ]);

        $this->actingAs($this->hsOfficer())
            ->from('/health-safety/events')
            ->post("/health-safety/events/{$event->id}/worksafe/acknowledge", [
                'acknowledged_at' => '2026-06-19',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $event->refresh();
        $this->assertEquals(HsEvent::WORKSAFE_ACKNOWLEDGED, $event->worksafe_status);
        $this->assertNotNull($event->worksafe_acknowledged_at);
    }

    public function test_cannot_notify_a_non_notifiable_event(): void
    {
        $actor = $this->hsOfficer();
        $event = HsEvent::factory()->worksafeNotNotifiable($actor)->create();

        $this->actingAs($actor)
            ->from('/health-safety/events')
            ->post("/health-safety/events/{$event->id}/worksafe/notify", [
                'notified_at' => '2026-06-18',
                'method' => 'phone',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNull($event->fresh()->worksafe_notified_at);
    }

    public function test_cannot_notify_an_undecided_event(): void
    {
        $event = HsEvent::factory()->worksafeUndecided()->create();

        $this->actingAs($this->hsOfficer())
            ->from('/health-safety/events')
            ->post("/health-safety/events/{$event->id}/worksafe/notify", [
                'notified_at' => '2026-06-18',
                'method' => 'phone',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNull($event->fresh()->worksafe_notified_at);
    }

    public function test_cannot_acknowledge_before_notifying(): void
    {
        $event = HsEvent::factory()->worksafeNotifiable()->create(); // pending

        $this->actingAs($this->hsOfficer())
            ->from('/health-safety/events')
            ->post("/health-safety/events/{$event->id}/worksafe/acknowledge", [
                'acknowledged_at' => '2026-06-18',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNotEquals(HsEvent::WORKSAFE_ACKNOWLEDGED, $event->fresh()->worksafe_status);
    }

    public function test_notify_requires_method_and_date(): void
    {
        $event = HsEvent::factory()->worksafeNotifiable()->create();

        $this->actingAs($this->hsOfficer())
            ->from('/health-safety/events')
            ->post("/health-safety/events/{$event->id}/worksafe/notify", [])
            ->assertSessionHasErrors(['notified_at', 'method']);
    }

    public function test_worksafe_actions_require_hazards_manage(): void
    {
        $event = HsEvent::factory()->worksafeNotifiable()->create();
        $user = User::factory()->create(['approved_at' => now()]);

        $this->actingAs($user)
            ->post("/health-safety/events/{$event->id}/worksafe/notify", [
                'notified_at' => '2026-06-18',
                'method' => 'phone',
            ])
            ->assertForbidden();
    }

    public function test_records_an_explicit_notifiable_decision_with_metadata_and_audit(): void
    {
        $actor = $this->hsOfficer();
        $event = HsEvent::factory()->worksafeUndecided()->create();

        $this->actingAs($actor)
            ->from("/health-safety/events?event={$event->id}")
            ->post("/health-safety/events/{$event->id}/worksafe/decision", [
                'notifiable' => true,
                'reason' => 'The reported hospital admission meets the statutory notification threshold.',
                'source' => 'manual',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $event->refresh();
        $this->assertTrue($event->worksafe_notifiable);
        $this->assertSame(HsEvent::WORKSAFE_PENDING, $event->worksafe_status);
        $this->assertSame($actor->id, $event->worksafe_decided_by_user_id);
        $this->assertNotNull($event->worksafe_decided_at);
        $this->assertSame(
            'The reported hospital admission meets the statutory notification threshold.',
            $event->worksafe_decision_reason,
        );
        $this->assertSame('manual', $event->worksafe_decision_source);

        $audit = AuditLog::query()
            ->where('action', 'healthSafety.event.worksafeDecisionRecorded')
            ->where('auditable_type', $event->getMorphClass())
            ->where('auditable_id', $event->id)
            ->firstOrFail();

        $this->assertSame($actor->id, $audit->user_id);
        $this->assertNull($audit->meta['before']['notifiable']);
        $this->assertTrue($audit->meta['after']['notifiable']);
        $this->assertSame(HsEvent::WORKSAFE_PENDING, $audit->meta['after']['status']);
    }

    public function test_decision_requires_an_open_event_with_handover_ready_for_hs(): void
    {
        $actor = $this->hsOfficer();
        $awaiting = HsEvent::factory()->worksafeUndecided()->create([
            'handover_status' => HsEvent::HANDOVER_AWAITING_ACCEPTANCE,
        ]);
        $closed = HsEvent::factory()->worksafeUndecided()->closed()->create([
            'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED,
        ]);
        $payload = [
            'notifiable' => false,
            'reason' => 'The documented assessment does not meet the statutory threshold.',
            'source' => 'manual',
        ];

        $this->actingAs($actor)
            ->post("/health-safety/events/{$awaiting->id}/worksafe/decision", $payload)
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'Accept the H&S handover'));

        $this->actingAs($actor)
            ->post("/health-safety/events/{$closed->id}/worksafe/decision", $payload)
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'closed H&S event'));

        $this->assertNull($awaiting->fresh()->worksafe_notifiable);
        $this->assertNull($closed->fresh()->worksafe_notifiable);
    }

    public function test_notification_and_acknowledgement_can_progress_after_internal_closure(): void
    {
        $actor = $this->hsOfficer();
        $closedPending = HsEvent::factory()->worksafeNotifiable($actor)->closed()->create([
            'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED,
        ]);
        $closedNotified = HsEvent::factory()->worksafeNotifiable($actor)->closed()->create([
            'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED,
            'worksafe_status' => HsEvent::WORKSAFE_NOTIFIED,
            'worksafe_notified_at' => now(),
            'worksafe_method' => 'online',
        ]);

        $this->actingAs($actor)
            ->post("/health-safety/events/{$closedPending->id}/worksafe/notify", [
                'notified_at' => '2026-06-18',
                'method' => 'phone',
            ])
            ->assertSessionHas('success');

        $this->actingAs($actor)
            ->post("/health-safety/events/{$closedNotified->id}/worksafe/acknowledge", [
                'acknowledged_at' => '2026-06-19',
            ])
            ->assertSessionHas('success');

        $this->assertSame(HsEvent::WORKSAFE_NOTIFIED, $closedPending->fresh()->worksafe_status);
        $this->assertNotNull($closedPending->fresh()->worksafe_notified_at);
        $this->assertSame(HsEvent::WORKSAFE_ACKNOWLEDGED, $closedNotified->fresh()->worksafe_status);
        $this->assertNotNull($closedNotified->fresh()->worksafe_acknowledged_at);
    }

    public function test_retrying_the_same_decision_is_idempotent_and_audited_once(): void
    {
        $actor = $this->hsOfficer();
        $event = HsEvent::factory()->worksafeUndecided()->create();
        $payload = [
            'notifiable' => false,
            'reason' => 'The review found no death, serious injury, illness, or dangerous incident.',
            'source' => 'manual',
        ];

        $this->actingAs($actor)
            ->post("/health-safety/events/{$event->id}/worksafe/decision", $payload)
            ->assertSessionHas('success');

        $decidedAt = $event->fresh()->worksafe_decided_at;

        $this->travel(5)->minutes();

        $this->actingAs($actor)
            ->post("/health-safety/events/{$event->id}/worksafe/decision", $payload)
            ->assertSessionHas('success');

        $this->assertTrue($decidedAt->equalTo($event->fresh()->worksafe_decided_at));
        $this->assertSame(1, AuditLog::query()
            ->where('action', 'healthSafety.event.worksafeDecisionRecorded')
            ->where('auditable_type', $event->getMorphClass())
            ->where('auditable_id', $event->id)
            ->count());
    }

    public function test_setting_false_clears_pending_only_state(): void
    {
        $actor = $this->hsOfficer();
        $event = HsEvent::factory()->worksafeNotifiable($actor)->create([
            'worksafe_reference' => 'DRAFT-REFERENCE',
            'worksafe_method' => 'online',
            'worksafe_site_preserved' => true,
        ]);

        $this->actingAs($actor)
            ->post("/health-safety/events/{$event->id}/worksafe/decision", [
                'notifiable' => false,
                'reason' => 'A documented review confirmed the statutory notification threshold is not met.',
                'source' => 'manual',
            ])
            ->assertSessionHas('success');

        $event->refresh();
        $this->assertFalse($event->worksafe_notifiable);
        $this->assertNull($event->worksafe_status);
        $this->assertNull($event->worksafe_reference);
        $this->assertNull($event->worksafe_method);
        $this->assertFalse($event->worksafe_site_preserved);
    }

    public function test_notified_event_cannot_be_changed_to_not_notifiable(): void
    {
        $actor = $this->hsOfficer();
        $event = HsEvent::factory()->worksafeNotifiable($actor)->create([
            'worksafe_status' => HsEvent::WORKSAFE_NOTIFIED,
            'worksafe_notified_at' => now(),
            'worksafe_method' => 'online',
            'worksafe_reference' => 'WS-LOCKED-001',
        ]);

        $this->actingAs($actor)
            ->post("/health-safety/events/{$event->id}/worksafe/decision", [
                'notifiable' => false,
                'reason' => 'This attempted revision must not erase a completed notification.',
                'source' => 'manual',
            ])
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'cannot be changed'));

        $event->refresh();
        $this->assertTrue($event->worksafe_notifiable);
        $this->assertSame(HsEvent::WORKSAFE_NOTIFIED, $event->worksafe_status);
        $this->assertSame('WS-LOCKED-001', $event->worksafe_reference);
    }

    public function test_decision_requires_choice_reason_and_supported_source(): void
    {
        $event = HsEvent::factory()->worksafeUndecided()->create();

        $this->actingAs($this->hsOfficer())
            ->post("/health-safety/events/{$event->id}/worksafe/decision", [
                'reason' => 'short',
                'source' => 'legacy',
            ])
            ->assertSessionHasErrors(['notifiable', 'reason', 'source']);

        $this->assertNull($event->fresh()->worksafe_notifiable);
    }

    public function test_record_event_defaults_to_undecided_when_no_source_decision_exists(): void
    {
        $source = Site::factory()->create();

        $event = app(HsEventService::class)->recordEvent([
            'source' => $source,
            'event_category' => HsEvent::CATEGORY_HAZARD,
            'severity' => HsEvent::SEVERITY_LOW,
            'site_id' => $source->id,
        ]);

        $this->assertNotNull($event);
        $this->assertNull($event->worksafe_notifiable);
        $this->assertNull($event->worksafe_status);
        $this->assertNull($event->worksafe_decided_at);
        $this->assertNull($event->worksafe_decided_by_user_id);
    }

    public function test_record_event_persists_an_explicit_classifier_decision_at_creation(): void
    {
        $actor = $this->hsOfficer();
        $source = Site::factory()->create();

        $event = app(HsEventService::class)->recordEvent([
            'source' => $source,
            'event_category' => HsEvent::CATEGORY_HAZARD,
            'severity' => HsEvent::SEVERITY_HIGH,
            'site_id' => $source->id,
            'staff_id' => $actor->id,
            'worksafe_notifiable' => true,
            'worksafe_decision_reason' => 'The source classifier identified a serious event that meets the threshold.',
            'worksafe_decision_source' => 'classifier',
        ]);

        $this->assertNotNull($event);
        $this->assertTrue($event->worksafe_notifiable);
        $this->assertSame(HsEvent::WORKSAFE_PENDING, $event->worksafe_status);
        $this->assertSame($actor->id, $event->worksafe_decided_by_user_id);
        $this->assertNotNull($event->worksafe_decided_at);
        $this->assertSame('classifier', $event->worksafe_decision_source);
        $this->assertSame(
            'The source classifier identified a serious event that meets the threshold.',
            $event->worksafe_decision_reason,
        );
    }

    public function test_site_preservation_requires_an_explicit_evidence_backed_decision_and_release(): void
    {
        $actor = $this->hsOfficer();
        $site = Site::factory()->create();
        $notRequired = HsEvent::factory()->worksafeNotifiable($actor)->create([
            'site_id' => $site->id,
            'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED,
            'worksafe_status' => HsEvent::WORKSAFE_ACKNOWLEDGED,
            'worksafe_notified_at' => now()->subHours(2),
            'worksafe_acknowledged_at' => now()->subHour(),
            'worksafe_method' => 'online',
        ]);

        $this->actingAs($actor)
            ->post("/health-safety/events/{$notRequired->id}/worksafe/site-preservation", [
                'required' => false,
                'evidence_reference' => 'Owner review HSP-2026-001',
            ])
            ->assertSessionHas('success');

        $notRequired->refresh();
        $this->assertSame(HsEvent::SITE_PRESERVATION_NOT_REQUIRED, $notRequired->worksafe_site_preservation_status);
        $this->assertFalse($notRequired->worksafe_site_preserved);
        $this->assertSame($actor->id, $notRequired->worksafe_site_preservation_decided_by_user_id);
        $this->assertSame('Owner review HSP-2026-001', $notRequired->worksafe_site_preservation_decision_reference);

        $active = HsEvent::factory()->worksafeNotifiable($actor)->create([
            'site_id' => $site->id,
            'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED,
            'worksafe_status' => HsEvent::WORKSAFE_ACKNOWLEDGED,
            'worksafe_notified_at' => now()->subHours(4),
            'worksafe_acknowledged_at' => now()->subHours(3),
            'worksafe_method' => 'online',
            'worksafe_site_preserved' => true,
            'worksafe_site_preservation_status' => HsEvent::SITE_PRESERVATION_ACTIVE,
            'worksafe_site_preservation_decided_at' => now()->subHours(4),
            'worksafe_site_preservation_decided_by_user_id' => $actor->id,
            'worksafe_site_preservation_decision_reference' => 'Scene secured HSP-2026-002',
        ]);

        $this->actingAs($actor)
            ->post("/health-safety/events/{$active->id}/worksafe/site-preservation/release", [
                'released_at' => now()->subHour()->toIso8601String(),
                'evidence_reference' => 'Release record HSP-2026-002-R',
            ])
            ->assertSessionHas('success');

        $active->refresh();
        $this->assertSame(HsEvent::SITE_PRESERVATION_RELEASED, $active->worksafe_site_preservation_status);
        $this->assertSame($actor->id, $active->worksafe_site_preservation_released_by_user_id);
        $this->assertSame('Release record HSP-2026-002-R', $active->worksafe_site_preservation_release_reference);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'healthSafety.event.sitePreservationDecisionRecorded',
            'auditable_id' => $notRequired->id,
            'user_id' => $actor->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'healthSafety.event.sitePreservationReleased',
            'auditable_id' => $active->id,
            'user_id' => $actor->id,
        ]);
    }

    public function test_active_site_preservation_cannot_be_downgraded_instead_of_released(): void
    {
        $actor = $this->hsOfficer();
        $event = HsEvent::factory()->worksafeNotifiable($actor)->create([
            'worksafe_status' => HsEvent::WORKSAFE_ACKNOWLEDGED,
            'worksafe_notified_at' => now()->subHours(4),
            'worksafe_acknowledged_at' => now()->subHours(3),
            'worksafe_method' => 'online',
            'worksafe_site_preserved' => true,
            'worksafe_site_preservation_status' => HsEvent::SITE_PRESERVATION_ACTIVE,
            'worksafe_site_preservation_decided_at' => now()->subHours(4),
            'worksafe_site_preservation_decided_by_user_id' => $actor->id,
            'worksafe_site_preservation_decision_reference' => 'Scene secured HSP-2026-003',
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('must be released with evidence');

        app(HsEventService::class)->recordSitePreservationDecision(
            $event,
            false,
            'Retrospective downgrade HSP-2026-003-X',
            $actor,
        );
    }
}
