<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Client;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationDoseSlot;
use App\Models\MedicationEvent;
use App\Models\MedicationFollowup;
use App\Models\MedicationIdempotencyResult;
use App\Models\MedicationOrderRevision;
use App\Models\User;
use App\Models\UserWitnessPin;
use App\Services\Medication\DoseTimingSettings;
use App\Services\Medication\Recording\DoseRecordingRequirements;
use Carbon\Carbon;
use Database\Seeders\FrontlineLifecycleDemoSeeder;
use Database\Seeders\MedicationReadinessAcceptanceSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SystemUsersSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-04 09:30:00', 'Pacific/Auckland')->utc());
    Storage::fake('local');
    $this->seed([RbacSeeder::class, SystemUsersSeeder::class, FrontlineLifecycleDemoSeeder::class]);
    Cache::flush();
});

afterEach(fn () => Carbon::setTestNow());

test('synthetic medication acceptance fixtures satisfy real recording and independent witness checks', function (): void {
    $clockBefore = now()->toIso8601String();
    $this->seed(MedicationReadinessAcceptanceSeeder::class);
    expect(now()->toIso8601String())->toBe($clockBefore);
    $worker = User::query()->where('email', 'sw-meds@demo.test')->sole();
    $witness = User::query()->where('email', 'sw-meds-witness@demo.test')->sole();
    $order = ClientMedication::query()->where('name', 'PW Meds Controlled PRN')->sole();
    $morning = ClientMedication::query()->where('client_id', $order->client_id)->where('name', 'PW Meds Morning Tablets')->sole();
    $morningDue = Carbon::parse('2026-10-04 '.$morning->dose_times[0], 'Pacific/Auckland')->utc();
    $morningRevision = MedicationOrderRevision::query()->where('client_medication_id', $morning->id)
        ->canonicalVersion()->where('status', 'checked')
        ->whereHas('version', fn ($versions) => $versions->where('version_number', $morning->version))->sole();
    expect($morning->created_at->lt($morningDue))->toBeTrue()
        ->and($morningRevision->checked_at->lt($morningDue))->toBeTrue()
        ->and($morningRevision->entered_by)->not->toBe($morningRevision->checked_by);
    foreach ([$morningRevision->entered_by, $morningRevision->checked_by] as $sourceActorId) {
        $sourceActor = User::query()->findOrFail($sourceActorId);
        expect($sourceActor->created_at->lte($morningRevision->checked_at))->toBeTrue()
            ->and($sourceActor->approved_at->lte($morningRevision->checked_at))->toBeTrue();
    }
    $this->actingAs($worker)->get('/meds/today')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('schedule', fn ($rows) => collect($rows)->contains(fn ($row) => (int) $row['medication_id'] === $morning->id
            && Carbon::parse($row['scheduled_for'])->equalTo($morningDue) && $row['status'] === 'due')));
    $badgeKey = HandleInertiaRequests::medsOverdueBadgeCacheKey($worker->id, '2026-10-04');
    Carbon::withTestNow($morningDue->copy()->addMinutes(app(DoseTimingSettings::class)->lateMinutes() + 1), function () use ($worker, $morning, $badgeKey): void {
        Cache::forget($badgeKey);
        $this->actingAs($worker)->get('/meds/today')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('schedule', fn ($rows) => collect($rows)->contains(fn ($row) => (int) $row['medication_id'] === $morning->id && $row['status'] === 'overdue'))
            ->where('auth.can.medications.overdueTodayCount', fn ($count) => $count >= 1));
    });
    Cache::forget($badgeKey);
    expect(now()->toIso8601String())->toBe($clockBefore);
    $requirements = app(DoseRecordingRequirements::class)->forAsNeeded($worker, $order);

    expect($requirements['block_all'])->toBeNull()
        ->and($requirements['block_given'])->toBeNull()
        ->and($requirements['competency']['state'])->toBe('current')
        ->and($requirements['second_person']['kind'])->toBe('witness')
        ->and(collect($requirements['second_person']['candidates'])->firstWhere('id', $witness->id)['can_confirm'])->toBeTrue();
    $revision = MedicationOrderRevision::query()->where('client_medication_id', $order->id)->where('status', 'checked')->latest('id')->firstOrFail();
    expect($revision->entered_by)->not->toBe($revision->checked_by)
        ->and($revision->files()->where('purpose', 'source')->exists())->toBeTrue();

    $this->actingAs($worker)->postJson('/meds/today/prn', [
        'client_medication_id' => $order->id, 'reason' => 'Severe pain', 'administered_at' => now()->toIso8601String(),
        'quantity_administered' => 1, 'cd_balance' => 11, 'witnessed_by' => $witness->id,
        'witness_credential' => MedicationReadinessAcceptanceSeeder::WITNESS_PIN,
        'client_request_uuid' => (string) Str::uuid(),
    ])->assertOk();
    expect(ClientMedicationAdministration::query()->where('client_medication_id', $order->id)->count())->toBe(1)
        ->and((float) $order->stock()->sole()->on_hand)->toBe(11.0);
    $dose = ClientMedicationAdministration::query()->where('client_medication_id', $order->id)->sole();
    $entry = ClientControlledDrugEntry::query()->where('client_medication_administration_id', $dose->id)->sole();
    expect(MedicationFollowup::query()->where('administration_id', $dose->id)->exists())->toBeTrue()
        ->and(MedicationIdempotencyResult::query()->where('scope', 'administration.record')->where('response_payload->administration_id', $dose->id)->exists())->toBeTrue();

    // Record the actual morning obligation at the restored fixture clock.
    $scheduled = $morning;
    $this->actingAs($worker)->postJson('/meds/today/record', [
        'client_medication_id' => $scheduled->id, 'status' => 'given',
        'scheduled_for' => $morningDue->toIso8601String(), 'administered_at' => now()->toIso8601String(),
        'client_request_uuid' => (string) Str::uuid(),
    ])->assertOk();
    $scheduledDose = ClientMedicationAdministration::query()->where('client_medication_id', $scheduled->id)->sole();
    $slot = MedicationDoseSlot::query()->where('outcome_administration_id', $scheduledDose->id)->sole();

    $otherClient = Client::factory()->create();
    $otherOrder = ClientMedication::query()->create(['client_id' => $otherClient->id, 'name' => 'Unrelated reset medicine', 'dosage' => '1 tablet', 'state' => 'active', 'active' => true]);
    $otherDose = ClientMedicationAdministration::query()->create([
        'client_id' => $otherClient->id, 'client_medication_id' => $otherOrder->id, 'status' => 'given',
        'administered_at' => now(), 'administered_by' => $worker->id,
    ]);
    $otherReceipt = MedicationIdempotencyResult::query()->create([
        'scope' => 'administration.record', 'request_uuid' => (string) Str::uuid(), 'expires_at' => null,
        'response_payload' => ['administration_id' => $otherDose->id, 'administration_root_id' => $otherDose->id,
            'client_id' => $otherClient->id, 'client_medication_id' => $otherOrder->id],
    ]);
    $otherDoseBefore = $otherDose->fresh()->getRawOriginal();
    $otherReceiptBefore = $otherReceipt->fresh()->getRawOriginal();
    $sourceBefore = $order->versions()->get()->map(fn ($version) => $version->getRawOriginal())->all();
    $checkedVersions = ClientMedication::query()->where('client_id', $order->client_id)->where('name', 'like', 'PW Meds %')->orderBy('id')->pluck('version', 'id')->all();
    $events = MedicationEvent::query()->where('client_id', $order->client_id)->orderBy('id')->get();
    $eventsBefore = $events->map(fn ($event) => $event->getRawOriginal())->all();
    $restricted = User::query()->where('email', 'sw-meds-no-record@demo.test')->sole();
    expect($restricted->canDo('medications.administer.record'))->toBeFalse();
    $this->actingAs($restricted)->postJson('/meds/today/prn', ['client_medication_id' => $order->id, 'reason' => 'Severe pain'])->assertForbidden();

    $this->seed([FrontlineLifecycleDemoSeeder::class, MedicationReadinessAcceptanceSeeder::class]);
    $freshRequirements = app(DoseRecordingRequirements::class)->forAsNeeded($worker->fresh(), $order->fresh());
    expect(ClientMedicationAdministration::withTrashed()->whereIn('id', [$dose->id, $scheduledDose->id])->exists())->toBeFalse()
        ->and(ClientControlledDrugEntry::query()->whereKey($entry->id)->exists())->toBeFalse()
        ->and(MedicationFollowup::query()->whereIn('administration_id', [$dose->id, $scheduledDose->id])->exists())->toBeFalse()
        ->and(MedicationIdempotencyResult::query()->where('scope', 'administration.record')->whereIn('response_payload->administration_id', [$dose->id, $scheduledDose->id])->exists())->toBeFalse()
        ->and($slot->fresh()->outcome)->toBeNull()
        ->and($slot->fresh()->outcome_administration_id)->toBeNull()
        ->and($slot->fresh()->outcome_at)->toBeNull()
        ->and((float) $order->stock()->sole()->on_hand)->toBe(12.0)
        ->and($freshRequirements['block_all'])->toBeNull()
        ->and($freshRequirements['block_given'])->toBeNull()
        ->and($order->versions()->get()->map(fn ($version) => $version->getRawOriginal())->all())->toBe($sourceBefore)
        ->and(ClientMedication::query()->where('client_id', $order->client_id)->where('name', 'like', 'PW Meds %')->orderBy('id')->pluck('version', 'id')->all())->toBe($checkedVersions)
        ->and(MedicationEvent::query()->whereIn('id', $events->modelKeys())->orderBy('id')->get()->map(fn ($event) => $event->getRawOriginal())->all())->toBe($eventsBefore)
        ->and($otherDose->fresh()->getRawOriginal())->toBe($otherDoseBefore)
        ->and($otherReceipt->fresh()->getRawOriginal())->toBe($otherReceiptBefore);
});

