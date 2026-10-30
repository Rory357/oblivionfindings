<?php

use App\Models\Client;
use App\Models\ServiceContext;
use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../../Support/ReferralFixtures.php';

it('rejects an old onboarding instance changing scope after a concurrent activation', function () {
    $site = Site::factory()->create();
    $person = Client::factory()->create(['status' => 'onboarding', 'site_id' => $site->id]);
    $stale = $person->fresh();
    // Represents another committed writer; raw fixture mutation is not an activation API.
    DB::table('clients')->where('id', $person->id)->update(['status' => 'active']);
    expect(fn () => $stale->update(['site_id' => null]))->toThrow(ValidationException::class);
    expect($person->fresh()->site_id)->toBe($site->id)->and($person->fresh()->status)->toBe('active');
});

it('rejects stale status, context and actual-date snapshots even during quiet saves', function () {
    $site = Site::factory()->create();
    $context = ServiceContext::create(['name' => 'Fictional support', 'type' => 'home_support', 'is_active' => true]);
    foreach (['status' => 'active', 'site_id' => $site->id, 'service_context_id' => $context->id, 'service_start_date' => '2026-10-01'] as $field => $value) {
        $person = Client::factory()->create(['status' => 'onboarding']);
        $stale = $person->fresh();
        DB::table('clients')->where('id', $person->id)->update([$field => $value]);
        $before = $person->fresh()->only(['status', 'site_id', 'service_context_id', 'service_start_date']);
        $stale->forceFill(['status' => 'inactive']);
        expect(fn () => $stale->saveQuietly())->toThrow(ValidationException::class);
        expect($person->fresh()->only(array_keys($before)))->toEqual($before);
    }
});

it('preserves unrelated stale edits without overwriting current service state', function () {
    $person = Client::factory()->create(['status' => 'onboarding']);
    $stale = $person->fresh();
    DB::table('clients')->where('id', $person->id)->update(['status' => 'active']);
    $stale->update(['preferred_name' => 'Chosen name']);
    expect($person->fresh()->status)->toBe('active')->and($person->fresh()->preferred_name)->toBe('Chosen name');
});

it('cannot activate using a case or whitespace variant of active when the review is required', function () {
    requireCommencementReviewForEveryone();
    $person = Client::factory()->create(['status' => 'onboarding']);
    expect(fn () => $person->update(['status' => ' ACTIVE ']))->toThrow(HttpException::class);
    expect($person->fresh()->status)->toBe('onboarding');
});

it('permits current unstarted scope changes and preserves historical date casting', function () {
    $person = Client::factory()->create(['status' => 'onboarding']);
    $site = Site::factory()->create();
    $person->update(['site_id' => $site->id]);
    expect($person->fresh()->site_id)->toBe($site->id);
    $historical = Client::factory()->create(['site_id' => $site->id, 'status' => 'active', 'service_start_date' => '2025-03-04']);
    $historical->update(['status' => 'inactive']);
    expect($historical->fresh()->status)->toBe('inactive')->and($historical->fresh()->service_start_date->toDateString())->toBe('2025-03-04');
});
