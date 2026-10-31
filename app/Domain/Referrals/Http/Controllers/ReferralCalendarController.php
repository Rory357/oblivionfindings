<?php

namespace App\Domain\Referrals\Http\Controllers;

use App\Domain\Referrals\Services\ReferralAccess;
use App\Domain\Referrals\Services\ReferralDates;
use App\Domain\Referrals\Services\ReferralPresenter;
use App\Domain\Referrals\Services\ReferralSearchIndex;
use App\Domain\Referrals\Services\ReferralWorkQueue;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class ReferralCalendarController extends Controller
{
    public function __construct(private readonly ReferralWorkQueue $queue, private readonly ReferralAccess $access) {}

    public function index(Request $request)
    {
        abort_unless($this->queue->canView($request->user()), 403);

        return Inertia::render('operations/referrals/calendar', ['permissions' => $this->access->permissions($request->user())])
            ->toResponse($request)->header('Cache-Control', 'no-store, private');
    }

    /** Searchable referral picker for planning work: name (hashed index) or reference, within the planner's scope. */
    public function options(Request $request, ReferralPresenter $presenter, ReferralSearchIndex $search)
    {
        abort_unless($this->queue->canView($request->user()) && $this->access->can($request->user(), 'tasks.manage'), 403);
        $input = $request->validate(['q' => 'nullable|string|max:80', 'page' => 'nullable|integer|min:1|max:50']);
        $page = (int) ($input['page'] ?? 1);
        $query = $this->access->query($request->user())->where('stage', '!=', 'closed');
        if (filled($input['q'] ?? null)) {
            $search->constrain($query, $input['q']);
        }
        $rows = $query->with(['owner:id,name', 'site:id,name'])->orderByDesc('id')->offset(($page - 1) * 20)->limit(21)->get();

        return response()->json(['referrals' => $rows->take(20)->map(fn ($referral) => $presenter->summary($referral))->values(), 'has_more' => $rows->count() > 20, 'page' => $page])
            ->header('Cache-Control', 'no-store, private');
    }

    public function events(Request $request)
    {
        $actor = $request->user();
        abort_unless($this->queue->canView($actor), 403);
        $input = $request->validate(['start' => 'required|date', 'end' => 'required|date', 'page' => 'nullable|integer|min:1|max:1000']);
        $start = ReferralDates::parse($input['start'], 'start');
        $end = ReferralDates::parse($input['end'], 'end');
        abort_unless($end->gt($start) && $end->lte($start->addDays(93)), 422, 'Choose a calendar range of up to 93 days.');
        $page = (int) ($input['page'] ?? 1);
        $work = $this->queue->work($actor)->where('status', 'open')->whereHas('referral', fn ($q) => $q->where('stage', '!=', 'closed'))
            ->where('due_at', '<', $end)->where(fn ($q) => $q->where('ends_at', '>', $start)->orWhere(fn ($point) => $point->whereNull('ends_at')->where('due_at', '>=', $start)))
            ->with(['owner:id,name', 'referral.site:id,name'])->orderBy('id')->offset(($page - 1) * 250)->limit(251)->get();
        $next = $this->queue->nextActions($actor)->where('next_due_at', '>=', $start)->where('next_due_at', '<', $end)
            ->with(['owner:id,name', 'site:id,name'])->orderBy('id')->offset(($page - 1) * 250)->limit(251)->get();
        $events = $work->take(250)->map(function ($item) {
            $referral = $item->referral;

            return $this->event('referral_work-'.$item->id, $item->details['title'] ?? 'Referral follow-up',
                $item->due_at, $item->ends_at, $item->owner, $referral,
                $item->audience === 'funding' ? 'referral-funding' : ($item->audience === 'assessment' ? 'referral-assessment' : ($item->ends_at ? 'referral-appointment' : 'referral-follow-up')),
                '/operations/referrals/'.$referral->reference.'?work='.$item->id);
        })->concat($next->take(250)->map(fn ($referral) => $this->event('referral_next-'.$referral->id, $referral->next_action,
            $referral->next_due_at, null, $referral->owner, $referral, 'referral-follow-up', '/operations/referrals/'.$referral->reference.'?tab=work')))->values();

        return response()->json(['events' => $events, 'next_page' => $work->count() > 250 || $next->count() > 250 ? $page + 1 : null])
            ->header('Cache-Control', 'no-store, private');
    }

    private function event($id, $title, $start, $end, $owner, $referral, $source, $link): array
    {
        return ['id' => $id, 'source' => $source, 'group' => 'auto', 'title' => $title, 'start' => $start->toISOString(),
            'end' => $end?->toISOString(), 'allDay' => false, 'status' => $start->isPast() ? 'overdue' : 'scheduled',
            'owner' => $owner ? ['id' => $owner->id, 'name' => $owner->name] : null,
            'site' => $referral->site ? ['id' => $referral->site->id, 'name' => $referral->site->name, 'type' => 'site'] : null,
            'ref' => $referral->reference, 'room' => null, 'link' => $link, 'editable' => false,
            'desc' => 'Open the referral to review safe contact, reschedule or record an outcome.'];
    }
}
