<?php

use App\Models\Client;
use App\Support\Fixtures\HistoricalClientFixture;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../../Support/ReferralFixtures.php';

it('keeps the established active default for omitted status at the model and database boundaries', function () {
    $person = Client::create(['first_name' => 'Fictional', 'last_name' => 'Established']);
    expect($person->fresh()->status)->toBe('active');
    $attributes = $person->getAttributes();
    unset($attributes['id'], $attributes['status']);
    $id = DB::table('clients')->insertGetId($attributes);
    expect(Client::findOrFail($id)->status)->toBe('active');
    // Non-referral entry points keep creating active people with a recorded start date.
    $direct = Client::create(['first_name' => 'Fictional', 'last_name' => 'Direct', 'status' => 'active', 'service_start_date' => '2026-10-01']);
    expect($direct->fresh()->status)->toBe('active')->and($direct->fresh()->service_start_date->toDateString())->toBe('2026-10-01');
});

it('defaults new people to onboarding when the organisation requires a commencement review for everyone', function () {
    requireCommencementReviewForEveryone();
    $person = Client::create(['first_name' => 'Fictional', 'last_name' => 'Unstarted']);
    expect($person->fresh()->status)->toBe('onboarding')->and($person->fresh()->service_start_date)->toBeNull();
});

it('rejects new active people even through quiet saves or disabled events when the review is required for everyone', function () {
    requireCommencementReviewForEveryone();
    $input = ['first_name' => 'Fictional', 'last_name' => 'Blocked', 'status' => 'active'];
    expect(fn () => Client::create($input))->toThrow(ValidationException::class);
    expect(fn () => (new Client($input))->saveQuietly())->toThrow(ValidationException::class);
    expect(fn () => Client::withoutEvents(fn () => Client::create($input)))->toThrow(ValidationException::class);
    expect(fn () => (new Client)->forceFill([...$input, 'status' => 'ACTIVE '])->save())->toThrow(ValidationException::class);
    expect(Client::count())->toBe(0);
});

it('rejects a fabricated start date while permitting an unstarted inactive profile when the review is required', function () {
    requireCommencementReviewForEveryone();
    $input = ['first_name' => 'Fictional', 'last_name' => 'Unstarted', 'status' => 'inactive'];
    expect(fn () => Client::create([...$input, 'service_start_date' => today()->toDateString()]))->toThrow(ValidationException::class);
    expect(Client::create($input)->fresh()->status)->toBe('inactive');
});

it('preserves explicit non-production historical fixtures without weakening runtime saves', function () {
    requireCommencementReviewForEveryone();
    $historical = Client::factory()->create(['status' => 'active', 'service_start_date' => '2025-03-04']);
    expect($historical->fresh()->status)->toBe('active')->and($historical->fresh()->service_start_date->toDateString())->toBe('2025-03-04');
    $historical->update(['preferred_name' => 'Existing support']);
    expect($historical->fresh()->status)->toBe('active');
    expect(fn () => HistoricalClientFixture::save($historical))->toThrow(LogicException::class);
    $made = Client::factory()->make();
    expect($made->exists)->toBeFalse();
    expect(fn () => $made->save())->toThrow(ValidationException::class);
});

it('forbids historical fixture seeding in production', function () {
    $environment = app()->environment();
    app()->instance('env', 'production');
    try {
        expect(fn () => HistoricalClientFixture::save(new Client(['first_name' => 'Fictional', 'last_name' => 'Blocked', 'status' => 'active'])))->toThrow(LogicException::class);
        expect(Client::count())->toBe(0);
    } finally {
        app()->instance('env', $environment);
    }
});

it('recreates the identity write mutex row on demand when a schema dump omitted it', function () {
    DB::table('client_identity_write_mutex')->delete();
    $person = Client::create(['first_name' => 'Fictional', 'last_name' => 'Mutex']);
    expect($person->exists)->toBeTrue()->and(DB::table('client_identity_write_mutex')->where('id', 1)->exists())->toBeTrue();
});
