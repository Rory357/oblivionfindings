<?php

use App\Domain\Referrals\Models\ReferralEvent;
use App\Domain\Referrals\Models\ReferralSettingsVersion;
use App\Domain\Referrals\Services\ReferralSettings;
use App\Models\Permission;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../../Support/ReferralSettingsFixtures.php';

it('keeps unpublished settings out of intake and records independent publication', function () {
    $service = app(ReferralSettings::class);
    $author = referralSettingsActor(['settings.manage']);
    $publisher = referralSettingsActor(['settings.publish']);
    $draft = $service->draft($author, ReferralSettings::defaults(), null, 'Initial configuration');
    expect($service->effective())->toBeNull();
    $published = $service->publish($publisher, $draft->id, now()->toDateTimeString());
    expect($service->effective()->id)->toBe($draft->id)
        ->and($published->published_by)->toBe($publisher->id)
        ->and(ReferralEvent::query()->where('kind', 'settings.published')->count())->toBe(1)
        ->and(fn () => $published->update(['configuration' => []]))->toThrow(LogicException::class);
});

it('requires a separate publisher even when the author holds both permissions', function () {
    $service = app(ReferralSettings::class);
    $actor = referralSettingsActor(['settings.manage', 'settings.publish']);
    $draft = $service->draft($actor, ReferralSettings::defaults(), null, 'Initial configuration');
    expect(fn () => $service->publish($actor, $draft->id, now()->toDateTimeString()))->toThrow(HttpException::class)
        ->and($draft->fresh()->state)->toBe('draft');
});

it('does not activate a scheduled version early or change a previously published snapshot', function () {
    $service = app(ReferralSettings::class);
    $author = referralSettingsActor(['settings.manage']);
    $publisher = referralSettingsActor(['settings.publish']);
    $first = $service->draft($author, ReferralSettings::defaults(), null, 'Initial configuration');
    $service->publish($publisher, $first->id, now()->toDateTimeString());
    $updated = ReferralSettings::defaults();
    $updated['public']['provider_name'] = 'Updated provider name';
    $second = $service->draft($author, $updated, $first->id, 'Update provider label');
    $service->publish($publisher, $second->id, now()->addDay()->toDateTimeString());
    expect($service->effective()->id)->toBe($first->id)
        ->and($first->fresh()->configuration['public']['provider_name'])->toBe('Oblivion Care');
    $this->travel(2)->days();
    expect($service->effective()->id)->toBe($second->id);
    $this->travelBack();
});

it('rejects competing stale drafts and rechecks revoked publishing permission', function () {
    $service = app(ReferralSettings::class);
    $author = referralSettingsActor(['settings.manage']);
    $publisher = referralSettingsActor(['settings.publish']);
    $first = $service->draft($author, ReferralSettings::defaults(), null, 'First candidate');
    $stale = $service->draft($author, ReferralSettings::defaults(), null, 'Second candidate');
    $service->publish($publisher, $first->id, now()->toDateTimeString());
    expect(fn () => $service->publish($publisher, $stale->id, now()->toDateTimeString()))->toThrow(HttpException::class);
    $new = $service->draft($author, ReferralSettings::defaults(), $first->id, 'New candidate');
    $publisher->load(['roles.permissions', 'permissionOverrides']);
    $publisher->permissionOverrides()->detach(Permission::query()->where('key', 'referrals.settings.publish')->value('id'));
    expect(fn () => $service->publish($publisher, $new->id, now()->toDateTimeString()))->toThrow(HttpException::class)
        ->and($new->fresh()->state)->toBe('draft');
});

it('requires governance for relaxed follow-up targets', function () {
    $service = app(ReferralSettings::class);
    $author = referralSettingsActor(['settings.manage']);
    $publisher = referralSettingsActor(['settings.publish']);
    $governor = referralSettingsActor(['settings.publish', 'settings.governance']);
    $config = ReferralSettings::defaults();
    $config['intake']['first_contact_business_days'] = 7;
    $draft = $service->draft($author, $config, null, 'Review target change');
    expect(fn () => $service->publish($publisher, $draft->id, now()->toDateTimeString()))->toThrow(HttpException::class);
    $service->publish($governor, $draft->id, now()->toDateTimeString());
    expect($draft->fresh()->state)->toBe('published');
});

it('rejects unknown settings and attempts to remove protected lifecycle stages', function () {
    $service = app(ReferralSettings::class);
    $config = ReferralSettings::defaults();
    $config['skip_start_gates'] = true;
    expect(fn () => $service->validate($config))->toThrow(ValidationException::class);
    unset($config['skip_start_gates']);
    $config['stages'][6]['id'] = 'active';
    expect(fn () => $service->validate($config))->toThrow(ValidationException::class);
    $config = ReferralSettings::defaults();
    $config['services'] = 'Malformed input';
    expect(fn () => $service->validate($config))->toThrow(ValidationException::class);
});

it('requires public contact and notice before enabling intake', function () {
    $config = ReferralSettings::defaults();
    $config['public']['enabled'] = true;
    expect(fn () => app(ReferralSettings::class)->validate($config))->toThrow(ValidationException::class);
});

it('never allows personal income assistance to authorize provider services', function () {
    $config = ReferralSettings::defaults();
    $config['pathways'][9]['service_ids'] = ['supported_living'];
    expect(fn () => app(ReferralSettings::class)->validate($config))->toThrow(HttpException::class)
        ->and(ReferralSettingsVersion::query()->count())->toBe(0);
});
