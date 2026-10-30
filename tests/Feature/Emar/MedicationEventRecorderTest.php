<?php

use App\Models\Client;
use App\Models\MedicationEvent;
use App\Models\Site;
use App\Services\Medication\Audit\MedicationEventChain;
use App\Services\Medication\Audit\MedicationEventData;
use App\Services\Medication\Audit\MedicationEventRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

function p09Event(Site $site, ?Client $client = null): MedicationEventData
{
    return new MedicationEventData($site->id, 'dose.recorded', 'administration', 'synthetic-1', null, CarbonImmutable::parse('2026-09-28T07:00:00+13:00'), 'Dose recorded', ['amount' => 1.0], $client?->id);
}

it('chains events per Site with exact stored timestamps and append-only corrections', function () {
    $site = Site::factory()->create();
    $other = Site::factory()->create();
    $client = Client::factory()->create(['site_id' => $site->id]);
    $events = DB::transaction(fn () => app(MedicationEventRecorder::class)->appendMany([p09Event($site, $client), p09Event($other), p09Event($site, $client)]), 5);
    expect(array_column(array_map(fn ($e) => $e->toArray(), $events), 'sequence'))->toBe([1, 1, 2]);
    expect($events[2]->previous_hash)->toBe($events[0]->hash);
    foreach ($events as $event) {
        expect($event->fresh()->hasValidFingerprint())->toBeTrue();
    }
    expect(DB::transaction(fn () => app(MedicationEventChain::class)->verify($site->id))['intact'])->toBeTrue();
    $events[0]->summary = 'Overwrite';
    expect(fn () => $events[0]->save())->toThrow(LogicException::class);
    expect(fn () => $events[0]->delete())->toThrow(LogicException::class);
});

it('fails closed on forged canonical person ownership without appending', function () {
    $site = Site::factory()->create();
    $client = Client::factory()->create(['site_id' => Site::factory()->create()->id]);
    expect(fn () => DB::transaction(fn () => app(MedicationEventRecorder::class)->append(p09Event($site, $client))))->toThrow(LogicException::class);
    expect(MedicationEvent::count())->toBe(0);
});

it('rolls the domain change and chain head back when the recorder insert fails', function () {
    $site = Site::factory()->create(['name' => 'Synthetic original']);
    MedicationEvent::creating(fn () => throw new RuntimeException('Synthetic ledger failure'));
    try {
        expect(fn () => DB::transaction(function () use ($site) {
            $site->update(['name' => 'Must roll back']);
            app(MedicationEventRecorder::class)->append(p09Event($site));
        }, 5))->toThrow(RuntimeException::class, 'Synthetic ledger failure');
        expect($site->fresh()->name)->toBe('Synthetic original');
        expect(DB::table('medication_event_heads')->where('site_id', $site->id)->exists())->toBeFalse();
    } finally {
        MedicationEvent::flushEventListeners();
        MedicationEvent::clearBootedModels();
    }
});

it('detects tampering or missing entries rather than claiming backing-record integrity', function () {
    $site = Site::factory()->create();
    DB::transaction(fn () => app(MedicationEventRecorder::class)->appendMany([p09Event($site), p09Event($site)]));
    DB::table('medication_events')->where('site_id', $site->id)->where('sequence', 1)->update(['summary' => 'Tampered']);
    $result = DB::transaction(fn () => app(MedicationEventChain::class)->verify($site->id));
    expect($result['intact'])->toBeFalse()->and($result['broken_at'])->toBe(1);
});
