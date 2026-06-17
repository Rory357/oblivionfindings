<?php

namespace Tests\Feature\Safeguarding;

use App\Models\Client;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SafeguardingConcern;
use App\Models\User;
use App\Notifications\Safeguarding\SafeguardingReviewDueNotification;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Safeguarding redesign — post-merge remediation ("make it real" pass).
 *
 * Locks the behaviour that closed the live-audit gaps: W6 (referral report
 * performs the triaged→referred_external transition), need-to-know becoming
 * settable (raise + toggle), and the row/detail payloads the rebuilt right-click
 * menu + Control Room link depend on.
 */
class SafeguardingRemediationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    private function makeSafeguardingUser(array $permissionKeys): User
    {
        $user = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);

        $adminRole = Role::query()->where('name', 'admin')->first();
        if ($adminRole) {
            $user->roles()->syncWithoutDetaching([$adminRole->id]);
        }

        foreach ($permissionKeys as $permissionKey) {
            $permission = Permission::query()->firstOrCreate(
                ['key' => $permissionKey],
                ['description' => str_replace('.', ' ', $permissionKey), 'group' => explode('.', $permissionKey)[0]],
            );
            $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        }

        return $user;
    }

    /** W6 — the promised triage-refer → log-report → Referred external flow. */
    public function test_logging_a_referral_report_advances_a_concern_parked_at_triage(): void
    {
        $user = $this->makeSafeguardingUser(['safeguarding.report.external']);
        $concern = SafeguardingConcern::factory()->create([
            'status' => 'triaged',
            'requires_external_referral' => true,
        ]);

        $this->actingAs($user)
            ->post("/safeguarding/{$concern->id}/external-reports", [
                'authority_type' => 'police',
                'authority_name' => 'NZ Police',
                'report_method' => 'phone',
                'reported_at' => now()->toDateString(),
                'report_summary' => 'Notified police.',
            ])
            ->assertRedirect();

        $this->assertSame('referred_external', $concern->fresh()->status);
    }

    /** A late/additional report must not regress a concern already past triage. */
    public function test_logging_a_report_does_not_regress_a_concern_past_triage(): void
    {
        $user = $this->makeSafeguardingUser(['safeguarding.report.external']);
        $concern = SafeguardingConcern::factory()->create([
            'status' => 'investigating',
            'requires_external_referral' => true,
        ]);

        $this->actingAs($user)
            ->post("/safeguarding/{$concern->id}/external-reports", [
                'authority_type' => 'police',
                'authority_name' => 'NZ Police',
                'report_method' => 'phone',
                'reported_at' => now()->toDateString(),
                'report_summary' => 'Notified police.',
            ])
            ->assertRedirect();

        $this->assertSame('investigating', $concern->fresh()->status);
    }

    /** Need-to-know is settable at raise time. */
    public function test_raising_a_sensitive_concern_persists_the_flag(): void
    {
        $user = $this->makeSafeguardingUser(['safeguarding.create']);

        $this->actingAs($user)
            ->post('/safeguarding', [
                'subject_type' => 'other',
                'other_subject_name' => 'Withheld',
                'concern_type' => 'abuse',
                'severity' => 'high',
                'description' => 'Sensitive allegation requiring need-to-know handling.',
                'is_sensitive' => true,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('safeguarding_concerns', [
            'description' => 'Sensitive allegation requiring need-to-know handling.',
            'is_sensitive' => true,
        ]);
    }

    /** The detail toggle flips the restriction both ways. */
    public function test_set_sensitivity_toggles_the_restriction(): void
    {
        $user = $this->makeSafeguardingUser(['safeguarding.update']);
        $concern = SafeguardingConcern::factory()->create(['is_sensitive' => false]);

        $this->actingAs($user)
            ->post("/safeguarding/{$concern->id}/sensitivity", ['is_sensitive' => true])
            ->assertRedirect();
        $this->assertTrue((bool) $concern->fresh()->is_sensitive);

        $this->actingAs($user)
            ->post("/safeguarding/{$concern->id}/sensitivity", ['is_sensitive' => false])
            ->assertRedirect();
        $this->assertFalse((bool) $concern->fresh()->is_sensitive);
    }

    /** The rebuilt right-click menu reads can-flags + a real subject href off each row. */
    public function test_index_row_exposes_can_flags_and_subject_href(): void
    {
        $user = $this->makeSafeguardingUser(['safeguarding.viewAny', 'safeguarding.update']);
        $client = Client::factory()->create();
        SafeguardingConcern::factory()->create([
            'subject_type' => Client::class,
            'subject_id' => $client->id,
            'status' => 'reported',
        ]);

        $this->actingAs($user)
            ->get('/safeguarding?tab=all')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('rows.data.0.can.update', true)
                ->where('rows.data.0.subject.href', "/operations/clients/{$client->id}")
                ->has('rows.data.0.subject_informed')
            );
    }

    /** The detail surfaces the active Control Room alert id via the linked HsEvent. */
    public function test_detail_resolves_control_room_alert_from_linked_hs_event(): void
    {
        $user = $this->makeSafeguardingUser(['safeguarding.viewAny']);
        $concern = SafeguardingConcern::factory()->create();
        $alert = ControlRoomAlert::factory()->create();

        $key = HsEvent::buildIdempotencyKey(SafeguardingConcern::class, $concern->id, HsEvent::CATEGORY_SAFEGUARDING);
        $hsEvent = HsEvent::query()->where('idempotency_key', $key)->first()
            ?? HsEvent::factory()->create([
                'idempotency_key' => $key,
                'source_type' => SafeguardingConcern::class,
                'source_id' => $concern->id,
                'event_category' => HsEvent::CATEGORY_SAFEGUARDING,
            ]);
        $hsEvent->forceFill(['control_room_alert_id' => $alert->id])->save();

        $this->actingAs($user)
            ->get("/safeguarding?concern={$concern->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('detail.control_room_alert_id', $alert->id)
            );
    }

    /** W9 — the daily reminder delivers a digest notification to the assigned lead. */
    public function test_review_reminder_notifies_the_assigned_lead(): void
    {
        Notification::fake();

        $lead = User::factory()->create(['approved_at' => now()]);
        $concern = SafeguardingConcern::factory()->create([
            'status' => 'investigating',
            'assigned_to_user_id' => $lead->id,
        ]);
        // An external report awaiting acknowledgement beyond the threshold.
        $concern->externalReports()->create([
            'authority_type' => 'police',
            'authority_name' => 'NZ Police',
            'report_method' => 'phone',
            'reported_at' => now()->subDays(10),
            'report_summary' => 'Notified police.',
            'reported_by_user_id' => $lead->id,
            'created_by' => $lead->id,
            'acknowledgement_received' => false,
        ]);

        $this->artisan('safeguarding:review-reminders --days=7')->assertExitCode(0);

        Notification::assertSentTo($lead, SafeguardingReviewDueNotification::class);
    }

    /** The need-to-know access log surfaces who viewed the concern (read-audit). */
    public function test_detail_exposes_access_log_after_a_view(): void
    {
        $user = $this->makeSafeguardingUser(['safeguarding.viewAny']);
        $concern = SafeguardingConcern::factory()->create();

        $this->actingAs($user)
            ->get("/safeguarding?concern={$concern->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('detail.access_log.0.by', $user->name)
            );
    }
}
