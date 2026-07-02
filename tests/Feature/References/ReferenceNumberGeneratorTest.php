<?php

use App\Models\ClientIncident;
use App\Models\ControlRoomAlert;
use App\Services\References\ReferenceNumberGenerator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

it('allocates sequential year-scoped references', function () {
    $gen = app(ReferenceNumberGenerator::class);
    $year = now()->year;

    expect($gen->next('INC'))->toBe("INC-{$year}-0001")
        ->and($gen->next('INC'))->toBe("INC-{$year}-0002")
        ->and($gen->next('INC'))->toBe("INC-{$year}-0003");
});

it('keeps scopes independent per prefix and year', function () {
    $gen = app(ReferenceNumberGenerator::class);
    $year = now()->year;

    $gen->next('INC');
    $gen->next('INC');

    expect($gen->next('MED'))->toBe("MED-{$year}-0001")
        ->and($gen->next('INC', $year - 1))->toBe('INC-'.($year - 1).'-0001')
        ->and($gen->next('INC'))->toBe("INC-{$year}-0003");
});

it('allocates global (non-year) sequences', function () {
    $gen = app(ReferenceNumberGenerator::class);

    expect($gen->nextGlobal('HR', 5))->toBe('HR-00001')
        ->and($gen->nextGlobal('HR', 5))->toBe('HR-00002');
});

it('ensureAtLeast raises the floor but never lowers it', function () {
    $gen = app(ReferenceNumberGenerator::class);
    $year = now()->year;

    $gen->ensureAtLeast("INC-{$year}", 50);
    expect($gen->next('INC'))->toBe("INC-{$year}-0050");

    $gen->ensureAtLeast("INC-{$year}", 10); // lower — must be a no-op
    expect($gen->next('INC'))->toBe("INC-{$year}-0051");
});

it('assigns a reference number to a client incident on create', function () {
    $incident = ClientIncident::factory()->create();

    expect($incident->reference_number)->toStartWith('INC-'.now()->year.'-');

    expect(ClientIncident::factory()->create()->reference_number)
        ->not->toBe($incident->reference_number);
});

it('assigns a reference number to a control room alert on create', function () {
    $alert = ControlRoomAlert::factory()->create();

    expect($alert->reference_number)->toStartWith('CR-'.now()->year.'-');
});

it('respects an explicitly supplied reference number', function () {
    $incident = ClientIncident::factory()->create(['reference_number' => 'INC-2020-9999']);

    expect($incident->reference_number)->toBe('INC-2020-9999');
});

it('numbers within the created-at year via the year override', function () {
    $gen = app(ReferenceNumberGenerator::class);

    expect($gen->next('FA', 2024))->toBe('FA-2024-0001');

    // Sequence row persisted for the historical scope.
    expect(DB::table('reference_sequences')->where('scope', 'FA-2024')->value('next_value'))->toBe(2);
});
