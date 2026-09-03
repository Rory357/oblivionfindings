<?php

namespace Tests\Feature\Security;

use App\Listeners\AuthEventSubscriber;
use App\Models\User;
use App\Models\UserLoginLog;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AuthEventReachabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_auth_event_subscriber_is_registered_in_event_dispatcher(): void
    {
        $events = app('events');
        $this->assertTrue($events->hasListeners(Login::class));
        $this->assertTrue($events->hasListeners(Logout::class));
        $this->assertTrue($events->hasListeners(Failed::class));
    }

    public function test_login_event_creates_user_login_log_and_updates_user(): void
    {
        $user = User::factory()->create();

        event(new Login('web', $user, false));

        $this->assertDatabaseHas('user_login_logs', [
            'user_id' => $user->id,
            'event_type' => 'login',
        ]);

        $user->refresh();
        $this->assertNotNull($user->last_login_at);
    }

    public function test_logout_event_creates_user_login_log(): void
    {
        $user = User::factory()->create();

        event(new Logout('web', $user));

        $this->assertDatabaseHas('user_login_logs', [
            'user_id' => $user->id,
            'event_type' => 'logout',
        ]);
    }

    public function test_failed_login_event_creates_user_login_log_with_context(): void
    {
        $user = User::factory()->create(['email' => 'test-failed@example.com']);

        event(new Failed('web', $user, ['email' => 'test-failed@example.com', 'password' => 'wrong']));

        $this->assertDatabaseHas('user_login_logs', [
            'user_id' => $user->id,
            'event_type' => 'failed_login',
        ]);
    }
}
