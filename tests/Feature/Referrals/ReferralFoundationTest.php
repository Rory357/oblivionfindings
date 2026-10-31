<?php

use App\Domain\Referrals\Models\ReferralEvent;
use App\Domain\Referrals\Models\ReferralFundingCase;
use App\Domain\Referrals\Services\ReferralAccess;
use App\Domain\Referrals\Services\ReferralCommand;
use App\Models\Permission;
use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../../Support/ReferralFixtures.php';

it('encrypts personal intake and excludes sensitive fields from raw serialization', function () {
    $referral = referralTestRecord(null);
    $stored = DB::table('care_referrals')->find($referral->id);

    expect($stored->identity)->not->toContain('Private identity')
        ->and($stored->assessment)->not->toContain('Restricted assessment')
        ->and($referral->identity['first_name'])->toBe('Private identity')
        ->and($referral->assessment['notes'])->toBe('Restricted assessment')
        ->and($referral->toArray())->not->toHaveKeys(['identity', 'intake', 'authority', 'assessment', 'next_action'])
        ->and($referral->reference)->toHaveLength(26);
});

it('requires base read, site access and privacy access independently', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $other = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $own = referralTestRecord($site);
    referralTestRecord($other);
    referralTestRecord(null);
    referralTestRecord($site, ['restricted' => true]);
    $access = app(ReferralAccess::class);
    $reader = referralTestUser($site, ['viewAny']);

    expect($access->query($reader)->pluck('id')->all())->toBe([$own->id]);
    $bypassOnly = referralTestUser($site, ['viewAllSites', 'centralIntake', 'restricted']);
    expect($access->query($bypassOnly)->count())->toBe(0);
    $full = referralTestUser($site, ['viewAny', 'viewAllSites', 'centralIntake', 'restricted']);
    expect($access->query($full)->count())->toBe(4);
});

it('keeps assignment within sites and requires explicit central intake access', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $other = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $reader = referralTestUser($site, ['viewAssigned', 'centralIntake']);
    $own = referralTestRecord($site, ['owner_id' => $reader->id]);
    $central = referralTestRecord(null, ['owner_id' => $reader->id]);
    referralTestRecord($other, ['owner_id' => $reader->id]);
    referralTestRecord(null);
    referralTestRecord($site);
    $funding = referralTestRecord($site);
    ReferralFundingCase::query()->create([
        'referral_id' => $funding->id, 'navigator_id' => $reader->id,
        'settings_version_id' => $funding->settings_version_id, 'pathway' => 'acc', 'details' => [],
    ]);

    expect(app(ReferralAccess::class)->query($reader)->orderBy('id')->pluck('id')->all())
        ->toBe([$own->id, $central->id, $funding->id]);
});

it('hides inaccessible direct objects before checking an action', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $reader = referralTestUser($site, ['viewAny']);
    $hidden = referralTestRecord(null);
    $own = referralTestRecord($site);
    $access = app(ReferralAccess::class);

    try {
        $access->authorize($reader, $hidden, 'edit');
        $this->fail('An inaccessible referral was authorized.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(404);
    }
    try {
        $access->authorize($reader, $own, 'edit');
        $this->fail('A reader was authorized to edit.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(403);
    }
});

it('requires sensitive read permission before allowing sensitive actions', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $reader = referralTestUser($site, ['viewAny', 'funding.manage', 'assessment.manage', 'settings.publish', 'reports.export']);
    $access = app(ReferralAccess::class);
    foreach (['funding.manage', 'assessment.manage', 'settings.publish', 'reports.export', 'unknown'] as $action) {
        expect($access->can($reader, $action))->toBeFalse();
    }
});

it('does not assign departed or unapproved staff even with all-sites access', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $staff = referralTestUser($site, ['viewAny', 'viewAllSites', 'centralIntake']);
    $access = app(ReferralAccess::class);
    expect($access->eligibleForSite($staff, $site->id))->toBeTrue();
    $staff->hrEmployeeProfile()->update(['end_date' => today()->subDay()]);
    expect($access->eligibleForSite($staff, $site->id))->toBeFalse()
        ->and($access->eligibleForSite($staff, null))->toBeFalse();
    referralTestRecord($site);
    referralTestRecord(null);
    expect($access->query($staff)->count())->toBe(0);
    $staff->hrEmployeeProfile()->update(['end_date' => null]);
    $staff->update(['approved_at' => null]);
    expect($access->eligibleForSite($staff, $site->id))->toBeFalse();
});

it('preserves event history instead of allowing revisions to overwrite it', function () {
    $referral = referralTestRecord(null);
    $event = ReferralEvent::query()->create([
        'referral_id' => $referral->id, 'kind' => 'contact.recorded', 'audience' => 'intake',
        'payload' => ['outcome' => 'No answer'], 'occurred_at' => now(),
    ]);
    expect(fn () => $event->update(['payload' => ['outcome' => 'Successful contact']]))->toThrow(LogicException::class)
        ->and(fn () => $event->delete())->toThrow(LogicException::class)
        ->and($event->fresh()->payload['outcome'])->toBe('No answer');
});

it('commits edits and audit together and rejects an outdated record version', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = referralTestUser($site, ['viewAny', 'edit']);
    $referral = referralTestRecord($site);
    $command = app(ReferralCommand::class);
    $command->run($actor, $referral->id, 1, 'edit', function ($record, $user) use ($command): void {
        $record->identity = ['first_name' => 'Corrected name'];
        $command->record($record, $user, 'identity.corrected', 'intake', ['reason' => 'Person requested correction']);
    });
    expect($referral->fresh()->lock_version)->toBe(2)
        ->and($referral->fresh()->identity['first_name'])->toBe('Corrected name');
    expect(fn () => $command->run($actor, $referral->id, 1, 'edit', fn () => null))->toThrow(HttpException::class);
    expect(ReferralEvent::query()->where('referral_id', $referral->id)->count())->toBe(1);
});

it('rechecks revoked permissions despite an already loaded user and rolls back failed edits', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = referralTestUser($site, ['viewAny', 'edit']);
    $referral = referralTestRecord($site);
    $command = app(ReferralCommand::class);
    expect(fn () => $command->run($actor, $referral->id, 1, 'edit', function ($record, $user) use ($command): void {
        $command->record($record, $user, 'identity.corrected', 'intake', []);
        $record->update(['identity' => ['first_name' => 'Should not persist']]);
        throw new LogicException('Simulated failure');
    }))->toThrow(LogicException::class);
    expect($referral->fresh()->identity['first_name'])->toBe('Private identity')
        ->and(ReferralEvent::query()->where('referral_id', $referral->id)->count())->toBe(0);
    $actor->load(['roles.permissions', 'permissionOverrides']);
    $actor->permissionOverrides()->detach(Permission::query()->where('key', 'referrals.edit')->value('id'));
    expect(fn () => $command->run($actor, $referral->id, 1, 'edit', fn () => null))->toThrow(HttpException::class)
        ->and($referral->fresh()->lock_version)->toBe(1);
});
