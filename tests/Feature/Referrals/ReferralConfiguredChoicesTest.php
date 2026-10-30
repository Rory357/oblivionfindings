<?php

use App\Domain\Referrals\Models\ReferralSettingsVersion;
use App\Domain\Referrals\Services\ReferralLifecycle;
use App\Domain\Referrals\Services\ReferralPresenter;
use App\Domain\Referrals\Services\ReferralSettings;
use App\Models\Site;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../../Support/ReferralFixtures.php';

it('accepts only the hold reasons turned on in published settings and shows their configured labels', function () {
    $configuration = ReferralSettings::defaults();
    $configuration['choices']['hold'] = array_map(
        fn (array $item) => $item['id'] === 'awaiting_funding' ? [...$item, 'enabled' => false] : $item,
        $configuration['choices']['hold'],
    );
    $configuration['choices']['hold'][] = ['id' => 'awaiting_housing', 'label' => 'Waiting for a housing offer', 'enabled' => true];
    ReferralSettingsVersion::create(['version' => 1, 'state' => 'published', 'configuration' => $configuration, 'published_at' => now()->subDay(), 'effective_at' => now()->subDay()]);

    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = referralTestUser($site, ['viewAny', 'transition']);
    $record = referralTestRecord($site, ['owner_id' => $actor->id, 'stage' => 'assessment']);
    $step = ['reason' => 'Reviewed with the person and intake team', 'next_action' => 'Check in about the next step', 'next_due_at' => now()->addDays(3)->toISOString()];
    $service = app(ReferralLifecycle::class);

    // A reason turned off in Settings cannot be chosen for a new waiting period.
    expect(fn () => $service->hold($actor, $record->id, 1, [...$step, 'hold_reason' => 'awaiting_funding']))->toThrow(ValidationException::class);

    // A reason added in Settings can be, and is shown with its configured label.
    $service->hold($actor, $record->id, 1, [...$step, 'hold_reason' => 'awaiting_housing']);
    expect($record->fresh()->hold_reason)->toBe('awaiting_housing')
        ->and(app(ReferralPresenter::class)->summary($record->fresh())['hold_reason_label'])->toBe('Waiting for a housing offer');

    // An older record keeps a readable label for a reason that has since been turned off.
    $older = referralTestRecord($site, ['owner_id' => $actor->id, 'stage' => 'assessment', 'hold_reason' => 'awaiting_funding']);
    expect(app(ReferralPresenter::class)->summary($older)['hold_reason_label'])->toBe('Waiting for a funding decision');
});

it('keeps the built-in reasons when no settings have been published', function () {
    expect(ReferralSettings::enabledChoiceKeys(null, 'hold'))->toBe(ReferralLifecycle::HOLD_REASONS)
        ->and(ReferralSettings::enabledChoiceKeys(null, 'close'))->toBe(ReferralLifecycle::CLOSE_REASONS);
});
