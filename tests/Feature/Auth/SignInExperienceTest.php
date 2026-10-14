<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Auth\LocaleController;
use App\Models\AppSetting;
use App\Models\Role;
use App\Models\User;
use App\Support\SecurityPolicy;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Tests\Support\FakesSsoProvider;
use Tests\TestCase;

/** The approved Journey sign-in pages: what the server tells them and where people land. */
class SignInExperienceTest extends TestCase
{
    use FakesSsoProvider;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        config(['inertia.ssr.enabled' => false]);
    }

    public function test_staff_sign_out_lands_on_the_staff_signed_out_page(): void
    {
        $staff = $this->user('staff@example.test', 'support_worker');

        $this->actingAs($staff)->post(route('logout'))->assertRedirect(route('signed-out'));
        $this->get(route('signed-out'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('auth/signed-out')->where('audience', 'staff'));
    }

    public function test_family_sign_out_lands_on_the_family_signed_out_page(): void
    {
        $family = $this->user('family@example.com', 'next_of_kin');

        $this->actingAs($family)->post(route('logout'))->assertRedirect(route('signed-out', ['for' => 'family']));
        $this->get(route('signed-out', ['for' => 'family']))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('audience', 'family'));
    }

    public function test_language_switch_is_remembered_for_signed_out_visitors(): void
    {
        $this->from(route('login'))
            ->post(route('locale.update'), ['locale' => 'mi'])
            ->assertRedirect(route('login'))
            ->assertCookie(LocaleController::COOKIE, 'mi');

        $this->withCookie(LocaleController::COOKIE, 'mi')
            ->get(route('login'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('locale', 'mi'));

        $this->post(route('locale.update'), ['locale' => 'xx'])->assertSessionHasErrors('locale');
    }

    public function test_reset_link_request_looks_the_same_for_unknown_and_real_emails(): void
    {
        Notification::fake();
        $staff = $this->user('staff@example.test', 'support_worker');

        foreach (['nobody@example.test', 'staff@example.test'] as $email) {
            $this->from(route('password.request'))
                ->post(route('password.email'), ['email' => $email])
                ->assertRedirect(route('password.request'))
                ->assertSessionHasNoErrors()
                ->assertSessionHas('resetLinkSentTo', $email);
        }

        Notification::assertSentTo($staff, ResetPassword::class);
        Notification::assertCount(1);
    }

    public function test_family_password_reset_returns_to_the_family_sign_in(): void
    {
        Notification::fake();
        $family = $this->user('family@example.com', 'next_of_kin');
        $this->post(route('password.email'), ['email' => $family->email]);

        Notification::assertSentTo($family, ResetPassword::class, function (ResetPassword $notification) use ($family) {
            $this->get(route('password.reset', ['token' => $notification->token, 'email' => $family->email]))
                ->assertInertia(fn (AssertableInertia $page) => $page->where('audience', 'family')->has('passwordRules'));

            $this->post(route('password.update'), [
                'token' => $notification->token,
                'email' => $family->email,
                'password' => 'Harbour-Lights-2026',
                'password_confirmation' => 'Harbour-Lights-2026',
            ])->assertRedirect(route('portal.login'))->assertSessionHas('passwordChanged', true);

            return true;
        });
    }

    public function test_staff_sign_in_page_gets_the_email_domain_routing_but_no_secrets(): void
    {
        $this->configureSsoFixture('example.test');

        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('auth/login')
                ->where('ssoRouting', [
                    ['domain' => 'example.test', 'provider' => 'microsoft'],
                ])
                ->missing('canRegister'));

        $this->assertStringNotContainsString('synthetic-microsoft-secret', $this->get(route('login'))->getContent());
    }

    public function test_email_first_hands_the_typed_address_to_the_provider(): void
    {
        $this->configureSsoFixture('example.test');
        $driver = Mockery::mock();
        $driver->shouldReceive('setConfig')->andReturnSelf();
        $driver->shouldReceive('setScopes')->andReturnSelf();
        $driver->shouldReceive('with')->once()->with(Mockery::on(
            fn (array $parameters) => ($parameters['login_hint'] ?? null) === 'rangi@example.test',
        ))->andReturnSelf();
        $driver->shouldReceive('redirect')->andReturn(redirect('https://provider.example.test/authorize'));
        Socialite::shouldReceive('buildProvider')->andReturn($driver);

        $this->get(route('auth.microsoft.redirect', ['login_hint' => 'Rangi@Example.test']))
            ->assertRedirect('https://provider.example.test/authorize');
    }

    public function test_login_hint_outside_the_organisation_domain_is_not_passed_on(): void
    {
        $this->configureSsoFixture('example.test');
        $driver = Mockery::mock();
        $driver->shouldReceive('setConfig')->andReturnSelf();
        $driver->shouldReceive('setScopes')->andReturnSelf();
        $driver->shouldReceive('with')->once()->with(Mockery::on(
            fn (array $parameters) => ! array_key_exists('login_hint', $parameters),
        ))->andReturnSelf();
        $driver->shouldReceive('redirect')->andReturn(redirect('https://provider.example.test/authorize'));
        Socialite::shouldReceive('buildProvider')->andReturn($driver);

        $this->get(route('auth.microsoft.redirect', ['login_hint' => 'someone@gmail.com']))
            ->assertRedirect('https://provider.example.test/authorize');
    }

    public function test_locked_out_sign_in_page_gets_the_wait_for_its_countdown(): void
    {
        $staff = $this->user('staff@example.test', 'support_worker');
        RateLimiter::increment(md5('login'.implode('|', [$staff->email, '127.0.0.1'])), amount: SecurityPolicy::maxLoginAttempts());

        $this->from(route('login'))
            ->withHeader('X-Inertia', 'true')
            ->post(route('login.store'), ['email' => $staff->email, 'password' => 'wrong-password'])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email')
            ->assertSessionHas('loginRetryAfter');
        $this->assertGuest();
    }

    public function test_awaiting_approval_is_flagged_for_the_waiting_screen(): void
    {
        $pending = User::factory()->withoutTwoFactor()->create(['email' => 'new@example.test', 'approved_at' => null]);

        $this->from(route('login'))
            ->post(route('login.store'), ['email' => $pending->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => 'Your account is awaiting approval.'])
            ->assertSessionHas('awaitingApproval', true);
        $this->assertGuest();
    }

    public function test_idle_family_member_returns_to_the_family_sign_in(): void
    {
        AppSetting::query()->updateOrCreate(['key' => 'session_timeout_minutes'], ['value' => 1]);
        SecurityPolicy::flushCache();
        $family = $this->user('family@example.com', 'next_of_kin');

        $this->actingAs($family)
            ->withSession(['last_activity_at' => now()->subMinutes(2)->timestamp])
            ->get('/settings/profile')
            ->assertRedirect(route('portal.login', absolute: false))
            ->assertSessionHasErrors(['session']);
        $this->assertGuest();
    }

    public function test_organisations_can_upload_a_second_logo_for_dark_backgrounds(): void
    {
        Storage::fake('public');
        $admin = $this->user('admin@example.test', 'admin');

        $this->actingAs($admin)
            ->post('/settings/branding', ['logo_dark' => UploadedFile::fake()->image('logo-dark.png', 240, 80)])
            ->assertRedirect();
        $path = AppSetting::query()->where('key', 'branding.logo_dark_path')->value('value');
        $this->assertIsString($path);
        Storage::disk('public')->assertExists($path);

        auth()->logout();
        $this->get(route('login'))
            ->assertInertia(fn (AssertableInertia $page) => $page->whereNot('branding.logoDarkUrl', null));

        $this->actingAs($admin)->post('/settings/branding', ['remove_logo_dark' => true])->assertRedirect();
        Storage::disk('public')->assertMissing($path);
        $this->assertNull(AppSetting::query()->where('key', 'branding.logo_dark_path')->value('value'));
    }

    private function user(string $email, string $role): User
    {
        $user = User::factory()->withoutTwoFactor()->create(['email' => $email, 'role' => $role, 'approved_at' => now()]);
        $user->roles()->attach(Role::where('name', $role)->firstOrFail());

        return $user;
    }
}
