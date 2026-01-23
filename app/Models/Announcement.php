<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'body',
        'created_by',
        'audience_roles',
        'starts_at',
        'ends_at',
        'is_active',
    ];

    protected $casts = [
        'audience_roles' => 'array',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function author()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reads()
    {
        return $this->belongsToMany(User::class, 'announcement_user_reads')
            ->withPivot('read_at')
            ->withTimestamps();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            });
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        // If no roles specified, it's for everyone.
        // Otherwise, show if any of the user's role names match.
        $roleNames = $user->roles()->pluck('name')->values()->all();

        return $query->where(function ($q) use ($roleNames) {
            $q->whereNull('audience_roles')
                ->orWhereJsonLength('audience_roles', 0);

            if (!empty($roleNames)) {
                $q->orWhere(function ($q2) use ($roleNames) {
                    foreach ($roleNames as $r) {
                        $q2->orWhereJsonContains('audience_roles', $r);
                    }
                });
            }
        });
    }

    public function markReadFor(User $user): void
    {
        $this->reads()->syncWithoutDetaching([
            $user->id => ['read_at' => now()],
        ]);
    }

    /**
     * Returns a compact inbox payload for the header.
     */
    public static function inboxFor(User $user, int $limit = 8): array
    {
        $base = static::query()->active()->visibleTo($user);

        $unread = (clone $base)
            ->whereDoesntHave('reads', fn($q) => $q->where('users.id', $user->id))
            ->count();

        $items = $base
            ->orderByDesc('created_at')
            ->limit($limit)
            ->with('author:id,name')
            ->get(['id', 'title', 'body', 'created_by', 'created_at'])
            ->map(function ($a) use ($user) {
                $readAt = $a->reads()
                    ->whereKey($user->id)
                    ->value('announcement_user_reads.read_at');

                return [
                    'id' => $a->id,
                    'title' => $a->title,
                    'body' => $a->body,
                    'author' => $a->author ? ['id' => $a->author->id, 'name' => $a->author->name] : null,
                    'read_at' => $readAt ? $readAt->toISOString() : null,
                    'created_at' => optional($a->created_at)->toISOString(),
                ];
            })
            ->values();

        return [
            'unread_count' => $unread,
            'items' => $items,
        ];
    }
}
