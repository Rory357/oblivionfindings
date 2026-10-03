<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\BreakGlassPolicy;
use App\Models\Client;
use App\Models\ClientBreakGlassAccess;
use App\Models\MedicationAlert;
use App\Models\MedicationEmergencyAccessReview;
use App\Models\MedicationEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleNotificationPreference;
use App\Models\Site;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Models\UserWitnessPin;
use App\Notifications\AppEventNotification;
use App\Notifications\MedicationAlertNotification;
use App\Services\Medication\EmergencyAccess\EmergencyAccessService;
use App\Services\Tasks\Providers\MedicationEmergencyAccessReviewProvider;
use App\Services\NotificationService;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class EmergencyAccessLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $reviewer;

    private Site $site;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $this->owner = $this->staff('provider_manager', ['medications.breakglass']);
        $this->reviewer = $this->staff('clinical_lead', ['medications.audit.view']);
        $this->client = Client::factory()->create(['site_id' => $this->site->id, 'status' => 'active']);
    }

    private function staff(string $role, array $permissions): User
    {
        $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $rbac = Role::where('name', $role)->first();
        if ($rbac) {
            $user->roles()->syncWithoutDetaching([$rbac->id]);
        }
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'medications', 'module' => 'Clinical']);
            $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        }
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [],
            'position_role' => $role, 'is_active' => true, 'start_date' => today()->subDay(), 'end_date' => null,
            'created_by' => $user->id, 'updated_by' => $user->id,
        ]);

        return $user->fresh();
    }

    private function payload(array $extra = []): array
    {
        return $extra + [
            'reason' => 'Relief has not arrived and a dose is due', 'reason_category' => 'Covering an absence',
            'minutes' => 60, 'authorization_mode' => 'self',
            'acknowledged_min_necessary' => true, 'acknowledged_incident_report' => true,
        ];
    }

    private function start(array $extra = []): ClientBreakGlassAccess
    {
        return app(EmergencyAccessService::class)->start($this->owner, $this->client, $this->payload($extra));
    }

    public function test_both_acknowledgements_are_required_on_server(): void
    {
        $this->actingAs($this->owner)->postJson('/clients/'.$this->client->id.'/break-glass',
            $this->payload(['acknowledged_min_necessary' => false]))->assertUnprocessable();
        $this->assertDatabaseCount('client_break_glass_accesses', 0);
        $this->assertDatabaseCount('medication_events', 0);
    }

    public function test_required_second_person_has_no_self_authorisation_bypass(): void
    {
        BreakGlassPolicy::updateApplicationPolicy(BreakGlassPolicy::defaults() + []);
        BreakGlassPolicy::current()->update(['second_person' => 'required']);
        $this->actingAs($this->owner)->postJson('/clients/'.$this->client->id.'/break-glass', $this->payload())->assertUnprocessable();
        $this->assertDatabaseCount('client_break_glass_accesses', 0);
    }

    public function test_a_second_person_must_use_their_pin_and_confirmed_at_is_saved(): void
    {
        UserWitnessPin::updateOrCreate(['user_id' => $this->reviewer->id], ['pin_hash' => Hash::make('384927'), 'set_at' => now(), 'must_change' => false]);
        $payload = $this->payload(['authorization_mode' => 'co_sign', 'co_signed_by' => $this->reviewer->id, 'co_signer_pin' => '000000']);
        $this->actingAs($this->owner)->postJson('/clients/'.$this->client->id.'/break-glass', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('client_break_glass_accesses', 0);
        $payload['co_signer_pin'] = '384927';
        $this->actingAs($this->owner)->postJson('/clients/'.$this->client->id.'/break-glass', $payload)->assertRedirect();
        $this->assertNotNull(ClientBreakGlassAccess::sole()->confirmed_at);
        $this->assertSame($this->reviewer->id, ClientBreakGlassAccess::sole()->co_signed_by);
    }

    public function test_duplicate_live_grant_is_refused_without_another_event(): void
    {
        $this->start();
        $this->actingAs($this->owner)->postJson('/clients/'.$this->client->id.'/break-glass', $this->payload())->assertUnprocessable();
        $this->assertDatabaseCount('client_break_glass_accesses', 1);
        $this->assertDatabaseCount('medication_events', 1);
    }

    public function test_extension_uses_frozen_policy_after_a_live_policy_change(): void
    {
        $grant = $this->start();
        BreakGlassPolicy::updateApplicationPolicy(['default_minutes' => 5, 'max_minutes' => 5, 'extend_minutes' => 5]);
        $this->travel(51)->minutes();
        app(EmergencyAccessService::class)->extend($this->owner, $grant, 'Relief is still on the way');
        $this->assertEquals($grant->expires_at->copy()->addMinutes(30), $grant->fresh()->expires_at);
        $this->assertSame(240, $grant->fresh()->policy_snapshot['max_minutes']);
        $this->assertDatabaseCount('medication_emergency_access_extensions', 1);
    }

    public function test_auditor_can_view_but_cannot_end_or_extend_another_grant(): void
    {
        $grant = $this->start();
        $auditor = $this->staff('auditor', ['medications.audit.view']);
        $this->actingAs($auditor)->get('/emar/emergency-access')->assertOk();
        $this->actingAs($auditor)->deleteJson('/emar/clients/'.$this->client->id.'/break-glass/'.$grant->id, ['reason' => 'Someone else is available now'])->assertForbidden();
        $this->actingAs($auditor)->postJson('/emar/clients/'.$this->client->id.'/break-glass/'.$grant->id.'/extend', ['reason' => 'Someone else is available now'])->assertForbidden();
        $this->assertTrue($grant->fresh()->isRunning());
    }

    public function test_authorized_colleague_can_end_another_grant_with_current_permission_evidence(): void
    {
        $grant = $this->start();
        $colleague = $this->staff('clinical_lead', ['medications.breakglass.end']);
        $reason = 'The assigned nurse has arrived and can continue care';
        $this->actingAs($colleague)->deleteJson('/emar/clients/'.$this->client->id.'/break-glass/'.$grant->id, ['reason' => $reason])
            ->assertRedirect();

        $ended = ClientBreakGlassAccess::withTrashed()->findOrFail($grant->id);
        $this->assertSame('ended_by', $ended->ended_how);
        $this->assertSame($colleague->id, $ended->ended_by);
        $this->assertSame($reason, $ended->end_reason);
        $this->assertFalse($ended->isRunning());
        $this->assertSame($colleague->id, MedicationEvent::where('facts->action', 'closed')->sole()->actor_id);
        Notification::assertSentTo($this->owner, AppEventNotification::class, fn (AppEventNotification $notification): bool =>
            $notification->payload['event_key'] === 'break_glass_access.ended'
            && $notification->payload['access_id'] === $grant->id
            && $notification->payload['body'] === $reason);
        Notification::assertNotSentTo($colleague, AppEventNotification::class, fn (AppEventNotification $notification): bool =>
            $notification->payload['event_key'] === 'break_glass_access.ended');
    }

    public function test_explicit_support_collection_preserves_recipients_and_user_over_role_preferences(): void
    {
        $enabledByUser = $this->staff('clinical_lead', []);
        $disabledByUser = $this->staff('provider_manager', []);
        $eventKey = 'break_glass_access.ended';
        RoleNotificationPreference::create([
            'role_id' => Role::where('name', 'clinical_lead')->sole()->id,
            'key' => $eventKey, 'enabled' => false,
        ]);
        RoleNotificationPreference::create([
            'role_id' => Role::where('name', 'provider_manager')->sole()->id,
            'key' => $eventKey, 'enabled' => true,
        ]);
        UserNotificationPreference::create(['user_id' => $enabledByUser->id, 'key' => $eventKey, 'enabled' => true]);
        UserNotificationPreference::create(['user_id' => $disabledByUser->id, 'key' => $eventKey, 'enabled' => false]);

        $recipients = collect([$this->owner, $this->reviewer, $enabledByUser, $disabledByUser]);
        $allowed = app(NotificationService::class)->applyPreferences($recipients, $eventKey);

        $this->assertSame([$this->owner->id, $enabledByUser->id], $allowed->pluck('id')->all());
        $this->assertSame($this->owner, $allowed[0]);
        $this->assertSame($enabledByUser, $allowed[1]);
        $this->assertCount(4, $recipients);
    }

    public function test_revoked_grant_is_waiting_for_review_and_self_review_is_denied(): void
    {
        $grant = $this->start();
        app(EmergencyAccessService::class)->end($this->owner, $grant);
        $this->actingAs($this->reviewer)->get('/emar/emergency-access')->assertInertia(fn (Assert $page) => $page
            ->where('stats.awaiting_review', 1)->has('reviewQueue', 1));
        $this->actingAs($this->owner)->postJson('/emar/clients/'.$this->client->id.'/break-glass/'.$grant->id.'/review', ['review_outcome' => 'justified'])->assertForbidden();
    }

    public function test_cosigner_is_not_the_independent_reviewer(): void
    {
        UserWitnessPin::updateOrCreate(['user_id' => $this->reviewer->id], ['pin_hash' => Hash::make('384927'), 'set_at' => now(), 'must_change' => false]);
        $grant = $this->start(['authorization_mode' => 'co_sign', 'co_signed_by' => $this->reviewer->id, 'co_signer_pin' => '384927']);
        app(EmergencyAccessService::class)->end($this->owner, $grant);
        $this->actingAs($this->reviewer)->postJson('/emar/clients/'.$this->client->id.'/break-glass/'.$grant->id.'/review', ['review_outcome' => 'justified'])->assertForbidden();
    }

    public function test_review_corrections_append_and_stale_correction_is_rejected(): void
    {
        $grant = $this->start();
        app(EmergencyAccessService::class)->end($this->owner, $grant);
        $url = '/emar/clients/'.$this->client->id.'/break-glass/'.$grant->id.'/review';
        $this->actingAs($this->reviewer)->postJson($url, ['review_outcome' => 'justified'])->assertRedirect();
        $first = MedicationEmergencyAccessReview::sole();
        $this->actingAs($this->reviewer)->postJson($url, [
            'review_outcome' => 'not_justified', 'review_notes' => 'The roster showed a signed-off colleague was already present',
            'correction_reason' => 'The roster was checked after the first review', 'corrects_review_id' => $first->id,
        ])->assertRedirect();
        $this->assertDatabaseCount('medication_emergency_access_reviews', 2);
        $this->assertSame('justified', $first->fresh()->outcome);
        $this->actingAs($this->reviewer)->postJson($url, [
            'review_outcome' => 'justified', 'correction_reason' => 'Another check was carried out', 'corrects_review_id' => $first->id,
        ])->assertUnprocessable();
        $this->assertDatabaseCount('medication_emergency_access_reviews', 2);
    }

    public function test_running_grant_and_not_justified_without_notes_cannot_be_reviewed(): void
    {
        $grant = $this->start();
        $url = '/emar/clients/'.$this->client->id.'/break-glass/'.$grant->id.'/review';
        $this->actingAs($this->reviewer)->postJson($url, ['review_outcome' => 'justified'])->assertUnprocessable();
        app(EmergencyAccessService::class)->end($this->owner, $grant);
        $this->actingAs($this->reviewer)->postJson($url, ['review_outcome' => 'not_justified'])->assertUnprocessable();
        $this->assertDatabaseCount('medication_emergency_access_reviews', 0);
    }

    public function test_expiry_is_recorded_once_at_actual_expiry_time(): void
    {
        $grant = $this->start();
        $grant->update(['expires_at' => now()->subMinute()]);
        $expiredAt = $grant->fresh()->expires_at;
        $this->artisan('emar:expire-emergency-access')->assertSuccessful();
        $this->artisan('emar:expire-emergency-access')->assertSuccessful();
        $this->assertEquals($expiredAt, $grant->fresh()->ended_at);
        $this->assertSame('expired', $grant->fresh()->ended_how);
        $this->assertSame(1, MedicationEvent::where('facts->action', 'closed')->count());
    }

    public function test_daily_report_uses_the_23_hour_nz_day_and_scoped_reviewers(): void
    {
        foreach (['2026-09-26 12:00:00', '2026-09-27 10:59:59', '2026-09-27 11:00:00'] as $at) {
            ClientBreakGlassAccess::forceCreate([
                'client_id' => $this->client->id, 'user_id' => $this->owner->id, 'reason' => 'Synthetic historical grant',
                'created_at' => Carbon::parse($at, 'UTC'), 'expires_at' => Carbon::parse($at, 'UTC')->addHour(),
            ]);
        }
        $hr = $this->staff('hr', []);
        $finance = $this->staff('finance', []);
        $this->artisan('breakglass:daily-report', ['--date' => '2026-09-27'])->assertSuccessful();
        $report = MedicationAlert::where('type', 'breakglass')->sole();
        $this->assertSame(2, $report->subject['used_count']);
        $this->assertSame('2026-09-27', $report->subject['nz_date']);
        Notification::assertSentTo($this->reviewer, MedicationAlertNotification::class);
        $this->artisan('breakglass:daily-report', ['--date' => '2026-09-27'])->assertSuccessful();
        $this->assertSame(1, MedicationAlert::where('type', 'breakglass')->count());
        Notification::assertNotSentTo($hr, MedicationAlertNotification::class);
        Notification::assertNotSentTo($finance, MedicationAlertNotification::class);
    }

    public function test_extension_is_refused_before_the_final_ten_minutes(): void
    {
        $grant = $this->start();
        $this->actingAs($this->owner)->postJson('/emar/clients/'.$this->client->id.'/break-glass/'.$grant->id.'/extend',
            ['reason' => 'Relief has not arrived'])->assertUnprocessable();
        $this->assertDatabaseCount('medication_emergency_access_extensions', 0);
    }

    public function test_service_checks_required_reason_against_the_saved_policy(): void
    {
        BreakGlassPolicy::updateApplicationPolicy(['reason_required' => true]);
        try {
            $this->start(['reason' => '']);
            $this->fail('A required reason cannot be blank.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason', $exception->errors());
        }
        $this->assertDatabaseCount('client_break_glass_accesses', 0);
    }

    public function test_removed_person_does_not_starve_later_expiry_processing(): void
    {
        $removed = $this->start();
        $removed->update(['expires_at' => now()->subMinute()]);
        $this->client->delete();
        $other = Client::factory()->create(['site_id' => $this->site->id, 'status' => 'active']);
        $valid = app(EmergencyAccessService::class)->start($this->owner, $other, $this->payload());
        $valid->update(['expires_at' => now()->subMinute()]);
        $this->artisan('emar:expire-emergency-access')->assertFailed();
        $this->assertNull($removed->fresh()->ended_at);
        $this->assertNotNull($valid->fresh()->ended_at);
    }

    public function test_repeat_acknowledgement_rechecks_current_permission_after_stale_actor_read(): void
    {
        BreakGlassPolicy::updateApplicationPolicy(['repeat_threshold_count' => 2]);
        $this->start();
        ClientBreakGlassAccess::create([
            'client_id' => $this->client->id, 'user_id' => $this->owner->id,
            'reason' => 'Earlier synthetic use', 'expires_at' => now()->subMinute(),
        ]);
        $this->reviewer->load(['roles.permissions', 'permissionOverrides', 'hrEmployeeProfile']);
        $permission = Permission::where('key', 'medications.audit.view')->sole();
        $this->reviewer->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => false]]);
        try {
            app(EmergencyAccessService::class)->acknowledgeRepeat($this->reviewer, $this->site->id, $this->owner->id, 'Reviewed the repeated cover arrangements');
            $this->fail('Revoked review authority must refuse acknowledgement.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('break_glass_flag_dismissals', 0);
        $this->assertSame(0, MedicationEvent::where('facts->action', 'repeat_acknowledged')->count());
    }

    public function test_review_rechecks_approval_after_stale_actor_read(): void
    {
        $grant = $this->start();
        app(EmergencyAccessService::class)->end($this->owner, $grant);
        $this->reviewer->newQuery()->whereKey($this->reviewer->id)->update(['approved_at' => null]);
        try {
            app(EmergencyAccessService::class)->review($this->reviewer, $grant, ['review_outcome' => 'justified']);
            $this->fail('Approval withdrawn before the authorization lock must refuse review.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('medication_emergency_access_reviews', 0);
    }

    public function test_manual_end_rechecks_approval_after_stale_actor_read(): void
    {
        $grant = $this->start();
        $this->reviewer->newQuery()->whereKey($this->reviewer->id)->update(['approved_at' => null]);
        try {
            app(EmergencyAccessService::class)->end($this->reviewer, $grant, 'Assigned staff can continue medication care');
            $this->fail('Approval withdrawn before the authorization lock must refuse a manual end.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertTrue($grant->fresh()->isRunning());
        $this->assertSame(0, MedicationEvent::where('facts->action', 'closed')->count());
    }

    public function test_unswept_expired_review_tasks_use_each_grants_frozen_deadline(): void
    {
        $expiry = now()->subDays(2)->subMinute();
        $grants = collect([1, 3, null])->map(fn ($days) => ClientBreakGlassAccess::forceCreate([
            'client_id' => $this->client->id, 'user_id' => $this->owner->id,
            'reason' => 'Historical emergency cover awaiting independent review',
            'created_at' => $expiry->copy()->subHour(), 'expires_at' => $expiry,
            'policy_snapshot' => $days === null ? null : array_replace(BreakGlassPolicy::defaults(), ['review_days' => $days]),
        ]));
        $tasks = collect((new MedicationEmergencyAccessReviewProvider)->authorizedTasks($this->reviewer))->keyBy('id');
        $this->assertCount(2, $tasks);
        $this->assertTrue($tasks->has('med_emergency_review-'.$grants[0]->id));
        $this->assertFalse($tasks->has('med_emergency_review-'.$grants[1]->id));
        $this->assertTrue($tasks->has('med_emergency_review-'.$grants[2]->id));
        $this->assertSame($grants[0]->reviewDueTime()->toIso8601String(), $tasks->get('med_emergency_review-'.$grants[0]->id)->dueAt);
    }

    public function test_overdue_review_projection_uses_independent_reviewer_authority(): void
    {
        $grant = $this->start();
        app(EmergencyAccessService::class)->end($this->owner, $grant);
        $this->travel(3)->days();
        $provider = new MedicationEmergencyAccessReviewProvider;
        $this->assertCount(1, $provider->authorizedTasks($this->reviewer));
        $this->assertCount(0, $provider->authorizedTasks($this->owner));
        app(EmergencyAccessService::class)->review($this->reviewer, $grant, ['review_outcome' => 'justified']);
        $this->assertCount(0, $provider->authorizedTasks($this->reviewer));
    }
}