test('medication acceptance setup is idempotent and leaves other people and medicines alone', function (): void {
    $otherClient = Client::factory()->create();
    $otherOrder = ClientMedication::query()->create(['client_id' => $otherClient->id, 'name' => 'Unrelated fixture medicine', 'dosage' => '1 tablet', 'state' => 'active', 'active' => true]);
    $otherBefore = $otherOrder->fresh()->getRawOriginal();
    $this->seed(MedicationReadinessAcceptanceSeeder::class);
    $worker = User::query()->where('email', 'sw-meds@demo.test')->sole();
    $witness = User::query()->where('email', 'sw-meds-witness@demo.test')->sole();
    $revisions = MedicationOrderRevision::query()->count();
    $assessments = MedicationCompetencyAssessment::query()->count();
    $pinBefore = UserWitnessPin::query()->where('user_id', $witness->id)->sole()->getAttributes();
    $checkedVersions = ClientMedication::query()->where('name', 'like', 'PW Meds %')->orderBy('id')->pluck('version', 'id')->all();
    $this->seed(FrontlineLifecycleDemoSeeder::class);
    $this->seed(MedicationReadinessAcceptanceSeeder::class);

    expect(MedicationOrderRevision::query()->count())->toBe($revisions)
        ->and(MedicationCompetencyAssessment::query()->count())->toBe($assessments)
        ->and(UserWitnessPin::query()->where('user_id', $witness->id)->sole()->getAttributes())->toBe($pinBefore)
        ->and($otherOrder->fresh()->getRawOriginal())->toBe($otherBefore)
        ->and(ClientMedication::query()->where('name', 'like', 'PW Meds %')->orderBy('id')->pluck('version', 'id')->all())->toBe($checkedVersions)
        ->and($worker->canDo('medications.orders.manage'))->toBeFalse()
        ->and($witness->canDo('medications.controlled.manage'))->toBeFalse();
});

