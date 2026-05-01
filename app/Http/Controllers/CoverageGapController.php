<?php

namespace App\Http\Controllers;

use App\Models\CoverageGapAcknowledgement;
use App\Models\Site;
use App\Services\AuditLogger;
use App\Services\ShiftSignalService;
use App\Services\UserSiteAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class CoverageGapController extends Controller
{
    public function ack(string $key, Request $request, UserSiteAccessService $siteAccess)
    {
        return $this->store($key, $request, $siteAccess, CoverageGapAcknowledgement::STATE_ACKED);
    }

    public function dismiss(string $key, Request $request, UserSiteAccessService $siteAccess)
    {
        return $this->store($key, $request, $siteAccess, CoverageGapAcknowledgement::STATE_DISMISSED);
    }

    public function clear(string $key, Request $request, UserSiteAccessService $siteAccess)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('rostering.viewAny'), 403);

        $key = urldecode($key);
        $data = $this->validatedWindowPayload($request, false);
        $siteAccess->assertCanAccessSiteId($auth, (int) $data['site_id'], ['reports.viewAny']);
        $this->assertKeyMatchesPayload($key, $data);

        $acknowledgement = CoverageGapAcknowledgement::query()
            ->where('coverage_window_key', $key)
            ->whereNull('cleared_at')
            ->latest('created_at')
            ->first();

        CoverageGapAcknowledgement::query()
            ->where('coverage_window_key', $key)
            ->whereNull('cleared_at')
            ->update(['cleared_at' => now()]);

        AuditLogger::log('rostering.coverage.clear', $acknowledgement, [
            'coverage_window_key' => $key,
            'site_id' => (int) $data['site_id'],
            'coverage_requirement_id' => $data['coverage_requirement_id'] ?? null,
            'window_starts_at' => Carbon::parse($data['window_starts_at'])->toIso8601String(),
            'window_ends_at' => Carbon::parse($data['window_ends_at'])->toIso8601String(),
        ], $request);

        return $this->respond($request, ['status' => 'cleared']);
    }

    protected function store(
        string $key,
        Request $request,
        UserSiteAccessService $siteAccess,
        string $state,
    ) {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('rostering.viewAny'), 403);

        $key = urldecode($key);
        $data = $this->validatedWindowPayload($request, $state === CoverageGapAcknowledgement::STATE_DISMISSED);
        $siteAccess->assertCanAccessSiteId($auth, (int) $data['site_id'], ['reports.viewAny']);
        $this->assertKeyMatchesPayload($key, $data);

        $site = Site::query()->findOrFail((int) $data['site_id']);

        CoverageGapAcknowledgement::query()
            ->where('coverage_window_key', $key)
            ->whereNull('cleared_at')
            ->update(['cleared_at' => now()]);

        $acknowledgement = CoverageGapAcknowledgement::create([
            'organization_id' => $site->organization_id ?? $auth->organization_id,
            'site_id' => (int) $data['site_id'],
            'coverage_requirement_id' => $data['coverage_requirement_id'] ?? null,
            'coverage_window_key' => $key,
            'window_starts_at' => Carbon::parse($data['window_starts_at']),
            'window_ends_at' => Carbon::parse($data['window_ends_at']),
            'state' => $state,
            'reason' => $data['reason'] ?? null,
            'actor_user_id' => $auth->id,
            'created_at' => now(),
        ]);

        $auditAction = $state === CoverageGapAcknowledgement::STATE_ACKED ? 'ack' : 'dismiss';

        AuditLogger::log('rostering.coverage.'.$auditAction, $acknowledgement, [
            'coverage_window_key' => $key,
            'site_id' => (int) $data['site_id'],
            'coverage_requirement_id' => $data['coverage_requirement_id'] ?? null,
            'window_starts_at' => Carbon::parse($data['window_starts_at'])->toIso8601String(),
            'window_ends_at' => Carbon::parse($data['window_ends_at'])->toIso8601String(),
        ], $request);

        return $this->respond($request, [
            'status' => $state,
            'acknowledgement' => $acknowledgement->load('actor:id,name'),
        ]);
    }

    protected function validatedWindowPayload(Request $request, bool $reasonRequired): array
    {
        return $request->validate([
            'site_id' => ['required', 'integer', 'exists:sites,id'],
            'coverage_requirement_id' => ['nullable', 'integer', 'exists:site_coverage_requirements,id'],
            'window_starts_at' => ['required', 'date'],
            'window_ends_at' => ['required', 'date', 'after:window_starts_at'],
            'reason' => [$reasonRequired ? 'required' : 'nullable', 'string', 'max:1000'],
            'return_to' => ['nullable', 'string', 'max:2048'],
        ]);
    }

    protected function assertKeyMatchesPayload(string $key, array $data): void
    {
        $expected = app(ShiftSignalService::class)->buildCoverageWindowKey([
            'site_id' => (int) $data['site_id'],
            'rule_id' => $data['coverage_requirement_id'] ?? null,
            'starts_at' => Carbon::parse($data['window_starts_at'])->toIso8601String(),
            'ends_at' => Carbon::parse($data['window_ends_at'])->toIso8601String(),
        ]);

        if ($expected !== $key) {
            throw ValidationException::withMessages([
                'coverage_window_key' => 'This coverage action no longer matches the selected window.',
            ]);
        }
    }

    protected function respond(Request $request, array $payload)
    {
        if ($request->expectsJson()) {
            return response()->json($payload);
        }

        return redirect($request->input('return_to') ?: url()->previous())
            ->with('success', 'Coverage gap updated.');
    }
}
