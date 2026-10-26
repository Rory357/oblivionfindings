<?php

require __DIR__.'/inspect-delivery.php';

use App\Models\Client;
use App\Support\Fixtures\HistoricalClientFixture;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

$checks = [];
$check = function ($ok, $label) use (&$checks) {
    if (! $ok) {
        throw new RuntimeException($label);
    } $checks[] = $label;
};
$blocked = function ($fn, $label) use ($check) {
    $before = Client::withTrashed()->count();
    try {
        $fn();
        throw new RuntimeException('Unexpected acceptance: '.$label);
    } catch (ValidationException) {
        $check(Client::withTrashed()->count() === $before, $label);
    }
};
DB::beginTransaction();
try {
    $details = ['first_name' => 'Fictional', 'last_name' => 'CreationGuardQA'];
    $pending = Client::create($details);
    $check($pending->fresh()->status === 'onboarding' && $pending->fresh()->service_start_date === null, 'Direct model creation defaults to unstarted onboarding');
    $blocked(fn () => Client::create([...$details, 'status' => 'active']), 'Direct active creation is rejected without inserting a person');
    $blocked(fn () => (new Client([...$details, 'status' => 'active']))->saveQuietly(), 'Quiet creation cannot bypass activation protection');
    $blocked(fn () => Client::withoutEvents(fn () => Client::create([...$details, 'status' => 'active'])), 'Disabling events cannot bypass the model save boundary');
    $blocked(fn () => (new Client)->forceFill([...$details, 'status' => 'ACTIVE '])->save(), 'Force fill and case or whitespace variants cannot create active people');
    $blocked(fn () => Client::create([...$details, 'status' => 'onboarding', 'service_start_date' => '2026-10-01']), 'New onboarding profiles cannot fabricate actual commencement dates');
    $inactive = Client::create([...$details, 'status' => 'inactive']);
    $check($inactive->fresh()->status === 'inactive', 'Explicit inactive creation remains supported');
    $attributes = $pending->getAttributes();
    unset($attributes['id'], $attributes['status']);
    $rawId = DB::table('clients')->insertGetId($attributes);
    $check(DB::table('clients')->where('id', $rawId)->value('status') === 'onboarding', 'Low-level omitted-status insert uses the new database default');
    $fixture = Client::factory()->create(['first_name' => 'Fictional historical fixture', 'status' => 'active', 'service_start_date' => '2025-03-04']);
    $check($fixture->fresh()->status === 'active' && $fixture->fresh()->service_start_date->toDateString() === '2025-03-04', 'Explicit non-production historical factory fixtures preserve their prior-state semantics');
    $fixture->update(['preferred_name' => 'Historical support retained']);
    $check($fixture->fresh()->status === 'active', 'Unrelated edits do not deactivate an existing historical person');
    $made = Client::factory()->make();
    $check(! $made->exists, 'Factory make remains unsaved');
    $blocked(fn () => $made->save(), 'Saving a made active fixture directly still uses the runtime guard');
    try {
        HistoricalClientFixture::save($fixture);
        throw new RuntimeException('Existing fixture mutation accepted');
    } catch (LogicException) {
        $check(true, 'Historical fixture helper cannot mutate existing people');
    }
    $environment = app()->environment();
    app()->instance('env', 'production');
    try {
        HistoricalClientFixture::save(new Client([...$details, 'status' => 'active']));
        throw new RuntimeException('Production fixture accepted');
    } catch (LogicException) {
        $check(true, 'Historical fixture helper is unavailable in production');
    } finally {
        app()->instance('env', $environment);
    }
    echo json_encode(['passed' => $checks, 'count' => count($checks)]).PHP_EOL;
} finally {
    DB::rollBack();
}
