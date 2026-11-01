<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Controller;
use App\Models\HsInvestigation;
use App\Models\HsInvestigationCorrection;
use App\Services\HealthSafety\HsEventAccessService;
use App\Services\HealthSafety\HsInvestigationCorrectionService;
use App\Services\UserSiteAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Answer 20: propose, review, approve or reject a correction to a done investigation's findings. Routes sit behind
 * hazards.manage; each command first reads the event through the event page's read (404 when it can't be read).
 */
class HsInvestigationCorrectionController extends Controller
{
    public function __construct(private readonly HsInvestigationCorrectionService $corrections) {}

    public function store(Request $request, int $event, HsInvestigation $investigation): RedirectResponse
    {
        $data = $request->validate([
            'after_value' => ['required', 'string', 'max:5000'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        DB::transaction(function () use ($request, $event, $investigation, $data): void {
            $readable = $this->event($request, $event);
            abort_unless((int) $investigation->hs_event_id === (int) $readable->id, 404);
            $this->corrections->propose($readable, $investigation, $request->user(), $data['after_value'], $data['reason']);
        });

        return back()->with('success', 'Correction sent for review.');
    }

    public function review(Request $request, int $event, HsInvestigationCorrection $correction): RedirectResponse
    {
        DB::transaction(fn () => $this->corrections->review($this->event($request, $event), $correction, $request->user()));

        return back()->with('success', 'Correction reviewed.');
    }

    public function approve(Request $request, int $event, HsInvestigationCorrection $correction): RedirectResponse
    {
        DB::transaction(fn () => $this->corrections->approve($this->event($request, $event), $correction, $request->user()));

        return back()->with('success', 'Correction approved.');
    }

    public function reject(Request $request, int $event, HsInvestigationCorrection $correction): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:2000']]);
        DB::transaction(fn () => $this->corrections->reject($this->event($request, $event), $correction, $request->user(), $data['reason']));

        return back()->with('success', 'Correction not accepted.');
    }

    private function event(Request $request, int $event): \App\Models\HsEvent
    {
        return app(HsEventAccessService::class)->readableEventForCommand($request->user(), $event,
            UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS);
    }
}
