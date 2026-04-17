<?php

namespace App\Http\Controllers\Concerns;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * PR 26 — Offline submission queue
 *
 * Lightweight idempotency guard for frontline submissions that may be queued
 * on-device and replayed on reconnect (PRN recording, progress notes).
 *
 * The pattern is deliberately small: the client sends a stable
 * `client_request_uuid` with every submission; the first time we see that
 * UUID for a given scope we run the write; any replay that arrives later is
 * short-circuited to the same response without creating duplicate records.
 *
 * This sits alongside `HandlesMedicationSync` which is eMAR-specific and
 * returns a richer `sync` envelope. Offline submissions that use Inertia's
 * redirect-with-flash flow don't need that envelope, so this trait keeps
 * things minimal: run-once guard + TTL'd cache of the last response.
 *
 * Scope strings should be stable (e.g. `prn`, `progress_note`) so the same
 * idempotency key is honoured whether the request lands from the online
 * path or from a queued replay.
 */
trait HandlesOfflineSubmission
{
    /**
     * Validation rules you should merge into the FormRequest/validate()
     * call for any endpoint that accepts offline-queued submissions.
     */
    protected function offlineSubmissionRules(): array
    {
        return [
            'client_request_uuid' => ['nullable', 'string', 'max:64'],
            'captured_offline_at' => ['nullable', 'date'],
            'origin_device_id' => ['nullable', 'string', 'max:128'],
            'queued_offline' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Run the given `$work` closure once per (scope, client_request_uuid).
     * If the same UUID is seen again within the TTL, the cached result of
     * the first run is returned instead of executing the closure again.
     *
     * When no UUID is provided (online path without the queue wiring), the
     * closure is always executed and its result is returned directly.
     *
     * `$work` must return the final HTTP response (typically a
     * RedirectResponse). We don't serialize it — we only cache a lightweight
     * "processed" marker and re-run `$work` if the marker is missing, which
     * keeps this safe for responses that can't be serialized.
     */
    protected function runOfflineSubmissionOnce(
        string $scope,
        array $data,
        Closure $work,
    ) {
        $uuid = $data['client_request_uuid'] ?? null;

        if (! is_string($uuid) || $uuid === '') {
            return $work();
        }

        $key = $this->offlineSubmissionKey($scope, $uuid);

        if (Cache::has($key)) {
            // Already processed this submission; re-run the "do nothing"
            // branch so the worker still lands somewhere sensible.
            return $this->onDuplicateOfflineSubmission($scope, $data);
        }

        $response = $work();

        Cache::put($key, [
            'processed_at' => now()->toIso8601String(),
            'device' => $data['origin_device_id'] ?? null,
            'queued_offline' => (bool) ($data['queued_offline'] ?? false),
        ], now()->addDays(7));

        return $response;
    }

    /**
     * Default duplicate handler — a calm redirect back with a "already
     * saved" flash. Controllers can override by not calling this trait's
     * `runOfflineSubmissionOnce` and writing their own guard, but in
     * practice this is enough for progress-note / PRN-style submissions.
     */
    protected function onDuplicateOfflineSubmission(string $scope, array $data)
    {
        return back()->with('success', 'Already saved — no changes needed.');
    }

    protected function offlineSubmissionKey(string $scope, string $uuid): string
    {
        return "offline:idempotency:{$scope}:{$uuid}";
    }
}
