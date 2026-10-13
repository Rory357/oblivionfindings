<?php

namespace App\Domain\Hr\Services;

use App\Models\Role;
use App\Models\RoleNotificationPreference;
use App\Models\User;
use App\Models\UserNotificationPreference;
use Illuminate\Support\Carbon;

class HrCalendarReminderPreferences
{
    public function allows(User $user, string $channel): bool
    {
        if (! in_array($channel, ['notification', 'email'], true)) {
            return false;
        }
        if ($channel === 'email' && (blank($user->email) || $user->email_digest_frequency === 'off')) {
            return false;
        }
        $field = $channel === 'email' ? 'email' : 'inapp';
        $personal = UserNotificationPreference::where('user_id', $user->id)->where('key', 'hr.calendar.reminder')->first();
        if ($personal && $personal->channel_overrides === null) {
            return $personal->enabled && $personal->{'channel_'.$field};
        }
        if ($personal && array_key_exists($field, $personal->channel_overrides)) {
            return (bool) $personal->channel_overrides[$field];
        }
        $roles = $user->roles()->pluck('roles.id');
        if ($roles->isEmpty() && filled($user->role)) {
            $roles = Role::where('name', $user->role)->pluck('id');
        }
        $defaults = RoleNotificationPreference::whereIn('role_id', $roles)->where('key', 'hr.calendar.reminder')->get();

        // Match the choices shown on Settings → Notifications: in-app on, email off.
        return $defaults->isEmpty() ? $field === 'inapp' : $defaults->contains(fn ($row) => $row->enabled && $row->{'channel_'.$field});
    }

    /** Return the next permissible attempt; quiet time and digests never change the event. */
    public function availableAt(User $user, string $channel, Carbon $due, Carbon $now): Carbon
    {
        $next = $due->copy();
        if ($channel === 'email' && in_array($user->email_digest_frequency, ['daily', 'weekly'], true)) {
            $local = $due->copy()->setTimezone('Pacific/Auckland');
            $next = $local->copy()->startOfDay()->setTime(8, 0);
            if ($user->email_digest_frequency === 'weekly') {
                $next->startOfWeek(Carbon::MONDAY)->setTime(8, 0);
                if ($next->lt($local)) {
                    $next->addWeek();
                }
            } elseif ($next->lt($local)) {
                $next->addDay();
            }
            $next->utc();
        }
        if ($user->dnd_enabled && (! $user->dnd_until || $user->dnd_until->gt($now))) {
            $next = $next->max($user->dnd_until ?? $now->copy()->addMinute());
        }

        return $next;
    }

    public function emailConfigured(): bool
    {
        return $this->isDeliveryMailer(config('mail.default'), []);
    }

    private function isDeliveryMailer(?string $mailer, array $visited): bool
    {
        if (blank($mailer) || in_array($mailer, $visited, true)) {
            return false;
        }
        $transport = config("mail.mailers.{$mailer}.transport");
        if (in_array($transport, [null, 'array', 'log'], true)) {
            return false;
        }
        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            $children = config("mail.mailers.{$mailer}.mailers", []);

            // A log/array fallback silently succeeds without delivery; do not issue a receipt for it.
            return is_array($children) && $children !== [] && collect($children)->every(fn ($child) => is_string($child) && $this->isDeliveryMailer($child, [...$visited, $mailer]));
        }

        return true;
    }
}
