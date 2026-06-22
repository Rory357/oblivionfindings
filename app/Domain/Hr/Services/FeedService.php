<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrAnnouncement;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrFeedPost;
use App\Domain\Hr\Models\HrKudos;
use App\Domain\Hr\Models\HrKudosReaction;
use App\Domain\Hr\Models\HrKudosReply;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FeedService
{
    /**
     * Kudos *values* — what the colleague demonstrated (shown as the value badge).
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
     * Kudos *impact* — the strength of the shout-out (shown as the impact badge).
     */
    public const KUDOS_IMPACTS = [
        'thank_you' => 'Thank You',
        'good_job' => 'Good Job',
        'impressive' => 'Impressive',
        'exceptional' => 'Exceptional',
    ];

    public const DEFAULT_IMPACT = 'good_job';

    /**
     * Emoji reactions supported on a kudos card.
     */
    public const REACTION_EMOJIS = ['heart', 'party', 'hands'];

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
    public function sendKudos(User $from, int $toUserId, string $category, string $message, ?int $tenantId = null, ?string $impact = null): HrKudos
    {
        return DB::transaction(function () use ($from, $toUserId, $category, $message, $tenantId, $impact) {
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
                'impact' => $this->normaliseImpact($impact),
                'message' => $message,
                'is_public' => true,
                'feed_post_id' => $feedPost->id,
            ]);
        });
    }

    /**
     * Send the same kudos to several recipients at once — one kudos (and feed
     * post) per recipient, all in a single transaction. Single-recipient callers
     * stay on {@see sendKudos}; this is the multi-select path from the wizard.
     *
     * @param  array<int|string>  $toUserIds
     * @return Collection<int, HrKudos>
     */
    public function sendKudosToMany(User $from, array $toUserIds, string $category, string $message, ?int $tenantId = null, ?string $impact = null): Collection
    {
        $ids = collect($toUserIds)
            ->map(fn ($v) => (int) $v)
            ->filter()
            ->unique()
            ->values();

        return DB::transaction(fn () => $ids->map(
            fn (int $id) => $this->sendKudos($from, $id, $category, $message, $tenantId, $impact),
        ));
    }

    /**
     * Toggle an emoji reaction on a kudos for one user (one of each emoji per
     * person — calling again removes it). Returns true when the reaction is now
     * active, false when it was removed. Authorisation is the caller's concern.
     */
    public function toggleReaction(HrKudos $kudos, int $userId, string $emoji): bool
    {
        $existing = HrKudosReaction::where('kudos_id', $kudos->id)
            ->where('user_id', $userId)
            ->where('emoji', $emoji)
            ->first();

        if ($existing) {
            $existing->delete();

            return false;
        }

        try {
            HrKudosReaction::create([
                'tenant_id' => $kudos->tenant_id,
                'kudos_id' => $kudos->id,
                'user_id' => $userId,
                'emoji' => $emoji,
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // A concurrent identical reaction won the race (rapid double-click) —
            // the unique(kudos_id,user_id,emoji) index already holds it. No-op.
        }

        return true;
    }

    /**
     * Post a reply on a kudos thread. Authorisation (giver/receiver only) is the
     * caller's concern.
     */
    public function addReply(HrKudos $kudos, int $userId, string $body): HrKudosReply
    {
        return HrKudosReply::create([
            'tenant_id' => $kudos->tenant_id,
            'kudos_id' => $kudos->id,
            'user_id' => $userId,
            'body' => $body,
        ]);
    }

    /**
     * Get paginated community feed, optionally filtered by type. Eager-loads the
     * kudos parties plus their reactions and reply threads so the wall can render
     * the social row without N+1 queries.
     */
    public function getFeed(?int $tenantId, ?string $type, int $perPage = 20): LengthAwarePaginator
    {
        return HrFeedPost::forTenant($tenantId)
            ->when($type, fn ($q) => $q->where('post_type', $type))
            ->with([
                'user:id,name',
                'kudos.toUser:id,name',
                'kudos.fromUser:id,name',
                'kudos.reactions:id,kudos_id,user_id,emoji',
                'kudos.replies' => fn ($q) => $q->with('user:id,name')->orderBy('created_at'),
            ])
            ->orderByDesc('is_pinned')
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * Headline metrics for the hero band: kudos this month, participation rate,
     * upcoming celebrations and posts this week.
     *
     * @return array{kudos_this_month:int, participation:int, celebrations:int, posts_this_week:int}
     */
    public function getMetrics(?int $tenantId): array
    {
        $now = now();
        $startOfMonth = $now->copy()->startOfMonth();
        $weekAgo = $now->copy()->subDays(7);

        $kudosThisMonth = HrKudos::forTenant($tenantId)
            ->where('created_at', '>=', $startOfMonth)
            ->count();

        $postsThisWeek = HrFeedPost::forTenant($tenantId)
            ->where('created_at', '>=', $weekAgo)
            ->count();

        $activeEmployees = HrEmployeeProfile::forTenant($tenantId)
            ->active()
            ->whereNotNull('user_id')
            ->count();

        $participants = HrKudos::forTenant($tenantId)
            ->where('created_at', '>=', $startOfMonth)
            ->get(['from_user_id', 'to_user_id'])
            ->flatMap(fn ($k) => [$k->from_user_id, $k->to_user_id])
            ->filter()
            ->unique()
            ->count();

        $participation = $activeEmployees > 0
            ? (int) round(min($participants, $activeEmployees) / $activeEmployees * 100)
            : 0;

        $milestones = $this->getMilestones($tenantId);
        $celebrations = count($milestones['birthdays'])
            + count($milestones['anniversaries'])
            + count($milestones['new_hires']);

        return [
            'kudos_this_month' => $kudosThisMonth,
            'participation' => $participation,
            'celebrations' => $celebrations,
            'posts_this_week' => $postsThisWeek,
        ];
    }

    /**
     * Active announcements for the feed wall (the "Notices"), newest-pinned-first,
     * each with acknowledgement progress and whether the viewer has acknowledged.
     * Sourced from the Announcements module — not a fork.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getFeedAnnouncements(?int $tenantId, int $viewerId): array
    {
        $headcount = max(
            1,
            HrEmployeeProfile::forTenant($tenantId)->active()->whereNotNull('user_id')->count(),
        );

        return HrAnnouncement::forTenant($tenantId)
            ->active()
            ->withCount('acknowledgements')
            ->with('creator:id,name')
            ->orderByDesc('is_pinned')
            ->orderByDesc('published_at')
            ->limit(10)
            ->get()
            ->map(function (HrAnnouncement $a) use ($headcount, $viewerId) {
                $acknowledged = $a->acknowledgements()->where('user_id', $viewerId)->exists();

                return [
                    'id' => $a->id,
                    'title' => $a->title,
                    'content' => $a->content,
                    'priority' => $a->priority,
                    'is_pinned' => (bool) $a->is_pinned,
                    'requires_acknowledgement' => (bool) $a->requires_acknowledgement,
                    'target_audience' => $a->target_audience,
                    'target_value' => $a->target_value,
                    'creator' => $a->creator ? [
                        'id' => $a->creator->id,
                        'name' => $a->creator->name,
                    ] : null,
                    'acknowledged_count' => $a->acknowledgements_count,
                    'audience_count' => $headcount,
                    'viewer_acknowledged' => $acknowledged,
                    'created_at' => $a->published_at?->diffForHumans() ?? $a->created_at?->diffForHumans(),
                ];
            })
            ->all();
    }

    /**
     * Get upcoming milestones: birthdays, work anniversaries, new hires. All date
     * maths is whole-day and direction-agnostic so it is correct under Carbon 3
     * (where `diffIn*` returns a signed float).
     */
    public function getMilestones(?int $tenantId): array
    {
        $today = now()->startOfDay();

        // Work anniversaries — ≥ 1 year of service, next anniversary within 30 days.
        $anniversaries = HrEmployeeProfile::forTenant($tenantId)
            ->active()
            ->whereNotNull('start_date')
            ->where('start_date', '<', $today->copy()->subYear())
            ->with('user:id,name')
            ->get()
            ->map(function ($profile) use ($today) {
                $start = $profile->start_date->copy()->startOfDay();
                $next = $this->nextRecurrence($start, $today);

                return [
                    'type' => 'anniversary',
                    'user_name' => $profile->user?->name ?? 'Unknown',
                    'user_id' => $profile->user_id,
                    'date' => $start->format('M d'),
                    'days_away' => $this->wholeDaysBetween($today, $next),
                    'years' => $next->year - $start->year,
                ];
            })
            ->filter(fn ($m) => $m['days_away'] <= 30)
            ->values()
            ->all();

        // Birthdays — date_of_birth is encrypted; decrypt, find next birthday ≤ 30 days.
        $birthdays = HrEmployeeProfile::forTenant($tenantId)
            ->active()
            ->whereNotNull('date_of_birth')
            ->with('user:id,name')
            ->get()
            ->map(function ($profile) use ($today) {
                try {
                    $dob = \Carbon\Carbon::parse($profile->date_of_birth)->startOfDay();
                } catch (\Exception) {
                    return null;
                }
                $next = $this->nextRecurrence($dob, $today);

                return [
                    'type' => 'birthday',
                    'user_name' => $profile->user?->name ?? 'Unknown',
                    'user_id' => $profile->user_id,
                    'date' => $dob->format('M d'),
                    'days_away' => $this->wholeDaysBetween($today, $next),
                ];
            })
            ->filter(fn ($m) => $m !== null && $m['days_away'] <= 30)
            ->values()
            ->all();

        // New hires — started in the last 30 days (days_away negative = days since start).
        $newHires = HrEmployeeProfile::forTenant($tenantId)
            ->active()
            ->whereNotNull('start_date')
            ->where('start_date', '>=', $today->copy()->subDays(30))
            ->with('user:id,name')
            ->get()
            ->map(function ($profile) use ($today) {
                $start = $profile->start_date->copy()->startOfDay();

                return [
                    'type' => 'new_hire',
                    'user_name' => $profile->user?->name ?? 'Unknown',
                    'user_id' => $profile->user_id,
                    'date' => $start->format('M d'),
                    'days_away' => -1 * $this->wholeDaysBetween($start, $today),
                    'position' => $profile->position_title,
                ];
            })
            ->values()
            ->all();

        return [
            'birthdays' => $birthdays,
            'anniversaries' => $anniversaries,
            'new_hires' => $newHires,
        ];
    }

    /** The next occurrence of a month/day on or after $today (this year or next). */
    private function nextRecurrence(Carbon $date, Carbon $today): Carbon
    {
        $next = $date->copy()->year($today->year)->startOfDay();
        if ($next->lt($today)) {
            $next->addYear();
        }

        return $next;
    }

    /** Whole calendar days between two dates, direction-agnostic. */
    private function wholeDaysBetween(Carbon $a, Carbon $b): int
    {
        return (int) abs(round(
            $a->copy()->startOfDay()->diffInDays($b->copy()->startOfDay()),
        ));
    }

    /**
     * Get kudos leaderboard — top recipients by count this month.
     */
    public function getKudosLeaderboard(?int $tenantId, ?Carbon $since = null): Collection
    {
        $since ??= now()->startOfMonth();

        return HrKudos::forTenant($tenantId)
            ->where('created_at', '>=', $since)
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

    /**
     * Most-recognised values this month — every category with its kudos count,
     * highest first. Powers the "Recognition insights" modal.
     *
     * @return array<int, array{key:string, label:string, count:int}>
     */
    public function getValueBreakdown(?int $tenantId): array
    {
        $counts = HrKudos::forTenant($tenantId)
            ->where('created_at', '>=', now()->startOfMonth())
            ->select('category', DB::raw('COUNT(*) as total'))
            ->groupBy('category')
            ->pluck('total', 'category');

        return collect(self::KUDOS_CATEGORIES)
            ->map(fn (string $label, string $key) => [
                'key' => $key,
                'label' => $label,
                'count' => (int) ($counts[$key] ?? 0),
            ])
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    private function normaliseImpact(?string $impact): string
    {
        return array_key_exists($impact, self::KUDOS_IMPACTS) ? $impact : self::DEFAULT_IMPACT;
    }
}
