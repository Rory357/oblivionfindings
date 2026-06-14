<?php

use App\Domain\Finance\Models\FinIrdFiling;
use App\Domain\Finance\Services\IrdFilingService;

/**
 * The IRD "submit" path faked a successful filing (random IRD-xxxx reference,
 * "received and queued") whenever any api_key was set — so a user could believe a
 * GST return was transmitted to IRD when nothing was sent. Submission now refuses
 * unless an explicit simulation is enabled, and a simulated submission is clearly
 * labelled (NOT transmitted).
 */
function validatedFiling(): FinIrdFiling
{
    return FinIrdFiling::create([
        'organization_id' => 1, 'ird_number' => '123456789', 'filing_type' => 'gst', 'status' => 'validated',
        'period_from' => now()->startOfMonth()->toDateString(), 'period_to' => now()->endOfMonth()->toDateString(),
        'filing_data' => [], 'total_amount' => '0.00',
    ]);
}

it('refuses to fake a submission when simulation is disabled', function () {
    config(['services.ird.simulation_enabled' => false]);

    $filing = app(IrdFilingService::class)->submitFiling(validatedFiling());

    expect($filing->status)->toBe('error')
        ->and($filing->error_message)->toContain('not yet available')
        ->and($filing->ird_reference)->toBeNull();
});

it('records a clearly-labelled SIMULATED submission when simulation is enabled', function () {
    config(['services.ird.simulation_enabled' => true]);

    $filing = app(IrdFilingService::class)->submitFiling(validatedFiling());

    expect($filing->status)->toBe('submitted')
        ->and($filing->ird_response['simulated'] ?? false)->toBeTrue()
        ->and($filing->ird_reference)->toStartWith('SIM-')
        ->and($filing->ird_response['message'])->toContain('SIMULATED');
});
