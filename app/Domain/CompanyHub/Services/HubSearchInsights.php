<?php

namespace App\Domain\CompanyHub\Services;

use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Coarse topic counters only: no search phrases, staff identifiers or result titles. */
class HubSearchInsights
{
    private const TOPICS = [
        'leave' => ['Leave & time off', 'leave|holiday|holidays|annual|time off'],
        'policies' => ['Policies & guidance', 'policy|policies|handbook|procedure|procedures|guide|guidance'],
        'learning' => ['Learning & development', 'training|learning|course|courses|competency|qualification'],
        'it' => ['IT & tools', 'it|password|laptop|computer|network|vpn|access|account|software'],
        'people' => ['People & teams', 'colleague|colleagues|people|birthday|staff|team|teams|manager'],
        'events' => ['Events', 'event|events|gathering|meeting|calendar'],
        'maintenance' => ['Maintenance & equipment', 'maintenance|equipment|repair|building|vehicle'],
        'news' => ['News & updates', 'news|announcement|announcements|update|updates|story|stories'],
        'other' => ['Other topics', null],
    ];

    public function record(User $user, string $query, bool $matched): void
    {
        app(HubAccess::class)->assertEnter($user);
        $query = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $query)));
        if (mb_strlen($query) < 2 || mb_strlen($query) > 150) {
            return;
        }
        $siteId = (int) $user->hrEmployeeProfile?->primary_site_id;
        if (! in_array($siteId, app(UserSiteAccessService::class)->accessibleSiteIds($user), true)) {
            return;
        }
        $today = now(config('app.worker_timezone', 'Pacific/Auckland'))->startOfDay();
        // Persist a daily keyed fingerprint alongside the counter transaction.
        // Cache may be request-local, disabled, evicted or cleared. The key
        // contains no plaintext phrase/identity and old days are pruned daily.
        $key = hash_hmac('sha256', $user->id.'|'.$today->toDateString().'|'.$query, (string) config('app.key'));
        $topic = 'other';
        foreach (self::TOPICS as $candidate => [, $words]) {
            if ($words && preg_match('/\b(?:'.$words.')\b/iu', $query)) {
                $topic = $candidate;
                break;
            }
        }
        try {
            DB::transaction(function () use ($key, $today, $siteId, $topic, $matched): void {
                if (! DB::table('hub_search_keys')->insertOrIgnore(['fingerprint' => $key, 'day' => $today->toDateString()])) {
                    return;
                }
                $identity = ['day' => $today->toDateString(), 'site_id' => $siteId, 'topic' => $topic];
                DB::table('hub_search_daily')->insertOrIgnore([...$identity, 'searches' => 0, 'unanswered' => 0, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('hub_search_daily')->where($identity)->update([
                    'searches' => DB::raw('searches + 1'),
                    'unanswered' => DB::raw('unanswered + '.($matched ? '0' : '1')),
                    'updated_at' => now(),
                ]);
                $this->prune();
            }, 3);
        } catch (Throwable $error) {
            // Optional insights must never prevent a colleague finding help.
            report($error);
        }
    }

    public function prune(): int
    {
        $today = now(config('app.worker_timezone', 'Pacific/Auckland'))->startOfDay();
        DB::table('hub_search_keys')->where('day', '<', $today->toDateString())->delete();

        return DB::table('hub_search_daily')->where('day', '<', $today->copy()->subDays(89)->toDateString())->delete();
    }

    public function summary(User $user, int $days): ?array
    {
        app(HubAccess::class)->assertEnter($user);
        if (! $user->canDo('company_hub.edit_all') && ! $user->canDo('company_hub.layout')) {
            return null;
        }
        abort_unless(in_array($days, [7, 28, 90], true), 422);
        $today = now(config('app.worker_timezone', 'Pacific/Auckland'))->startOfDay();
        $since = $today->copy()->subDays($days - 1)->toDateString();
        $rows = DB::table('hub_search_daily')->whereIn('site_id', app(UserSiteAccessService::class)->accessibleSiteIds($user))
            ->whereBetween('day', [$since, $today->toDateString()])->selectRaw('topic, SUM(searches) as searches, SUM(unanswered) as unanswered')
            ->groupBy('topic')->get();

        return [
            'since' => $since, 'until' => $today->toDateString(), 'days' => $days,
            'total' => (int) $rows->sum('searches'), 'unanswered' => (int) $rows->sum('unanswered'),
            'topics' => $rows->filter(fn ($row) => (int) $row->unanswered > 0)->map(fn ($row) => [
                'key' => $row->topic, 'label' => self::TOPICS[$row->topic][0] ?? 'Other topics',
                'total' => (int) $row->searches, 'unanswered' => (int) $row->unanswered,
            ])->sortByDesc('unanswered')->values()->all(),
        ];
    }
}
