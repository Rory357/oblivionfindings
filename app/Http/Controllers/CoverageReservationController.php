<?php

namespace App\Http\Controllers;

use App\Services\CoverageReservationService;
use App\Services\UserSiteAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class CoverageReservationController extends Controller
{
    public function store(
        Request $request,
        CoverageReservationService $reservations,
        UserSiteAccessService $siteAccess,
    ) {
        $auth = $request->user();
        abort_unless($auth && ($auth->canDo('shifts.create') || $auth->canDo('shifts.manageAny')), 403);

        $data = $request->validate([
            'site_id' => ['required', 'integer', 'exists:sites,id'],
            'coverage_rule_id' => ['nullable', 'integer', 'exists:site_coverage_requirements,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'role_key' => ['nullable', 'string', 'max:80'],
            'return_to' => ['nullable', 'string', 'max:2048'],
        ]);

        $siteAccess->assertCanAccessSiteId($auth, (int) $data['site_id'], ['reports.viewAny']);

        $reservation = $reservations->createQuickFillReservation(
            $auth,
            (int) $data['site_id'],
            Carbon::parse($data['starts_at']),
            Carbon::parse($data['ends_at']),
            ! empty($data['coverage_rule_id']) ? (int) $data['coverage_rule_id'] : null,
            ! empty($data['role_key']) ? (string) $data['role_key'] : null,
            [
                'source' => 'rostering_gap_action',
                'return_to' => $data['return_to'] ?? null,
            ],
        );

        return response()->json([
            'token' => $reservation->reservation_token,
            'expires_at' => $reservation->expires_at?->toIso8601String(),
            'reservation_id' => $reservation->id,
        ]);
    }
}
