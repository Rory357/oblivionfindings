<?php

use App\Domain\Referrals\Models\ReferralEvent;
use App\Domain\Referrals\Models\ReferralSettingsVersion;
use App\Domain\Referrals\Services\ReferralLifecycle;
use App\Domain\Referrals\Services\ReferralPresenter;
use App\Domain\Referrals\Services\ReferralSettings;
use App\Models\Site;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../../Support/ReferralFixtures.php';

/** Publish settings where "High" is turned off and a custom "Same day" priority is on. */
function referralPrioritySettings(): void
{
    $configuration = ReferralSettings::defaults();
    $configuration['choices']['priority'] = array_map(
        fn (array $item) => $item['id'] === 'high' ? [...$item, 'enabled' => false] : $item,
        $configuration['choices']['priority'],
    );
    $configuration['choices']['priority'][] = ['id' => 'same_day', 'label' => 'Same day', 'enabled' => true];
    ReferralSettingsVersion::create(['version' => 1, 'state' => 'published', 'configuration' => $configuration, 'published_at' => now()->subDay(), 'effective_at' => now()->subDay()]);
}

it('sets only a priority turned on in the published settings and shows its configured label', function () {
    referralPrioritySettings();
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = referralTestUser($site, ['viewAny', 'transition']);
    $record = referralTestRecord($site, ['stage' => 'triage']);
    $service = app(ReferralLifecycle::class);

    expect(fn () => $service->priority($actor, $record->id, 1, ['priority' => 'high', 'reason' => 'Turned off in settings']))->toThrow(ValidationException::class)
        ->and(fn () => $service->priority($actor, $record->id, 1, ['priority' => 'invented', 'reason' => 'Not a configured priority']))->toThrow(ValidationException::class)
        ->and(fn () => $service->priority($actor, $record->id, 1, ['priority' => 'urgent', 'reason' => 'no']))->toThrow(ValidationException::class);

    $service->priority($actor, $record->id, 1, ['priority' => 'same_day', 'reason' => 'Hospital discharge planned for tomorrow']);
    $summary = app(ReferralPresenter::class)->summary($record->fresh());
    expect($summary['priority'])->toBe('same_day')
        ->and($summary['priority_label'])->toBe('Same day');

    // The detail offers only the enabled priorities, with their labels.
    $detail = app(ReferralPresenter::class)->detail($actor, $record->fresh());
    expect(array_column($detail['choices']['priority'], 'label', 'id'))->toBe(['urgent' => 'Urgent', 'standard' => 'Standard', 'same_day' => 'Same day']);

    // The same priority again is refused rather than recorded twice.
    expect(fn () => $service->priority($actor, $record->id, 2, ['priority' => 'same_day', 'reason' => 'Still the same priority']))->toThrow(HttpException::class);
});

it('records who changed the priority, from what and why, without moving any stage or contact clock', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = referralTestUser($site, ['viewAny', 'transition']);
    $record = referralTestRecord($site, [
        'stage' => 'assessment', 'owner_id' => $actor->id, 'hold_reason' => 'awaiting_evidence',
        'next_action' => 'Chase the GP letter', 'next_due_at' => now()->addDays(2)->startOfMinute(),
        'stage_changed_at' => now()->subDays(4)->startOfMinute(), 'first_contact_attempt_at' => now()->subDays(5)->startOfMinute(),
        'first_human_contact_at' => now()->subDays(5)->addHour()->startOfMinute(),
    ]);
    $before = $record->fresh();

    $this->actingAs($actor)->postJson("/operations/referrals/{$record->reference}/lifecycle/priority", [
        'version' => 1, 'priority' => 'urgent', 'reason' => 'Current housing ends on Friday',
    ])->assertOk()->assertJson(['saved' => true]);

    $after = $record->fresh();
    expect($after->priority)->toBe('urgent')
        ->and($after->lock_version)->toBe(2);
    foreach (['stage', 'owner_id', 'hold_reason', 'next_action', 'primary_work_item_id'] as $field) {
        expect($after->{$field})->toBe($before->{$field});
    }
    foreach (['stage_changed_at', 'next_due_at', 'first_contact_attempt_at', 'first_human_contact_at', 'closed_at', 'converted_at'] as $clock) {
        expect($after->{$clock}?->toISOString())->toBe($before->{$clock}?->toISOString());
    }

    $event = ReferralEvent::query()->where('referral_id', $record->id)->where('kind', 'priority.changed')->sole();
    expect($event->actor_id)->toBe($actor->id)
        ->and($event->audience)->toBe('intake')
        ->and($event->payload)->toMatchArray([
            'previous_priority' => 'standard', 'priority' => 'urgent', 'reason' => 'Current housing ends on Friday',
            'previous_priority_label' => 'Standard', 'priority_label' => 'Urgent',
        ]);
    expect(ReferralEvent::query()->where('referral_id', $record->id)->where('kind', 'like', 'stage.%')->exists())->toBeFalse();
});

