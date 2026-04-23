<?php

namespace App\Listeners;

use App\Models\UserLoginLog;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Schema;

class AuthEventSubscriber
{
    /** @var array<string, bool> */
    private static array $userColumnCache = [];

    public function handleLogin(Login $event): void
    {
        $user = $event->user;
        $request = request();

        UserLoginLog::record('login', $user->id, $request->ip(), $request->userAgent());

        $updates = [];

        if ($this->usersTableHasColumn('last_login_at')) {
            $updates['last_login_at'] = now();
        }

        if ($this->usersTableHasColumn('last_login_ip')) {
            $updates['last_login_ip'] = $request->ip();
        }

        if ($this->usersTableHasColumn('login_count')) {
            $updates['login_count'] = (int) ($user->login_count ?? 0) + 1;
        }

        if ($updates !== []) {
            $user->forceFill($updates)->save();
        }
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

    private function usersTableHasColumn(string $column): bool
    {
        return self::$userColumnCache[$column]
            ??= Schema::hasColumn('users', $column);
    }
}
