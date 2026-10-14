<?php

namespace Tests\Feature\Portal;

use App\Models\PortalSignInLink;
use App\Models\Role;
use App\Models\User;
use App\Notifications\PortalSignInLinkNotification;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * "Email me a sign-in link" on the family portal: one use, 15 minutes, the
 * requesting browser only, and never a hint about which emails have accounts.
 */
class PortalSignInLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        config(['inertia.ssr.enabled' => false]);
    }

    public function test_approved_family_account_gets_a_link_and_the_requesting_browser_a_device_cookie(): void
    {
        Notification::fake();
        $family = $this->familyUser('mere@example.com');

        $response = $this->post(route('portal.sign-in-link.store'), ['email' => 'Mere@Example.com ']);

        $response->assertRedirect(route('portal.login', absolute: false))
            ->assertSessionHas('portalLinkSentTo', 'mere@example.com')
            ->assertCookie(PortalSignInLink::DEVICE_COOKIE);
        Notification::assertSentTo($family, PortalSignInLinkNotification::class);
        $this->assertDatabaseHas('portal_sign_in_links', ['user_id' => $family->id, 'used_at' => null]);
    }

    public function test_unknown_and_staff_emails_get_the_same_response_and_no_link(): void
    {
        Notification::fake();
        $staff = User::factory()->create(['email' => 'staff@example.com', 'role' => 'support_worker', 'approved_at' => now()]);
        $staff->roles()->attach(Role::where('name', 'support_worker')->firstOrFail());

        foreach (['nobody@example.com', 'staff@example.com'] as $email) {
            $this->post(route('portal.sign-in-link.store'), ['email' => $email])
                ->assertRedirect(route('portal.login', absolute: false))
                ->assertSessionHas('portalLinkSentTo', $email)
                ->assertCookie(PortalSignInLink::DEVICE_COOKIE);
        }

        Notification::assertNothingSent();
        $this->assertDatabaseCount('portal_sign_in_links', 0);
    }

    public function test_unapproved_family_account_gets_no_link(): void
    {
        Notification::fake();
        $this->familyUser('pending@example.com', approved: false);

        $this->post(route('portal.sign-in-link.store'), ['email' => 'pending@example.com'])
            ->assertSessionHas('portalLinkSentTo', 'pending@example.com');

        Notification::assertNothingSent();
        $this->assertDatabaseCount('portal_sign_in_links', 0);
    }

    public function test_link_signs_in_once_in_the_same_browser(): void
    {
        Notification::fake();
        $family = $this->familyUser('mere@example.com');
        [$url, $deviceSecret] = $this->requestLink('mere@example.com', $family);

        $this->withCookie(PortalSignInLink::DEVICE_COOKIE, $deviceSecret)
            ->get($url)
            ->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($family);
        $this->assertNotNull(PortalSignInLink::query()->first()->used_at);
        $this->assertNotNull($family->fresh()->email_verified_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'portal.sign_in_link.used']);

        auth()->logout();
        $this->withCookie(PortalSignInLink::DEVICE_COOKIE, $deviceSecret)
            ->get($url)
            ->assertRedirect(route('portal.login', absolute: false))
            ->assertSessionHasErrors('link');
        $this->assertGuest();
    }

    public function test_link_opened_in_another_browser_does_not_sign_in(): void
    {
        Notification::fake();
        $family = $this->familyUser('mere@example.com');
        [$url] = $this->requestLink('mere@example.com', $family);

        $this->withCookie(PortalSignInLink::DEVICE_COOKIE, 'a-different-browser')
            ->get($url)
            ->assertRedirect(route('portal.login', absolute: false))
            ->assertSessionHasErrors(['link' => 'Open the sign-in link in the same browser where you asked for it, or ask for a new link here.']);
        $this->assertGuest();
        $this->assertNull(PortalSignInLink::query()->first()->used_at);
    }

    public function test_expired_link_does_not_sign_in(): void
    {
        Notification::fake();
        $family = $this->familyUser('mere@example.com');
        [$url, $deviceSecret] = $this->requestLink('mere@example.com', $family);

        $this->travel(PortalSignInLink::VALID_FOR_MINUTES + 1)->minutes();

        $this->withCookie(PortalSignInLink::DEVICE_COOKIE, $deviceSecret)
            ->get($url)
            ->assertSessionHasErrors('link');
        $this->assertGuest();
    }

    public function test_a_newer_link_replaces_the_older_one(): void
    {
        Notification::fake();
        $family = $this->familyUser('mere@example.com');
        [$firstUrl, $firstSecret] = $this->requestLink('mere@example.com', $family);
        Notification::fake();
        $this->requestLink('mere@example.com', $family);

        $this->withCookie(PortalSignInLink::DEVICE_COOKIE, $firstSecret)
            ->get($firstUrl)
            ->assertSessionHasErrors('link');
        $this->assertGuest();
    }

    public function test_two_step_verification_is_not_skipped(): void
    {
        Notification::fake();
        $family = $this->familyUser('mere@example.com');
        $family->forceFill([
            'two_factor_secret' => encrypt('synthetic-secret'),
            'two_factor_confirmed_at' => now(),
        ])->save();
        [$url, $deviceSecret] = $this->requestLink('mere@example.com', $family);

        $this->withCookie(PortalSignInLink::DEVICE_COOKIE, $deviceSecret)
            ->get($url)
            ->assertRedirect(route('two-factor.login', absolute: false))
            ->assertSessionHas('login.id', $family->id);
        $this->assertGuest();
    }

    public function test_requests_per_email_are_limited_without_changing_the_response(): void
    {
        Notification::fake();
        $family = $this->familyUser('mere@example.com');

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->post(route('portal.sign-in-link.store'), ['email' => 'mere@example.com'])
                ->assertSessionHas('portalLinkSentTo', 'mere@example.com');
        }

        Notification::assertSentToTimes($family, PortalSignInLinkNotification::class, 3);
    }

    private function familyUser(string $email, bool $approved = true): User
    {
        $user = User::factory()->withoutTwoFactor()->create([
            'email' => $email,
            'role' => 'next_of_kin',
            'approved_at' => $approved ? now() : null,
            'email_verified_at' => null,
        ]);
        $user->roles()->attach(Role::where('name', 'next_of_kin')->firstOrFail());

        return $user;
    }

    /** @return array{0: string, 1: string} The emailed URL and the device cookie value. */
    private function requestLink(string $email, User $user): array
    {
        $response = $this->post(route('portal.sign-in-link.store'), ['email' => $email]);
        $deviceSecret = (string) $response->getCookie(PortalSignInLink::DEVICE_COOKIE)?->getValue();
        $url = '';
        Notification::assertSentTo($user, PortalSignInLinkNotification::class, function (PortalSignInLinkNotification $notification) use (&$url) {
            $url = $notification->signInUrl;

            return true;
        });

        return [parse_url($url, PHP_URL_PATH) ?: $url, $deviceSecret];
    }
}
