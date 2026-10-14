<?php

namespace Tests\Feature\Portal;

use App\Models\Client;
use App\Models\PortalInvitation;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Notifications\PortalInvitationNotification;
use App\Services\Portal\PortalInvitationService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FakesSsoProvider;
use Tests\TestCase;

/** The family invitation page: 7-day link, set a password or link Google/Microsoft. */
class PortalInvitationTest extends TestCase
{
    use FakesSsoProvider;
    use RefreshDatabase;

    private const STRONG_PASSWORD = 'Harbour-Lights-2026';

    private User $inviter;

    private User $family;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->configureSsoFixture();
        Http::preventStrayRequests();

        $this->inviter = User::factory()->create(['name' => 'Tomasi Vea', 'role' => 'admin', 'approved_at' => now()]);
        $this->client = Client::factory()->create(['first_name' => 'Aroha', 'site_id' => Site::factory()->create()->id]);
        // As ClientPortalUserController creates invited accounts: approved, random password.
        $this->family = User::factory()->withoutTwoFactor()->create([
            'name' => 'Mere Parata',
            'email' => 'mere.parata@example.com',
            'role' => 'next_of_kin',
            'approved_at' => now(),
            'email_verified_at' => null,
        ]);
        $this->family->roles()->attach(Role::where('name', 'next_of_kin')->firstOrFail());
    }

    public function test_active_invitation_shows_who_invited_and_the_password_rules(): void
    {
        $token = $this->issue();

        $this->get(route('portal.invitations.show', $token))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('portal/invitation')
                ->where('state', 'active')
                ->where('email', 'mere.parata@example.com')
                ->where('inviterName', 'Tomasi Vea')
                ->where('clientFirstName', 'Aroha')
                ->has('passwordRules.minLength')
                ->has('expiresOn'));
    }

    public function test_choosing_a_password_accepts_the_invitation_and_signs_in(): void
    {
        $token = $this->issue();

        $this->post(route('portal.invitations.accept', $token), [
            'password' => self::STRONG_PASSWORD,
            'password_confirmation' => self::STRONG_PASSWORD,
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($this->family);
        $this->assertTrue(Hash::check(self::STRONG_PASSWORD, $this->family->fresh()->password));
        $this->assertNotNull($this->family->fresh()->email_verified_at);
        $this->assertNotNull(PortalInvitation::query()->sole()->accepted_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'portal.invitation.accepted']);
    }

    public function test_weak_password_is_refused_and_the_invitation_stays_open(): void
    {
        $token = $this->issue();

        $this->post(route('portal.invitations.accept', $token), [
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');

        $this->assertGuest();
        $this->assertNull(PortalInvitation::query()->sole()->accepted_at);
    }

    public function test_an_accepted_invitation_cannot_be_used_again(): void
    {
        $token = $this->issue();
        $this->post(route('portal.invitations.accept', $token), [
            'password' => self::STRONG_PASSWORD,
            'password_confirmation' => self::STRONG_PASSWORD,
        ]);
        auth()->logout();

        $this->post(route('portal.invitations.accept', $token), [
            'password' => 'Another-Strong-Pass-1',
            'password_confirmation' => 'Another-Strong-Pass-1',
        ])->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check(self::STRONG_PASSWORD, $this->family->fresh()->password));

        $this->get(route('portal.invitations.show', $token))
            ->assertRedirect(route('portal.login', absolute: false))
            ->assertSessionHas('portalNotice');
    }

    public function test_expired_invitation_offers_a_new_link_without_revealing_the_address(): void
    {
        $token = $this->issue();
        $this->travel(PortalInvitation::VALID_FOR_DAYS + 1)->days();

        $this->get(route('portal.invitations.show', $token))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('state', 'expired')
                ->where('maskedEmail', 'm•••@example.com')
                ->missing('email'));

        $this->post(route('portal.invitations.accept', $token), [
            'password' => self::STRONG_PASSWORD,
            'password_confirmation' => self::STRONG_PASSWORD,
        ])->assertSessionHasErrors('password');

        Notification::fake();
        $this->post(route('portal.invitations.resend', $token))
            ->assertRedirect(route('portal.invitations.show', $token, absolute: false))
            ->assertSessionHas('portalInvitationResent', true);
        Notification::assertSentTo($this->family, PortalInvitationNotification::class);
        $this->assertSame(1, PortalInvitation::query()->whereNull('revoked_at')->whereNull('accepted_at')->where('expires_at', '>', now())->count());
    }

    public function test_a_newer_invitation_replaces_the_older_link(): void
    {
        $old = $this->issue();
        $this->issue();

        $this->get(route('portal.invitations.show', $old))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'invalid'));
        $this->post(route('portal.invitations.accept', $old), [
            'password' => self::STRONG_PASSWORD,
            'password_confirmation' => self::STRONG_PASSWORD,
        ])->assertSessionHasErrors('password');
        $this->assertGuest();
    }

    public function test_unknown_or_malformed_tokens_show_the_invalid_state(): void
    {
        foreach ([str_repeat('a', 64), 'short-token'] as $token) {
            $this->get(route('portal.invitations.show', $token))
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'invalid'));
        }
    }

    public function test_google_from_the_invitation_links_the_provider_and_signs_in(): void
    {
        $token = $this->issue();
        $this->fakeSsoProvider('google', ['id' => 'mere-google', 'email' => $this->family->email, 'name' => 'Mere']);

        $this->get(route('portal.invitations.sso', ['token' => $token, 'provider' => 'google']))
            ->assertRedirect(route('portal.auth.google', absolute: false));
        $this->beginSsoFixture('google', 'portal');
        $this->get('/portal/auth/google/callback')->assertRedirect('/portal');

        $this->assertAuthenticatedAs($this->family);
        $this->assertDatabaseHas('identities', ['user_id' => $this->family->id, 'provider' => 'google', 'provider_user_id' => 'mere-google']);
        $this->assertNotNull(PortalInvitation::query()->sole()->accepted_at);
    }

    public function test_provider_sign_in_without_the_invitation_still_requires_an_existing_method(): void
    {
        $this->issue();
        $this->fakeSsoProvider('google', ['id' => 'mere-google', 'email' => $this->family->email, 'name' => 'Mere']);

        $this->beginSsoFixture('google', 'portal');
        $this->get('/portal/auth/google/callback')->assertForbidden();

        $this->assertGuest();
        $this->assertDatabaseMissing('identities', ['user_id' => $this->family->id]);
        $this->assertNull(PortalInvitation::query()->sole()->accepted_at);
    }

    /** Issues an invitation like ClientPortalUserController does and returns the emailed token. */
    private function issue(): string
    {
        Notification::fake();
        app(PortalInvitationService::class)->issue($this->family, $this->client, $this->inviter);
        $token = '';
        Notification::assertSentTo($this->family, PortalInvitationNotification::class, function (PortalInvitationNotification $notification) use (&$token) {
            $token = basename((string) parse_url($notification->acceptUrl, PHP_URL_PATH));

            return true;
        });

        return $token;
    }
}
