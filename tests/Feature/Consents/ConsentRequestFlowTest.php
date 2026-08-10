<?php

namespace Tests\Feature\Consents;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ConsentRequest;
use App\Models\ConsentType;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Notifications\Operations\ConsentRequestCreatedNotification;
use App\Notifications\Operations\ConsentRequestRespondedNotification;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * End-to-end coverage of the consent-request → family-portal approve flow.
 *
 * Covers:
 *   - Staff creates request (permission gate, notifications)
 *   - Recipient must be a linked portal user
 *   - Portal user approves → ClientConsent row written + linked
 *   - Portal user declines → no consent, staff notified
 *   - Non-recipient cannot respond (403)
 *   - Expired request cannot be responded to
 *   - Staff cancellation
 */
class ConsentRequestFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $familyMember;

    private User $strangerFamily;

    private Client $client;

    private ConsentType $consentType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $site = Site::factory()->create(['is_active' => true]);
        $this->staff = User::factory()->create([
            'role' => 'admin',
            'approved_at' => now(),
        ]);
        $this->staff->roles()->attach(Role::where('name', 'admin')->firstOrFail());
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->staff->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => today()->subDay(),
            'end_date' => null,
        ]);

        $this->familyMember = User::factory()->create([
            'name' => 'Sarah Whanau',
            'role' => 'next_of_kin',
            'approved_at' => now(),
        ]);
        $this->strangerFamily = User::factory()->create([
            'name' => 'Stranger',
            'role' => 'next_of_kin',
            'approved_at' => now(),
        ]);
        $portalRole = Role::query()->where('name', 'next_of_kin')->firstOrFail();
        $this->familyMember->roles()->attach($portalRole);
        $this->strangerFamily->roles()->attach($portalRole);

        $this->client = Client::factory()->create([
            'site_id' => $site->id,
            'status' => 'active',
        ]);
        $this->client->portalUsers()->attach($this->familyMember->id, ['relation' => 'next_of_kin']);

        $this->consentType = ConsentType::factory()->create();
    }

    public function test_staff_creates_consent_request_and_recipient_is_notified(): void
    {
        Notification::fake();

        $response = $this->actingAs($this->staff)
            ->post("/operations/clients/{$this->client->id}/consent-requests", $this->validPayload());

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseCount('consent_requests', 1);
        $request = ConsentRequest::first();

        $this->assertSame(ConsentRequest::STATUS_PENDING, $request->status);
        $this->assertSame($this->familyMember->id, $request->recipient_user_id);
        $this->assertSame($this->staff->id, $request->requested_by_user_id);

        Notification::assertSentTo($this->familyMember, ConsentRequestCreatedNotification::class);
    }

    public function test_staff_cannot_pick_non_portal_recipient(): void
    {
        $response = $this->actingAs($this->staff)
            ->post(
                "/operations/clients/{$this->client->id}/consent-requests",
                array_merge($this->validPayload(), ['recipient_user_id' => $this->strangerFamily->id]),
            );

        $response->assertSessionHasErrors('recipient_user_id');
        $this->assertDatabaseCount('consent_requests', 0);
    }

    public function test_portal_user_approves_and_client_consent_row_is_written(): void
    {
        Notification::fake();

        $request = $this->createRequest();

        $response = $this->actingAs($this->familyMember)
            ->post("/portal/clients/{$this->client->id}/consent-requests/{$request->id}/approve", [
                'response_notes' => 'Reviewed and approved',
                'acknowledge_authority' => '1',
            ]);

        $response->assertRedirect();

        $request->refresh();
        $this->assertSame(ConsentRequest::STATUS_APPROVED, $request->status);
        $this->assertNotNull($request->resulting_consent_id);
        $this->assertNotNull($request->responded_at);

        // A ClientConsent was created and linked.
        $consent = ClientConsent::find($request->resulting_consent_id);
        $this->assertNotNull($consent);
        $this->assertSame($this->client->id, $consent->client_id);
        $this->assertSame('given', $consent->status);
        $this->assertSame('portal_signature', $consent->evidence_type);
        $this->assertSame('electronic', $consent->given_method);
        $this->assertSame($this->familyMember->id, $consent->given_by_user_id);
        $this->assertSame('next_of_kin', $consent->given_by_relationship);

        Notification::assertSentTo($this->staff, ConsentRequestRespondedNotification::class);
    }

    public function test_portal_user_decline_records_no_consent(): void
    {
        Notification::fake();

        $request = $this->createRequest();

        $response = $this->actingAs($this->familyMember)
            ->post("/portal/clients/{$this->client->id}/consent-requests/{$request->id}/decline", [
                'response_notes' => 'Want to try alternative safeguards first.',
            ]);

        $response->assertRedirect();

        $request->refresh();
        $this->assertSame(ConsentRequest::STATUS_DECLINED, $request->status);
        $this->assertNull($request->resulting_consent_id);
        $this->assertSame(0, ClientConsent::count());

        Notification::assertSentTo($this->staff, ConsentRequestRespondedNotification::class);
    }

    public function test_non_recipient_cannot_respond(): void
    {
        $request = $this->createRequest();

        // Linked to the client BUT not the recipient of this specific request.
        $this->client->portalUsers()->attach($this->strangerFamily->id, ['relation' => 'next_of_kin']);

        $response = $this->actingAs($this->strangerFamily)
            ->post("/portal/clients/{$this->client->id}/consent-requests/{$request->id}/approve", [
                'response_notes' => 'attempt',
                'acknowledge_authority' => '1',
            ]);

        $response->assertForbidden();
        $this->assertSame(ConsentRequest::STATUS_PENDING, $request->fresh()->status);
    }

    public function test_expired_request_cannot_be_approved(): void
    {
        $request = $this->createRequest();
        $request->update(['expires_at' => now()->subDay()]);

        $response = $this->actingAs($this->familyMember)
            ->post("/portal/clients/{$this->client->id}/consent-requests/{$request->id}/approve", [
                'response_notes' => 'trying',
                'acknowledge_authority' => '1',
            ]);

        // Service throws RuntimeException on non-actionable — the controller lets
        // it propagate; expect a 5xx / exception. We check the state didn't change.
        $this->assertNotSame(ConsentRequest::STATUS_APPROVED, $request->fresh()->status);
    }

    public function test_staff_can_cancel_pending_request(): void
    {
        $request = $this->createRequest();

        $response = $this->actingAs($this->staff)
            ->post("/operations/clients/{$this->client->id}/consent-requests/{$request->id}/cancel", [
                'reason' => 'Clinical situation changed; re-issuing to the welfare guardian.',
            ]);

        $response->assertRedirect();

        $request->refresh();
        $this->assertSame(ConsentRequest::STATUS_CANCELLED, $request->status);
        $this->assertSame($this->staff->id, $request->cancelled_by_user_id);
    }

    public function test_request_index_returns_inertia_page(): void
    {
        $this->createRequest();

        $response = $this->actingAs($this->staff)
            ->get("/operations/clients/{$this->client->id}/consent-requests");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('operations/clients/consent-requests/Index')
            ->has('requests', 1)
            ->where('stats.pending', 1)
        );
    }

    public function test_portal_show_marks_viewed_at_on_first_load(): void
    {
        $request = $this->createRequest();
        $this->assertNull($request->viewed_at);

        $this->actingAs($this->familyMember)
            ->get("/portal/clients/{$this->client->id}/consent-requests/{$request->id}")
            ->assertOk();

        $request->refresh();
        $this->assertNotNull($request->viewed_at);
    }

    public function test_pending_requests_surface_on_family_dashboard(): void
    {
        $this->createRequest();

        $response = $this->actingAs($this->familyMember)
            ->get("/portal/clients/{$this->client->id}/dashboard");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('pendingConsentRequests', 1)
            ->where('stats.pendingConsentRequests', 1)
        );
    }

    public function test_expire_stale_command_flips_overdue_pending_to_expired(): void
    {
        $fresh = $this->createRequest();
        $stale = $this->createRequest();
        $stale->update(['expires_at' => now()->subDays(2)]);

        $this->artisan('consent-requests:expire-stale')
            ->expectsOutputToContain('Expired 1')
            ->assertExitCode(0);

        $this->assertSame(ConsentRequest::STATUS_PENDING, $fresh->fresh()->status);
        $this->assertSame(ConsentRequest::STATUS_EXPIRED, $stale->fresh()->status);
    }

    // ── helpers ───────────────────────────────────────────────────

    private function validPayload(): array
    {
        return [
            'consent_type_id' => $this->consentType->id,
            'recipient_user_id' => $this->familyMember->id,
            'recipient_relationship' => ConsentRequest::RELATION_NEXT_OF_KIN,
            'purpose' => 'Monitor location of personal tracker for safety after wandering incidents.',
            'least_restrictive_justification' => 'Alternatives reviewed and rejected.',
            'data_scope' => 'Care team only',
            'retention_period_days' => 180,
            'withdrawal_method_text' => 'Contact key worker to withdraw.',
            'expires_in_days' => 14,
        ];
    }

    private function createRequest(array $overrides = []): ConsentRequest
    {
        return ConsentRequest::factory()->create(array_merge([
            'client_id' => $this->client->id,
            'consent_type_id' => $this->consentType->id,
            'requested_by_user_id' => $this->staff->id,
            'recipient_user_id' => $this->familyMember->id,
            'recipient_relationship' => ConsentRequest::RELATION_NEXT_OF_KIN,
        ], $overrides));
    }
}
