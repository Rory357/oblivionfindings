<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An action taken by a break-glass user during an active grant window. Recorded
 * via {@see recordFor()}, which is a no-op unless the acting user currently holds
 * an active grant for the client — so it can be called unconditionally from the
 * medication controllers.
 */
class BreakGlassAccessEvent extends Model
{
    protected $fillable = [
        'break_glass_access_id',
        'action',
        'detail',
    ];

    public function access(): BelongsTo
    {
        return $this->belongsTo(ClientBreakGlassAccess::class, 'break_glass_access_id');
    }

    /**
     * Record an access-scope event if (and only if) the user is acting under an
     * active break-glass grant for this client. $dedupeMinutes suppresses repeat
     * events of the same action within the window (e.g. MAR-chart refreshes).
     */
    public static function recordFor(?User $user, ?Client $client, string $action, ?string $detail = null, int $dedupeMinutes = 0): void
    {
        if (! $user || ! $client) {
            return;
        }

        $access = ClientBreakGlassAccess::query()
            ->where('client_id', $client->id)
            ->where('user_id', $user->id)
            ->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest('created_at')
            ->first();

        if (! $access) {
            return;
        }

        if ($dedupeMinutes > 0 && static::query()
            ->where('break_glass_access_id', $access->id)
            ->where('action', $action)
            ->where('created_at', '>=', now()->subMinutes($dedupeMinutes))
            ->exists()) {
            return;
        }

        static::create([
            'break_glass_access_id' => $access->id,
            'action' => $action,
            'detail' => $detail !== null ? mb_substr($detail, 0, 255) : null,
        ]);
    }
}
