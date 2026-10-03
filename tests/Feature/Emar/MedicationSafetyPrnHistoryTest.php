<?php

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\User;
use App\Services\MedicationSafetyService;
use Carbon\Carbon;
use Carbon\CarbonInterface;

// The PRN service reads canonical, effective clinical evidence. Keep these
// original limit/interval cases in the database harness rather than mocking
// the former accessor and relation that the service no longer uses.
beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05T00:00:00Z'));
    $this->service = new MedicationSafetyService;
    $client = Client::factory()->create();
    $this->prnGiver = User::factory()->create();
    $this->prnMedication = ClientMedication::withoutEvents(fn () => ClientMedication::query()->create([
        'client_id' => $client->id, 'name' => 'Synthetic PRN history medicine', 'dosage' => '1 tablet',
        'frequency' => 'As needed', 'is_prn' => true, 'active' => true, 'state' => 'active',
    ]));
});

afterEach(fn () => Carbon::setTestNow());

function seedSafetyPrnDose(ClientMedication $medication, User $giver, CarbonInterface $at): void
{
    ClientMedicationAdministration::withoutEvents(fn () => ClientMedicationAdministration::query()->create([
        'client_id' => $medication->client_id, 'client_medication_id' => $medication->id,
        'administered_by' => $giver->id, 'administered_at' => $at, 'status' => 'given', 'dose_given' => '1 tablet',
    ]));
}

// ─── checkPrnLimits ────────────────────────────────────────────────────

test('checkPrnLimits blocks when daily limit reached', function () {
    $medication = $this->prnMedication;
    $medication->is_prn = true;
    $medication->max_per_day = '4';

    foreach (range(1, 4) as $hours) {
        seedSafetyPrnDose($medication, $this->prnGiver, now()->subHours($hours));
    }

    $result = $this->service->checkPrnLimits($medication);

    expect($result['blocked'])->toBeTrue()
        ->and($result['details']['count_24h'])->toBe(4)
        ->and($result['details']['max_per_day'])->toBe(4)
        ->and($result['details']['remaining'])->toBe(0);
});

test('checkPrnLimits does not block when under limit', function () {
    $medication = $this->prnMedication;
    $medication->is_prn = true;
    $medication->max_per_day = '4';

    foreach (range(1, 2) as $hours) {
        seedSafetyPrnDose($medication, $this->prnGiver, now()->subHours($hours));
    }

    $result = $this->service->checkPrnLimits($medication);

    expect($result['blocked'])->toBeFalse()
        ->and($result['details']['remaining'])->toBe(2);
});

test('checkPrnLimits shows near limit warning at 75% usage', function () {
    $medication = $this->prnMedication;
    $medication->is_prn = true;
    $medication->max_per_day = '4';

    foreach (range(1, 3) as $hours) {
        seedSafetyPrnDose($medication, $this->prnGiver, now()->subHours($hours));
    }

    $result = $this->service->checkPrnLimits($medication);

    expect($result['blocked'])->toBeFalse()
        ->and($result['near_limit'])->toBeTrue()
        ->and($result['details']['remaining'])->toBe(1);
});

test('checkPrnLimits returns safe when not a PRN medication', function () {
    $medication = $this->prnMedication;
    $medication->is_prn = false;
    $medication->max_per_day = null;

    $result = $this->service->checkPrnLimits($medication);

    expect($result['blocked'])->toBeFalse()
        ->and($result['near_limit'])->toBeFalse();
});

// ─── checkPrnInterval ──────────────────────────────────────────────────

test('checkPrnInterval blocks when minimum hours not elapsed', function () {
    $lastAdminTime = Carbon::now()->subMinutes(30); // 30 minutes ago

    $medication = $this->prnMedication;
    $medication->min_hours_between_doses = 4;
    seedSafetyPrnDose($medication, $this->prnGiver, $lastAdminTime);

    $result = $this->service->checkPrnInterval($medication);

    expect($result['blocked'])->toBeTrue()
        ->and($result['details']['min_hours_between_doses'])->toBe(4.0)
        ->and($result['details']['hours_remaining'])->toBeGreaterThan(0);
});

test('checkPrnInterval does not block when minimum hours have elapsed', function () {
    $lastAdminTime = Carbon::now()->subHours(5); // 5 hours ago

    $medication = $this->prnMedication;
    $medication->min_hours_between_doses = 4;
    seedSafetyPrnDose($medication, $this->prnGiver, $lastAdminTime);

    $result = $this->service->checkPrnInterval($medication);

    expect($result['blocked'])->toBeFalse();
});

test('checkPrnInterval does not block when no previous administrations', function () {
    $medication = $this->prnMedication;
    $medication->min_hours_between_doses = 4;

    $result = $this->service->checkPrnInterval($medication);

    expect($result['blocked'])->toBeFalse();
});

test('checkPrnInterval returns unblocked when min_hours is zero', function () {
    $medication = $this->prnMedication;
    $medication->min_hours_between_doses = 0;

    $result = $this->service->checkPrnInterval($medication);

    expect($result['blocked'])->toBeFalse();
});

