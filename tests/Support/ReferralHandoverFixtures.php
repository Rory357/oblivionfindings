<?php

use App\Domain\Referrals\Models\ReferralProposal;
use App\Domain\Referrals\Models\ReferralWorkItem;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;

require_once __DIR__.'/ReferralFixtures.php';

function handoverCanonicalPermissions(User $user, array $keys): void
{
    foreach ($keys as $key) {
        $user->permissionOverrides()->attach(Permission::query()->firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'clients', 'module' => 'Operations']), ['allowed' => true]);
    }
}

function handoverPreviewFixture(): array
{
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $actor = referralTestUser($site, ['viewAny', 'convert', 'assessment.view', 'funding.view', 'duplicates.manage']);
    handoverCanonicalPermissions($actor, ['clients.create', 'clients.viewAny', 'clients.update', 'onboarding.view']);
    $referral = referralTestRecord($site, ['owner_id' => $actor->id, 'team' => 'Supported living team', 'requested_service' => 'supported_living',
        'identity' => ['first_name' => 'Casey', 'last_name' => 'Example', 'date_of_birth' => null],
        'intake' => ['relationship' => 'family', 'submitted_contact' => ['name' => 'Parent Example', 'email' => 'family@example.test', 'phone' => '021 111 2222', 'leave_message' => false]],
        'authority' => ['review' => ['version' => 1, 'state' => 'current', 'review_due_at' => now()->addMonth()->toISOString(), 'scopes' => ['collect' => true, 'service' => true]]],
        'assessment' => ['version' => 1, 'authority_version' => 1, 'review_due_at' => now()->addMonth()->toISOString(), 'identity_status' => 'verified', 'intention' => 'proceed', 'suitability' => 'suitable', 'goals' => 'Support daily living choice', 'participation' => 'Use plain language and allow time to decide', 'safety_needs' => 'Review the agreed mobility support with the person']]);
    $work = ReferralWorkItem::create(['referral_id' => $referral->id, 'owner_id' => $actor->id, 'kind' => 'follow_up', 'audience' => 'intake',
        'status' => 'open', 'due_at' => now()->addDay(), 'details' => ['title' => 'Arrange funding assistance']]);
    $referral->update(['primary_work_item_id' => $work->id]);
    ReferralProposal::create(['referral_id' => $referral->id, 'version' => 1, 'authored_by' => $actor->id, 'authored_at' => now(),
        'proposal' => ['funding_position' => 'Funding application is pending; navigator follows up tomorrow', 'starts_on' => today()->toDateString(), 'ends_on' => today()->addMonth()->toDateString(),
            'lines' => [['key' => 'support', 'description' => 'Daily living support', 'quantity' => null, 'unit' => 'hours', 'period' => 'week', 'estimate' => ['known' => false]]], 'allocations' => []]]);

    return [$site, $actor, $referral];
}
