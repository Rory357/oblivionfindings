<?php

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralSettingsVersion;
use App\Domain\Referrals\Services\ReferralSettings;
use App\Domain\Referrals\Services\StaffReferralIntake;
use App\Models\Site;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../../Support/ReferralFixtures.php';

function staffReferralPayload(?int $siteId): array
{
    $version = ReferralSettingsVersion::query()->create([
        'version' => 1, 'state' => 'published', 'configuration' => ReferralSettings::defaults(),
        'effective_at' => now()->subMinute(), 'published_at' => now()->subMinute(),
    ]);

    return [
        'source' => 'phone', 'site_id' => $siteId, 'successful_contact' => true,
        'notice_method' => 'pending', 'notice_reason' => 'Asked for a written notice before giving contact permission',
        'next_action' => 'Provide the notice through the agreed channel', 'next_due_at' => now()->addDay()->toISOString(),
        'form' => [
            'submission_token' => (string) Str::uuid(), 'settings_version_id' => $version->id, 'notice_version' => 'draft',
            'purpose' => 'support', 'relationship' => 'self', 'authority_status' => 'self',
            'person' => ['first_name' => 'Phone', 'last_name' => 'Enquirer'],
            'contact' => ['name' => 'Phone Enquirer', 'channel' => 'phone', 'phone' => '0210000000', 'leave_message' => false],
            'requested_service' => 'supported_living', 'funding_situation' => 'unsure',
            'contact_permission' => false, 'privacy_acknowledged' => false,
        ],
    ];
}

it('records phone intake with an owner without assuming notice delivery or contact consent', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = referralTestUser($site, ['viewAssigned', 'create', 'contact']);
    $payload = staffReferralPayload($site->id);
    $service = app(StaffReferralIntake::class);
    $record = $service->create($actor, $payload)->fresh();
    expect($record->owner_id)->toBe($actor->id)->and($record->source)->toBe('phone')
        ->and($record->first_human_contact_at)->not->toBeNull()->and($record->authority['contact_permission'])->toBeFalse()
        ->and($record->authority['notice_presented_at'])->toBeNull()->and($record->authority['staff_review'])->toBe('pending');
    expect($service->create($actor, $payload)->id)->toBe($record->id)->and(CareReferral::query()->count())->toBe(1);
    $payload['next_action'] = 'A different follow-up';
    expect(fn () => $service->create($actor, $payload))->toThrow(HttpException::class);
});

it('does not permit staff creation across sites or without a base read grant', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $other = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = referralTestUser($site, ['viewAssigned', 'create', 'contact']);
    $createOnly = referralTestUser($other, ['create', 'contact']);
    $payload = staffReferralPayload($other->id);
    foreach ([$actor, $createOnly] as $user) {
        expect(fn () => app(StaffReferralIntake::class)->create($user, $payload))->toThrow(HttpException::class);
    }
    expect(CareReferral::query()->count())->toBe(0);
});

it('permits explicitly authorized central staff without treating null site as global access', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = referralTestUser($site, ['viewAssigned', 'centralIntake', 'create', 'contact']);
    $actor->hrEmployeeProfile()->update(['primary_site_id' => null, 'secondary_site_ids' => []]);
    $payload = staffReferralPayload(null);
    $record = app(StaffReferralIntake::class)->create($actor, $payload);
    expect($record->site_id)->toBeNull()->and($record->owner_id)->toBe($actor->id);
    $payload['site_id'] = $site->id;
    $payload['form']['submission_token'] = (string) Str::uuid();
    expect(fn () => app(StaffReferralIntake::class)->create($actor, $payload))->toThrow(HttpException::class);
});
