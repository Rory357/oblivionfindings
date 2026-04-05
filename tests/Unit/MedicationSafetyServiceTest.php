<?php

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Services\MedicationSafetyService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

beforeEach(function () {
    $this->service = new MedicationSafetyService();
});

// ─── validateDoseAgainstPrescribed ─────────────────────────────────────

test('validateDoseAgainstPrescribed returns warning when dose exceeds 120% of prescribed', function () {
    $medication = Mockery::mock(ClientMedication::class)->makePartial();
    $medication->dose_amount = 100;

    $result = $this->service->validateDoseAgainstPrescribed($medication, '150mg');

    expect($result)->not->toBeNull()
        ->and($result['type'])->toBe('dose_exceeds_prescribed')
        ->and($result['severity'])->toBe('warning')
        ->and($result['details']['dose_given'])->toBe(150.0)
        ->and($result['details']['dose_prescribed'])->toBe(100.0)
        ->and($result['details']['percent_over'])->toBe(50.0);
});

test('validateDoseAgainstPrescribed returns no warning when dose is within range', function () {
    $medication = Mockery::mock(ClientMedication::class)->makePartial();
    $medication->dose_amount = 100;

    $result = $this->service->validateDoseAgainstPrescribed($medication, '100mg');

    expect($result)->toBeNull();
});

test('validateDoseAgainstPrescribed returns no warning at exactly 120% threshold', function () {
    $medication = Mockery::mock(ClientMedication::class)->makePartial();
    $medication->dose_amount = 100;

    $result = $this->service->validateDoseAgainstPrescribed($medication, '120mg');

    expect($result)->toBeNull();
});

test('validateDoseAgainstPrescribed returns warning when dose is just above 120%', function () {
    $medication = Mockery::mock(ClientMedication::class)->makePartial();
    $medication->dose_amount = 100;

    $result = $this->service->validateDoseAgainstPrescribed($medication, '121mg');

    expect($result)->not->toBeNull()
        ->and($result['type'])->toBe('dose_exceeds_prescribed');
});

test('validateDoseAgainstPrescribed returns null when dose has no numeric value', function () {
    $medication = Mockery::mock(ClientMedication::class)->makePartial();
    $medication->dose_amount = 100;

    $result = $this->service->validateDoseAgainstPrescribed($medication, 'as needed');

    expect($result)->toBeNull();
});

test('validateDoseAgainstPrescribed returns null when prescribed dose is zero', function () {
    $medication = Mockery::mock(ClientMedication::class)->makePartial();
    $medication->dose_amount = 0;

    $result = $this->service->validateDoseAgainstPrescribed($medication, '50mg');

    expect($result)->toBeNull();
});

// ─── checkPrnLimits ────────────────────────────────────────────────────

test('checkPrnLimits blocks when daily limit reached', function () {
    $medication = Mockery::mock(ClientMedication::class)->makePartial();
    $medication->is_prn = true;
    $medication->max_per_day = '4';

    // Mock the prnCountLast24Hours accessor to return 4 (at limit)
    $medication->shouldReceive('getAttribute')
        ->with('prnCountLast24Hours')
        ->andReturn(4);

    $result = $this->service->checkPrnLimits($medication);

    expect($result['blocked'])->toBeTrue()
        ->and($result['details']['count_24h'])->toBe(4)
        ->and($result['details']['max_per_day'])->toBe(4)
        ->and($result['details']['remaining'])->toBe(0);
});

test('checkPrnLimits does not block when under limit', function () {
    $medication = Mockery::mock(ClientMedication::class)->makePartial();
    $medication->is_prn = true;
    $medication->max_per_day = '4';

    $medication->shouldReceive('getAttribute')
        ->with('prnCountLast24Hours')
        ->andReturn(2);

    $result = $this->service->checkPrnLimits($medication);

    expect($result['blocked'])->toBeFalse()
        ->and($result['details']['remaining'])->toBe(2);
});

