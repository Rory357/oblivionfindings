<?php

use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Services\ShiftHandoverService;
use Carbon\Carbon;
use Database\Seeders\FrontlineLifecycleDemoSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SystemUsersSeeder;
use Illuminate\Support\Facades\DB;

afterEach(function (): void {
    Carbon::setTestNow();
});

test('frontline lifecycle seeder binds submitted handovers to one canonical incoming shift idempotently', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-28 10:00:00', config('app.worker_timezone', 'Pacific/Auckland')));

    $this->seed(RbacSeeder::class);
    $this->seed(SystemUsersSeeder::class);
    $this->seed(FrontlineLifecycleDemoSeeder::class);

    $fixtureNotes = [
        'PW:active-clean:sw2@demo.test',
        'PW:active-clean:sw6@demo.test',
        'PW:active-incident-blocker:sw5@demo.test',
    ];

    $loadFixtureHandovers = fn () => ShiftHandover::query()
        ->with(['outgoingShift', 'incomingShift'])
        ->whereHas('outgoingShift', fn ($shift) => $shift->whereIn('notes', $fixtureNotes))
        ->orderBy('id')
        ->get();

    $handovers = $loadFixtureHandovers();

    expect($handovers)->toHaveCount(3);

    foreach ($handovers as $handover) {
        $outgoingShift = $handover->outgoingShift;
        $incomingShift = $handover->incomingShift;
        $handoffBoundary = $outgoingShift->actual_ends_at ?? $outgoingShift->ends_at;

        expect($handover->status)->toBe('submitted')
            ->and($handover->incoming_shift_id)->not->toBeNull()
            ->and($incomingShift)->not->toBeNull()
            ->and((int) $handover->incoming_staff_id)->toBe((int) $incomingShift->user_id)
            ->and((int) $incomingShift->client_id)->toBe((int) $outgoingShift->client_id)
            ->and((int) $incomingShift->site_id)->toBe((int) $outgoingShift->site_id)
            ->and((int) $incomingShift->service_context_id)->toBe((int) $outgoingShift->service_context_id)
            ->and($incomingShift->status)->toBe('scheduled')
            ->and($incomingShift->starts_at->greaterThanOrEqualTo($handoffBoundary))->toBeTrue()
            ->and($incomingShift->starts_at->lessThanOrEqualTo($handoffBoundary->copy()->addHours(12)))->toBeTrue();
    }

    $firstSeed = $handovers
        ->mapWithKeys(fn (ShiftHandover $handover) => [
            $handover->outgoing_shift_id => [
                'id' => $handover->id,
                'incoming_shift_id' => $handover->incoming_shift_id,
                'incoming_staff_id' => $handover->incoming_staff_id,
                'version' => $handover->version,
                'submitted_at' => $handover->submitted_at?->toIso8601String(),
            ],
        ])
        ->all();

    $this->seed(FrontlineLifecycleDemoSeeder::class);

    $secondSeed = $loadFixtureHandovers()
        ->mapWithKeys(fn (ShiftHandover $handover) => [
            $handover->outgoing_shift_id => [
                'id' => $handover->id,
                'incoming_shift_id' => $handover->incoming_shift_id,
                'incoming_staff_id' => $handover->incoming_staff_id,
                'version' => $handover->version,
                'submitted_at' => $handover->submitted_at?->toIso8601String(),
            ],
        ])
        ->all();

    expect($secondSeed)->toBe($firstSeed);
});

test('frontline lifecycle seeder upgrades only legacy Playwright incoming handover identity', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-28 10:00:00', config('app.worker_timezone', 'Pacific/Auckland')));

    $this->seed(RbacSeeder::class);
    $this->seed(SystemUsersSeeder::class);
    $this->seed(FrontlineLifecycleDemoSeeder::class);

    $fixtureNotes = [
        'PW:active-clean:sw2@demo.test',
        'PW:active-clean:sw6@demo.test',
        'PW:active-incident-blocker:sw5@demo.test',
    ];
    $handovers = ShiftHandover::query()
        ->whereHas('outgoingShift', fn ($shift) => $shift->whereIn('notes', $fixtureNotes))
        ->orderBy('id')
        ->get();
    $incomingShift = Shift::query()
        ->where('notes', 'PW:incoming-handover:sw8@demo.test')
        ->sole();

    expect($handovers)->toHaveCount(3);

    $acknowledged = $handovers->first();
    DB::table('shift_handovers')->where('id', $acknowledged->id)->update([
        'status' => ShiftHandoverService::STATUS_ACKNOWLEDGED,
        'handover_notes' => 'Reviewer-authored deterministic fixture note.',
        'version' => 17,
        'acknowledged_at' => Carbon::now()->subMinute(),
        'acknowledged_by' => $incomingShift->user_id,
    ]);
    DB::table('shift_handovers')->whereIn('id', $handovers->pluck('id'))->update([
        'incoming_shift_id' => null,
        'incoming_staff_id' => null,
    ]);

    $unchangedBefore = DB::table('shift_handovers')
        ->whereIn('id', $handovers->pluck('id'))
        ->orderBy('id')
        ->get()
        ->mapWithKeys(fn (object $handover): array => [
            (int) $handover->id => collect((array) $handover)
                ->except(['incoming_shift_id', 'incoming_staff_id'])
                ->all(),
        ])
        ->all();

    $this->seed(FrontlineLifecycleDemoSeeder::class);

    $repaired = ShiftHandover::query()
        ->whereIn('id', $handovers->pluck('id'))
        ->orderBy('id')
        ->get();
    foreach ($repaired as $handover) {
        expect((int) $handover->incoming_shift_id)->toBe($incomingShift->id)
            ->and((int) $handover->incoming_staff_id)->toBe((int) $incomingShift->user_id);
    }

    $unchangedAfter = DB::table('shift_handovers')
        ->whereIn('id', $handovers->pluck('id'))
        ->orderBy('id')
        ->get()
        ->mapWithKeys(fn (object $handover): array => [
            (int) $handover->id => collect((array) $handover)
                ->except(['incoming_shift_id', 'incoming_staff_id'])
                ->all(),
        ])
        ->all();

    expect($unchangedAfter)->toBe($unchangedBefore)
        ->and($repaired->first()->status)->toBe(ShiftHandoverService::STATUS_ACKNOWLEDGED)
        ->and($repaired->first()->version)->toBe(17)
        ->and($repaired->first()->handover_notes)->toBe('Reviewer-authored deterministic fixture note.');
});
