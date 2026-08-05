<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\Monitoring\Data\CollectorEnrollmentIssue;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Services\CollectorEnrollmentService;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class MonitoringCollectorLifecycleController extends Controller
{
    public function __construct(
        private readonly CollectorEnrollmentService $enrollments,
        private readonly SecurityDevicesAccessService $access,
    ) {}

    public function issue(Request $request): JsonResponse
    {
        $viewer = $request->user();
        abort_unless($viewer->canDo('securityDevices.integrations.manage'), 403);

        $validated = $request->validate([
            'site_id' => ['required', 'integer', 'min:1'],
        ]);
        $siteId = (int) $validated['site_id'];
        $this->access->assertCanViewSite($viewer, $siteId);

        return $this->enrollmentResponse(
            $this->enrollments->issue($siteId, (int) $viewer->id),
        );
    }

    public function reEnroll(Request $request, int $collector): JsonResponse
    {
        $viewer = $request->user();
        abort_unless($viewer->canDo('securityDevices.integrations.manage'), 403);

        $record = $this->accessibleCollector($viewer, $collector);
        if ($record->revoked_at === null) {
            throw ValidationException::withMessages([
                'collector' => 'Revoke this collector before issuing replacement enrolment material.',
            ]);
        }

        return $this->enrollmentResponse($this->enrollments->issue(
            (int) $record->site_id,
            (int) $viewer->id,
            replacesCollectorId: (int) $record->id,
        ));
    }

    public function revoke(Request $request, int $collector): JsonResponse
    {
        $viewer = $request->user();
        abort_unless($viewer->canDo('securityDevices.integrations.manage'), 403);

        $record = $this->accessibleCollector($viewer, $collector);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500', 'not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/'],
        ]);
        $updated = $this->enrollments->revoke(
            $record,
            (int) $viewer->id,
            (string) $validated['reason'],
        );

        return response()->json([
            'collector' => [
                'id' => (int) $updated->id,
                'state' => 'revoked',
                'revoked_at' => $updated->revoked_at?->toIso8601String(),
            ],
        ], 200, $this->sensitiveResponseHeaders());
    }

    private function accessibleCollector(User $viewer, int $collectorId): MonitoringCollector
    {
        $siteIds = $this->access->accessibleSiteIds($viewer);

        return MonitoringCollector::query()
            ->whereKey($collectorId)
            ->when(
                $siteIds === [],
                fn ($query) => $query->whereRaw('1 = 0'),
                fn ($query) => $query->whereIn('site_id', $siteIds),
            )
            ->firstOrFail();
    }

    private function enrollmentResponse(CollectorEnrollmentIssue $issue): JsonResponse
    {
        return response()->json([
            'enrollment' => [
                'id' => (int) $issue->enrollment->id,
                'purpose' => $issue->enrollment->replacement_collector_id === null
                    ? 'new_collector'
                    : 'collector_re_enrolment',
                'token' => $issue->plainToken,
                'expires_at' => $issue->enrollment->expires_at->toIso8601String(),
            ],
        ], 201, $this->sensitiveResponseHeaders());
    }

    /** @return array<string, string> */
    private function sensitiveResponseHeaders(): array
    {
        return [
            'Cache-Control' => 'no-store, private, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }
}