test('checkPrnLimits shows near limit warning at 75% usage', function () {
    $medication = Mockery::mock(ClientMedication::class)->makePartial();
    $medication->is_prn = true;
    $medication->max_per_day = '4';

    $medication->shouldReceive('getAttribute')
        ->with('prnCountLast24Hours')
        ->andReturn(3);

    $result = $this->service->checkPrnLimits($medication);

    expect($result['blocked'])->toBeFalse()
        ->and($result['near_limit'])->toBeTrue()
        ->and($result['details']['remaining'])->toBe(1);
});

test('checkPrnLimits returns safe when not a PRN medication', function () {
    $medication = Mockery::mock(ClientMedication::class)->makePartial();
    $medication->is_prn = false;
    $medication->max_per_day = null;

    $result = $this->service->checkPrnLimits($medication);

    expect($result['blocked'])->toBeFalse()
        ->and($result['near_limit'])->toBeFalse();
});

// ─── checkPrnInterval ──────────────────────────────────────────────────

test('checkPrnInterval blocks when minimum hours not elapsed', function () {
    $lastAdminTime = Carbon::now()->subMinutes(30); // 30 minutes ago

    $lastAdmin = Mockery::mock(ClientMedicationAdministration::class)->makePartial();
    $lastAdmin->status = 'given';
    $lastAdmin->administered_at = $lastAdminTime;

    // Build the query chain mock
    $query = Mockery::mock();
    $query->shouldReceive('where')->with('status', 'given')->andReturnSelf();
    $query->shouldReceive('orderByDesc')->with('administered_at')->andReturnSelf();
    $query->shouldReceive('first')->andReturn($lastAdmin);

    $medication = Mockery::mock(ClientMedication::class)->makePartial();
    $medication->min_hours_between_doses = 4;
    $medication->shouldReceive('administrations')->andReturn($query);

    $result = $this->service->checkPrnInterval($medication);

    expect($result['blocked'])->toBeTrue()
        ->and($result['details']['min_hours_between_doses'])->toBe(4.0)
        ->and($result['details']['hours_remaining'])->toBeGreaterThan(0);
});

test('checkPrnInterval does not block when minimum hours have elapsed', function () {
    $lastAdminTime = Carbon::now()->subHours(5); // 5 hours ago

    $lastAdmin = Mockery::mock(ClientMedicationAdministration::class)->makePartial();
    $lastAdmin->status = 'given';
    $lastAdmin->administered_at = $lastAdminTime;

    $query = Mockery::mock();
    $query->shouldReceive('where')->with('status', 'given')->andReturnSelf();
    $query->shouldReceive('orderByDesc')->with('administered_at')->andReturnSelf();
    $query->shouldReceive('first')->andReturn($lastAdmin);

    $medication = Mockery::mock(ClientMedication::class)->makePartial();
    $medication->min_hours_between_doses = 4;
    $medication->shouldReceive('administrations')->andReturn($query);

    $result = $this->service->checkPrnInterval($medication);

    expect($result['blocked'])->toBeFalse();
});

test('checkPrnInterval does not block when no previous administrations', function () {
    $query = Mockery::mock();
    $query->shouldReceive('where')->with('status', 'given')->andReturnSelf();
    $query->shouldReceive('orderByDesc')->with('administered_at')->andReturnSelf();
    $query->shouldReceive('first')->andReturnNull();

    $medication = Mockery::mock(ClientMedication::class)->makePartial();
    $medication->min_hours_between_doses = 4;
    $medication->shouldReceive('administrations')->andReturn($query);

    $result = $this->service->checkPrnInterval($medication);

    expect($result['blocked'])->toBeFalse();
});

test('checkPrnInterval returns unblocked when min_hours is zero', function () {
    $medication = Mockery::mock(ClientMedication::class)->makePartial();
    $medication->min_hours_between_doses = 0;

    $result = $this->service->checkPrnInterval($medication);

    expect($result['blocked'])->toBeFalse();
});