test('later synthetic fixture schedules get a new independently checked version and preserve earlier source evidence', function (): void {
    $this->seed(MedicationReadinessAcceptanceSeeder::class);
    $order = ClientMedication::query()->where('name', 'PW Meds Morning Tablets')->sole();
    $previousVersion = $order->version;
    $previousTimes = $order->dose_times;
    $source = $order->versions()->where('version_number', $previousVersion)->sole();
    $sourceBefore = $source->getRawOriginal();
    $revisionsBefore = MedicationOrderRevision::query()->count();
    $prnVersionsBefore = ClientMedication::query()->where('client_id', $order->client_id)->where('is_prn', true)->orderBy('id')->pluck('version', 'id')->all();

    Carbon::setTestNow(now()->addMinutes(20));
    $this->seed(FrontlineLifecycleDemoSeeder::class);
    // The general reset preserves the actual published prescription.
    expect($order->refresh()->version)->toBe($previousVersion)
        ->and($order->dose_times)->toBe($previousTimes);
    $this->seed(MedicationReadinessAcceptanceSeeder::class);
    $order->refresh();
    $revision = MedicationOrderRevision::query()->where('client_medication_id', $order->id)
        ->canonicalVersion()->where('status', 'checked')
        ->whereHas('version', fn ($versions) => $versions->where('version_number', $order->version))->sole();

    expect($order->version)->toBe($previousVersion + 1)
        ->and($order->dose_times)->toBe(['09:35'])
        ->and($revision->version->version_number)->toBe($order->version)
        ->and($revision->version->prescription_payload['dose_times'])->toBe($order->dose_times)
        ->and($revision->entered_by)->not->toBe($revision->checked_by)
        ->and($revision->files()->where('purpose', 'source')->exists())->toBeTrue()
        ->and($source->fresh()->getRawOriginal())->toBe($sourceBefore)
        ->and(MedicationOrderRevision::query()->count())->toBe($revisionsBefore + 3)
        ->and(ClientMedication::query()->where('client_id', $order->client_id)->where('is_prn', true)->orderBy('id')->pluck('version', 'id')->all())->toBe($prnVersionsBefore);
});
