<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Controller;
use App\Http\Requests\HealthSafety\RecordHsWorksafeDecisionRequest;
use App\Models\HsEvent;
use App\Services\HealthSafety\HsEventService;
use App\Services\UserSiteAccessService;
use Illuminate\Http\RedirectResponse;

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
        $query = HsEvent::query();
        $this->siteAccess->applyHsEventScope(
            $query,
            $request->user(),
            ['healthSafety.viewAllSites'],
        );
        $event = $query->findOrFail($hsEvent);
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
