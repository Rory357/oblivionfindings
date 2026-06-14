<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrFeedPost;
use App\Domain\Hr\Models\HrKudos;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FeedService
{
    /**
     * Kudos categories supported by the system.
     */
    public const KUDOS_CATEGORIES = [
        'teamwork' => 'Teamwork',
        'innovation' => 'Innovation',
        'leadership' => 'Leadership',
        'customer_focus' => 'Customer Focus',
        'going_above' => 'Going Above & Beyond',
        'other' => 'Other',
    ];

    /**
     * Post types supported.
     */
    public const POST_TYPES = ['update', 'milestone', 'kudos', 'announcement'];

    /**
     * Create a new feed post.
     */
    public function createPost(User $user, array $data, ?int $tenantId = null): HrFeedPost
    {
        return DB::transaction(function () use ($user, $data, $tenantId) {
            return HrFeedPost::create([
                'tenant_id' => $tenantId ?? $user->tenant_id,
                'user_id' => $user->id,
                'post_type' => $data['post_type'] ?? 'update',
                'content' => $data['content'],
                'is_pinned' => $data['is_pinned'] ?? false,
            ]);
        });
    }

    /**
     * Send kudos to another user. Also creates a corresponding feed post.
     */
    public function sendKudos(User $from, int $toUserId, string $category, string $message, ?int $tenantId = null): HrKudos
    {
        return DB::transaction(function () use ($from, $toUserId, $category, $message, $tenantId) {
            User::findOrFail($toUserId);
            $resolvedTenantId = $tenantId ?? $from->tenant_id;

            // Create a feed post for the kudos
            $feedPost = HrFeedPost::create([
                'tenant_id' => $resolvedTenantId,
                'user_id' => $from->id,
                'post_type' => 'kudos',
                'content' => $message,
                'is_pinned' => false,
            ]);

            return HrKudos::create([
                'tenant_id' => $resolvedTenantId,
                'from_user_id' => $from->id,
                'to_user_id' => $toUserId,
                'category' => $category,
                'message' => $message,
                'is_public' => true,
                'feed_post_id' => $feedPost->id,
            ]);
        });
    }

    /**
     * Get paginated community feed, optionally filtered by type.
     */
    public function getFeed(?int $tenantId, ?string $type, int $perPage = 20): LengthAwarePaginator
    {
        return HrFeedPost::forTenant($tenantId)
            ->when($type, fn ($q) => $q->where('post_type', $type))
            ->with([
                'user:id,name',
                'kudos.toUser:id,name',
                'kudos.fromUser:id,name',
            ])
            ->orderByDesc('is_pinned')
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * Get upcoming milestones: birthdays, work anniversaries, new hires.
     */
    public function getMilestones(?int $tenantId): array
    {
        $now = now();

        // Work anniversaries — employees whose start_date month/day matches upcoming 30 days
        $anniversaries = HrEmployeeProfile::forTenant($tenantId)
            ->active()
            ->whereNotNull('start_date')
            ->where('start_date', '<', $now->copy()->subYear())
            ->with('user:id,name')
            ->get()
            ->filter(function ($profile) use ($now) {
                $start = $profile->start_date;
                $anniversaryThisYear = $start->copy()->year($now->year);
                if ($anniversaryThisYear->isPast() && $anniversaryThisYear->diffInDays($now) > 0) {
                    $anniversaryThisYear->addYear();
                }
                return $anniversaryThisYear->diffInDays($now) <= 30;
            })
            ->map(fn ($profile) => [
                'type' => 'anniversary',
                'user_name' => $profile->user?->name ?? 'Unknown',
                'user_id' => $profile->user_id,
                'date' => $profile->start_date->format('M d'),
                'years' => $profile->start_date->diffInYears($now),
            ])
            ->values()
            ->all();

        // Birthdays — date_of_birth is encrypted so we need to decrypt and check
        $birthdays = HrEmployeeProfile::forTenant($tenantId)
            ->active()
            ->whereNotNull('date_of_birth')
            ->with('user:id,name')
            ->get()
            ->filter(function ($profile) use ($now) {
                try {
                    $dob = \Carbon\Carbon::parse($profile->date_of_birth);
                    $birthdayThisYear = $dob->copy()->year($now->year);
                    if ($birthdayThisYear->isPast() && $birthdayThisYear->diffInDays($now) > 0) {
                        $birthdayThisYear->addYear();
                    }
                    return $birthdayThisYear->diffInDays($now) <= 30;
                } catch (\Exception) {
                    return false;
                }
            })
            ->map(function ($profile) use ($now) {
                $dob = \Carbon\Carbon::parse($profile->date_of_birth);
                return [
                    'type' => 'birthday',
                    'user_name' => $profile->user?->name ?? 'Unknown',
                    'user_id' => $profile->user_id,
                    'date' => $dob->format('M d'),
                ];
            })
            ->values()
            ->all();

        // New hires — started in the last 30 days
        $newHires = HrEmployeeProfile::forTenant($tenantId)
            ->active()
            ->whereNotNull('start_date')
            ->where('start_date', '>=', $now->copy()->subDays(30))
            ->with('user:id,name')
            ->get()
            ->map(fn ($profile) => [
                'type' => 'new_hire',
                'user_name' => $profile->user?->name ?? 'Unknown',
                'user_id' => $profile->user_id,
                'date' => $profile->start_date->format('M d'),
                'position' => $profile->position_title,
            ])
            ->values()
            ->all();

        return [
            'birthdays' => $birthdays,
            'anniversaries' => $anniversaries,
            'new_hires' => $newHires,
        ];
    }

    /**
     * Get kudos leaderboard — top recipients by count.
     */
    public function getKudosLeaderboard(?int $tenantId): Collection
    {
        return HrKudos::forTenant($tenantId)
            ->select('to_user_id', DB::raw('COUNT(*) as kudos_count'))
            ->with('toUser:id,name')
            ->groupBy('to_user_id')
            ->orderByDesc('kudos_count')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'user_id' => $row->to_user_id,
                'user_name' => $row->toUser?->name ?? 'Unknown',
                'kudos_count' => $row->kudos_count,
            ]);
    }
}
