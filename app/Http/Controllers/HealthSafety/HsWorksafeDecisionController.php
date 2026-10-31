<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Controller;
use App\Http\Requests\HealthSafety\RecordHsWorksafeDecisionRequest;
use App\Models\HsEvent;
use App\Services\HealthSafety\HsEventAccessService;
use App\Services\HealthSafety\HsEventService;
use App\Services\UserSiteAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class HsWorksafeDecisionController extends Controller
{
    public function __construct(
        private readonly HsEventService $events,
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    public function __invoke(
        RecordHsWorksafeDecisionRequest $request,
        int $hsEvent,
    ): RedirectResponse {
        return DB::transaction(fn () => $this->decideWithinSourceAuthority($request, $hsEvent));
    }

    private function decideWithinSourceAuthority(
        RecordHsWorksafeDecisionRequest $request,
        int $hsEvent,
    ): RedirectResponse {
        // The event page's H&S source read (incident-linked events need incident read); it stays locked until commit.
        $event = app(HsEventAccessService::class)->readableEventForCommand($request->user(), $hsEvent,
            UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS);
        $data = $request->validated();

        try {
            $this->events->recordWorksafeDecision(
                $event,
                $request->boolean('notifiable'),
                $data['reason'],
                $request->user(),
                $data['source'] ?? 'manual',
            );
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'WorkSafe decision recorded.');
    }
}