it('lets anyone who edits or moves the referral set it, within scope, on a current open referral', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $other = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $reader = referralTestUser($site, ['viewAny']);
    $editor = referralTestUser($site, ['viewAny', 'edit']);
    $elsewhere = referralTestUser($other, ['viewAny', 'edit']);
    $actor = referralTestUser($site, ['viewAny', 'transition']);
    $record = referralTestRecord($site, ['stage' => 'triage']);
    $input = ['version' => 1, 'priority' => 'high', 'reason' => 'Waiting list review'];

    $this->actingAs($reader)->postJson("/operations/referrals/{$record->reference}/lifecycle/priority", $input)->assertForbidden();
    // Editing rights at another site don't reach this referral.
    $this->actingAs($elsewhere)->postJson("/operations/referrals/{$record->reference}/lifecycle/priority", $input)->assertNotFound();
    expect($record->fresh()->priority)->toBe('standard');

    // Intake staff who can edit the referral may set it, without stage-change rights.
    $this->actingAs($editor)->postJson("/operations/referrals/{$record->reference}/lifecycle/priority", $input)->assertOk();
    expect($record->fresh()->priority)->toBe('high');
    // So may coordinators who move it between stages, against the current version only.
    $input = ['version' => 2, 'priority' => 'urgent', 'reason' => 'Housing ends this week'];
    $this->actingAs($actor)->postJson("/operations/referrals/{$record->reference}/lifecycle/priority", [...$input, 'version' => 7])->assertStatus(409);
    $this->actingAs($actor)->postJson("/operations/referrals/{$record->reference}/lifecycle/priority", $input)->assertOk();
    expect($record->fresh()->priority)->toBe('urgent');

    $closed = referralTestRecord($site, ['stage' => 'closed', 'closure_reason' => 'information_only', 'closed_at' => now(), 'next_due_at' => null]);
    $this->actingAs($editor)->postJson("/operations/referrals/{$closed->reference}/lifecycle/priority", [...$input, 'version' => 1])->assertStatus(422);
    $converted = referralTestRecord($site, ['stage' => 'converted', 'converted_at' => now()]);
    $this->actingAs($actor)->postJson("/operations/referrals/{$converted->reference}/lifecycle/priority", [...$input, 'version' => 1])->assertStatus(422);
    expect($closed->fresh()->priority)->toBe('standard')
        ->and($converted->fresh()->priority)->toBe('standard')
        ->and(ReferralEvent::query()->where('kind', 'priority.changed')->orderBy('id')->pluck('actor_id')->all())->toBe([$editor->id, $actor->id]);
});

it('opens Set priority in place on the workspace', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = referralTestUser($site, ['viewAny', 'transition']);
    $record = referralTestRecord($site, ['stage' => 'triage']);

    $this->actingAs($actor)->get(route('referrals.index', ['focus' => $record->reference, 'act' => 'priority']))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('referral.reference', $record->reference)
            ->where('filters.act', 'priority')->where('referral.priority_label', 'Standard')
            ->where('referral.choices.priority.0.id', 'urgent'));
});
