<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\CoverageGapAcknowledgement;
use App\Models\SiteCoverageRequirement;
use App\Services\AuditLogger;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use App\Services\Operations\WorkforceMutationGuard;
use App\Services\ShiftSignalService;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        return $this->executeWindow($key, $request, $siteAccess, 'clear');
    }

    protected function store(
        string $key,
        Request $request,
        UserSiteAccessService $siteAccess,
        string $state,
    ) {
        return $this->executeWindow($key, $request, $siteAccess,
            $state === CoverageGapAcknowledgement::STATE_ACKED ? 'ack' : 'dismiss');
    }

    private function executeWindow(string $key, Request $request, UserSiteAccessService $siteAccess, string $action)
    {
        $request->session()->forget('coverage_gap_result');
        $requesterId = $request->user()?->id;
        abort_unless($requesterId !== null && $requesterId > 0, 403);
        $rootEntry = DB::transactionLevel() === 0 && ! DB::connection()->getPdo()->inTransaction();
        abort_if(DB::transactionLevel() === 0 && ! $rootEntry, 409, 'Coverage review cannot run inside an externally owned transaction.');
        $data = $this->validatedWindowPayload($request, $action === 'dismiss');
        $key = urldecode($key);
        $saved = DB::transaction(function () use ($request, $requesterId, $data, $key, $siteAccess, $action): array {
            app(WorkforceMutationGuard::class)->lock();
            $actor = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($requesterId ?? 0, ['rostering.viewAny', 'reports.viewAny']);
            abort_unless($actor->isApproved() && $actor->canDo('rostering.viewAny'), 403);

            return CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($request, $data, $key, $siteAccess, $action, $actor): array {
                abort_unless(in_array((int) $data['site_id'], $siteAccess->accessibleSiteIds($actor, ['reports.viewAny'], $reads), true), 403, UserSiteAccessService::DEFAULT_MESSAGE);
                $this->assertRequirementBelongsToSite($data, $reads);
                $this->assertKeyMatchesPayload($key, $data);
                // Preserve the installed key and DATETIME matching convention for legacy reviews.
                $starts = Carbon::parse($data['window_starts_at'])->startOfSecond();
                $ends = Carbon::parse($data['window_ends_at'])->startOfSecond();
                if (! $ends->greaterThan($starts)) {
                    throw ValidationException::withMessages(['window_ends_at' => 'The coverage window must end after it starts.']);
                }
                $window = ['site_id' => (int) $data['site_id'], 'coverage_requirement_id' => empty($data['coverage_requirement_id']) ? null : (int) $data['coverage_requirement_id'],
                    'coverage_window_key' => $key, 'window_starts_at' => $starts->copy()->utc()->toISOString(), 'window_ends_at' => $ends->copy()->utc()->toISOString()];
                $matchingWindow = [...$window, 'window_starts_at' => $starts, 'window_ends_at' => $ends];
                $time = now('UTC')->startOfSecond();
                $active = $this->activeAcknowledgementsForWindow($key, $matchingWindow)->orderBy('id')->lockForUpdate()->get();
                $auditTarget = $active->sortByDesc('created_at')->first();
                $cleared = [];
                foreach ($active as $row) {
                    $cleared[$row->id] = [...$row->getRawOriginal(), 'cleared_at' => $time->format($row->getDateFormat())];
                    $row->cleared_at = $time;
                    if (! $row->save()) {
                        $this->refuseSave();
                    }
                }
                $new = null;
                $values = null;
                if ($action !== 'clear') {
                    $values = [...$window, 'window_starts_at' => $starts, 'window_ends_at' => $ends,
                        'state' => $action === 'ack' ? CoverageGapAcknowledgement::STATE_ACKED : CoverageGapAcknowledgement::STATE_DISMISSED,
                        'reason' => $data['reason'] ?? null, 'actor_user_id' => (int) $actor->id, 'created_at' => $time, 'cleared_at' => null];
                    $new = new CoverageGapAcknowledgement($values);
                    if (! $new->save()) {
                        $this->refuseSave();
                    }
                    $auditTarget = $new;
                }
                $auditAction = 'rostering.coverage.'.$action;
                $meta = ['coverage_window_key' => $key, 'site_id' => $window['site_id'], 'coverage_requirement_id' => $window['coverage_requirement_id'],
                    'window_starts_at' => $starts->toIso8601String(), 'window_ends_at' => $ends->toIso8601String()];
                $auditScope = AuditLog::query()->where('user_id', $actor->id)->where('action', $auditAction)
                    ->where('auditable_type', $auditTarget?->getMorphClass())->where('auditable_id', $auditTarget?->id)
                    ->where('meta->coverage_window_key', $key);
                $lastAuditId = (int) ((clone $auditScope)->orderByDesc('id')->lockForUpdate()->value('id') ?? 0);
                AuditLogger::logOrFail($auditAction, $auditTarget, $meta, $request);
                $audits = (clone $auditScope)->where('id', '>', $lastAuditId)->orderBy('id')->lockForUpdate()->get();
                $audit = $audits->first();
                $actualMeta = $audit?->meta;
                $expectedMeta = $meta;
                if (is_array($actualMeta)) {
                    ksort($actualMeta);
                }
                ksort($expectedMeta);
                if ($audits->count() !== 1 || $actualMeta !== $expectedMeta) {
                    $this->refuseSave();
                }
                foreach ($cleared as $id => $expected) {
                    if (CoverageGapAcknowledgement::whereKey($id)->lockForUpdate()->first()?->getRawOriginal() !== $expected) {
                        $this->refuseSave();
                    }
                }
                $stored = $new ? CoverageGapAcknowledgement::whereKey($new->id)->lockForUpdate()->first() : null;
                if ($new) {
                    if (! $stored) {
                        $this->refuseSave();
                    }
                    foreach ($values as $field => $expected) {
                        $actual = $stored->{$field};
                        if ($expected instanceof Carbon) {
                            $actual = $stored->getRawOriginal($field);
                            $expected = $expected->format($stored->getDateFormat());
                        }
                        if (in_array($field, ['site_id', 'coverage_requirement_id', 'actor_user_id'], true)) {
                            $actual = $actual === null ? null : (int) $actual;
                        }
                        if ($actual !== $expected) {
                            $this->refuseSave();
                        }
                    }
                    $stored->setRelation('actor', (clone $actor)->setRelations([])->setVisible(['id', 'name']));
                }
                $activeIds = $this->activeAcknowledgementsForWindow($key, $matchingWindow)->orderBy('id')->lockForUpdate()->pluck('id')->map(fn ($id) => (int) $id)->all();
                if ($activeIds !== ($stored ? [(int) $stored->id] : [])) {
                    $this->refuseSave();
                }

                return ['actor_id' => (int) $actor->id, 'action' => $action, 'request_id' => $data['request_id'] ?? null, 'window' => $window,
                    'stored_window' => ['window_starts_at' => $starts->format('Y-m-d H:i:s'), 'window_ends_at' => $ends->format('Y-m-d H:i:s')],
                    'outcome' => $action === 'clear' ? ($cleared === [] ? 'unchanged' : 'cleared') : 'recorded',
                    'changed' => $stored !== null || $cleared !== [], 'state' => $stored?->state, 'acknowledgement_id' => $stored ? (int) $stored->id : null,
                    'cleared_ids' => array_map('intval', array_keys($cleared)), 'reason' => $stored?->reason, 'audit_id' => (int) $audit->id,
                    'acknowledgement' => $stored?->toArray()];
            });
        });
        $result = $this->committedResult($rootEntry, $requesterId, $request, $saved);
        $payload = ['status' => $saved['state'] ?? 'cleared', 'result' => $result];
        if ($saved['acknowledgement'] !== null) {
            $payload['acknowledgement'] = $saved['acknowledgement'];
        }

        return $this->respond($request, $payload);
    }

    private function refuseSave(): never
    {
        throw ValidationException::withMessages(['coverage_window_key' => 'The coverage review could not be recorded as requested. Refresh the window and review it again.']);
    }

    protected function committedResult(bool $rootEntry, ?int $requesterId, Request $request, array $saved): ?array
    {
        try {
            if (! $rootEntry || DB::transactionLevel() !== 0 || DB::connection()->getPdo()->inTransaction()
                || $saved['actor_id'] !== $requesterId || $request->user()?->id !== $requesterId) {
                return null;
            }
            $result = ['version' => 1, 'scope' => 'coverage_gap', ...array_diff_key($saved, ['acknowledgement' => true]),
                'staffing_resolved' => false, 'committed_at' => now('UTC')->startOfSecond()->toISOString()];
            $this->flashCommittedResult($request, $result);

            return $result;
        } catch (\Throwable $exception) {
            try {
                Log::warning('Committed coverage review result could not be presented', ['exception_class' => $exception::class]);
            } catch (\Throwable) {
            }

            return null;
        }
    }

    protected function flashCommittedResult(Request $request, array $result): void
    {
        $request->session()->flash('coverage_gap_result', $result);
    }

    protected function validatedWindowPayload(Request $request, bool $reasonRequired): array
    {
        return $request->validate([
            'site_id' => ['required', 'integer', 'min:1'],
            'coverage_requirement_id' => ['nullable', 'integer', 'min:1'],
            'request_id' => ['nullable', 'uuid'],
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

    /** @param array<string, mixed> $data */
    protected function assertRequirementBelongsToSite(array $data, CurrentAuthorizationReads $reads): void
    {
        $requirementId = $data['coverage_requirement_id'] ?? null;
        if (! $requirementId) {
            return;
        }

        abort_unless(
            $reads->query(SiteCoverageRequirement::query()
                ->whereKey((int) $requirementId)
                ->where('site_id', (int) $data['site_id']))
                ->exists(),
            403,
            UserSiteAccessService::DEFAULT_MESSAGE,
        );
    }

    /** @param array<string, mixed> $data */
    protected function activeAcknowledgementsForWindow(string $key, array $data): Builder
    {
        return CoverageGapAcknowledgement::query()
            ->where('site_id', (int) $data['site_id'])
            ->where('coverage_window_key', $key)
            ->where('window_starts_at', Carbon::parse($data['window_starts_at']))
            ->where('window_ends_at', Carbon::parse($data['window_ends_at']))
            ->when(
                $data['coverage_requirement_id'] ?? null,
                fn (Builder $query, $requirementId) => $query->where('coverage_requirement_id', (int) $requirementId),
                fn (Builder $query) => $query->whereNull('coverage_requirement_id'),
            )
            ->whereNull('cleared_at');
    }

    protected function respond(Request $request, array $payload)
    {
        if ($request->expectsJson()) {
            return response()->json($payload);
        }

        $response = redirect($request->input('return_to') ?: url()->previous());

        return $payload['result'] !== null ? $response->with('success', 'Coverage gap updated.') : $response;
    }
}