// ─── performSafetyCheck includes dose validation ───────────────────────

test('performSafetyCheck includes dose validation warnings when dose exceeds prescribed', function () {
    $client = Mockery::mock(Client::class)->makePartial();
    $client->id = 1;

    $medication = Mockery::mock(ClientMedication::class)->makePartial();
    $medication->id = 1;
    $medication->is_prn = false;
    $medication->high_risk = false;
    $medication->controlled_drug = false;
    $medication->dose_amount = 100;
    $medication->shouldReceive('isActive')->andReturn(true);
    $medication->shouldReceive('isExpired')->andReturn(false);
    $medication->shouldReceive('isExpiringSoon')->andReturn(false);

    // Mock allergy check - no allergies
    $allergyQuery = Mockery::mock();
    $allergyQuery->shouldReceive('where')->andReturnSelf();
    $allergyQuery->shouldReceive('get')->andReturn(new \Illuminate\Support\Collection());

    // Mock duplicate check - no duplicates
    $dupQuery = Mockery::mock();
    $dupQuery->shouldReceive('where')->andReturnSelf();
    $dupQuery->shouldReceive('active')->andReturnSelf();
    $dupQuery->shouldReceive('get')->andReturn(new \Illuminate\Support\Collection());

    // Mock interaction check - no interactions
    $interactionQuery = Mockery::mock();
    $interactionQuery->shouldReceive('where')->andReturnSelf();
    $interactionQuery->shouldReceive('active')->andReturnSelf();
    $interactionQuery->shouldReceive('pluck')->andReturn(collect([]));

    // We need to use a partial mock approach with the service itself
    $service = Mockery::mock(MedicationSafetyService::class)->makePartial();
    $service->shouldReceive('checkAllergies')->andReturn(['has_match' => false, 'matches' => [], 'allergy_count' => 0]);
    $service->shouldReceive('checkDuplicates')->andReturn(['has_duplicate' => false, 'duplicates' => []]);
    $service->shouldReceive('checkInteractions')->andReturn(['has_interaction' => false, 'interactions' => []]);

    $result = $service->performSafetyCheck($client, $medication, Carbon::now(), '150mg');

    // Should have a dose_exceeds_prescribed warning
    $doseWarnings = collect($result['warnings'])->where('type', 'dose_exceeds_prescribed');
    expect($doseWarnings)->toHaveCount(1)
        ->and($doseWarnings->first()['severity'])->toBe('warning');
});

test('performSafetyCheck has no dose warning when dose is within range', function () {
    $client = Mockery::mock(Client::class)->makePartial();
    $client->id = 1;

    $medication = Mockery::mock(ClientMedication::class)->makePartial();
    $medication->id = 1;
    $medication->is_prn = false;
    $medication->high_risk = false;
    $medication->controlled_drug = false;
    $medication->dose_amount = 100;
    $medication->shouldReceive('isActive')->andReturn(true);
    $medication->shouldReceive('isExpired')->andReturn(false);
    $medication->shouldReceive('isExpiringSoon')->andReturn(false);

    $service = Mockery::mock(MedicationSafetyService::class)->makePartial();
    $service->shouldReceive('checkAllergies')->andReturn(['has_match' => false, 'matches' => [], 'allergy_count' => 0]);
    $service->shouldReceive('checkDuplicates')->andReturn(['has_duplicate' => false, 'duplicates' => []]);
    $service->shouldReceive('checkInteractions')->andReturn(['has_interaction' => false, 'interactions' => []]);

    $result = $service->performSafetyCheck($client, $medication, Carbon::now(), '100mg');

    $doseWarnings = collect($result['warnings'])->where('type', 'dose_exceeds_prescribed');
    expect($doseWarnings)->toHaveCount(0);
});
