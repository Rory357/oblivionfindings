<?php

namespace App\Domain\Privacy\Services;

use App\Models\User;
use Illuminate\Support\Collection;

class PrivacyRecipients
{
    /**
     * Users who hold a given privacy permission — role-granted or allow-override,
     * honouring deny overrides — resolved exactly like the gate's User::canDo().
     *
     * The query narrows to plausible candidates (anyone with the permission via a
     * role, or with any per-user override row for it); canDo() then makes the final,
     * authoritative decision so deny-overrides and legacy synonyms are respected.
     *
     * @return Collection<int, User>
     */
    public static function withPermission(string $permissionKey): Collection
    {
        return User::query()
            ->where(function ($q) use ($permissionKey) {
                $q->whereHas('roles.permissions', fn ($p) => $p->where('key', $permissionKey))
                    ->orWhereHas('permissionOverrides', fn ($p) => $p->where('permissions.key', $permissionKey));
            })
            ->get()
            ->filter(fn (User $user) => $user->canDo($permissionKey))
            ->values();
    }
}
