<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Controller;
use App\Services\HealthSafety\RestraintOwnFollowupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Your follow-ups" (decision 4, Stephan 1 Nov 2026): the restraint follow-ups a person owns, and recording the outcome
 * on one. Routes (permission:restraints.followups.own):
 *   GET  /health-safety/restraints/my-followups                       → index
 *   POST /health-safety/restraints/my-followups/{followup}/complete    → complete
 */
class RestraintOwnFollowupController extends Controller
{
    public const PAGE = '/health-safety/restraints/my-followups';

    public function __construct(private readonly RestraintOwnFollowupService $followups) {}

    public function index(Request $request): Response
    {
        return Inertia::render('health-safety/restraints/my-followups', $this->followups->forViewer($request->user()));
    }

    public function complete(Request $request, int $followup): RedirectResponse
    {
        $this->followups->complete($request->user(), $followup, $request->only(['completed_at', 'outcome', 'notes', 'idempotency_key', 'expected_version']));

        return redirect(self::PAGE)->with('success', 'Outcome recorded.');
    }
}
