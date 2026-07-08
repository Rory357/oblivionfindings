<?php

use App\Models\User;
use App\Models\WorkplaceInjury;

/**
 * Seam S4 — Injuries (H&S) → HR. `WorkplaceInjury` is owned by H&S: a source
 * audit finds it referenced in 13 sites app-wide, ALL under HealthSafety /
 * Governance / Tasks — ZERO under app/Domain/Hr, app/Http/Controllers/Hr, or
 * resources/js/pages/hr. HR therefore has no path to create/update/delete an
 * injury (the one-owner-per-fact invariant holds by absence). This test locks
 * the per-employee ownership contract that a future read-only HR surface would
 * federate on. See Decision D-8: read-only HR injury surfacing is NOT built
 * today (no INJURIES_HR_SURFACING_PLAN.md exists) — a feature decision, not a
 * drift/bug.
 */
test('S4 seam: a workplace injury is H&S-owned per-employee data that HR would federate read-only', function () {
    $employee = User::factory()->create();
    $injury = WorkplaceInjury::factory()->create(['user_id' => $employee->id]);

    // Per-employee linkage — the join a read-only HR surface would federate on.
    expect($injury->user)->not->toBeNull();
    expect($injury->user->id)->toBe($employee->id);

    // The injury is retrievable by the injured employee's id (the read path a
    // read-only HR surface would use); H&S remains the sole writer.
    expect(WorkplaceInjury::query()->where('user_id', $employee->id)->count())->toBe(1);
});
