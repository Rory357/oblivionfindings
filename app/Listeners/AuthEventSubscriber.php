<?php

namespace App\Listeners;

use App\Models\UserLoginLog;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Events\Dispatcher;

class AuthEventSubscriber
{
    public function handleLogin(Login $event): void
    {
        $user = $event->user;
        $request = request();

        UserLoginLog::record('login', $user->id, $request->ip(), $request->userAgent());

        $user->update([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
            'login_count' => ($user->login_count ?? 0) + 1,
        ]);
    }

    public function handleLogout(Logout $event): void
    {
        if ($event->user) {
            UserLoginLog::record('logout', $event->user->id, request()->ip(), request()->userAgent());
        }
    }

    public function handleFailed(Failed $event): void
    {
        $userId = $event->user?->id;
        UserLoginLog::record('failed_login', $userId, request()->ip(), request()->userAgent(), [
            'email' => $event->credentials['email'] ?? null,
        ]);
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        if ($event->user) {
            UserLoginLog::record('password_reset', $event->user->id, request()->ip(), request()->userAgent());
        }
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'handleLogin',
            Logout::class => 'handleLogout',
            Failed::class => 'handleFailed',
            PasswordReset::class => 'handlePasswordReset',
        ];
    }
}
