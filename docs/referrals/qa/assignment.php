<?php

require __DIR__.'/inspect-delivery.php';

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralEvent;
use App\Domain\Referrals\Models\ReferralFundingCase;
use App\Domain\Referrals\Models\ReferralWorkItem;
use App\Domain\Referrals\Services\ReferralAccess;
use App\Domain\Referrals\Services\ReferralCasework;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

$checks = [];
$check = function ($ok, $name) use (&$checks) {
    if (! $ok) {
        throw new RuntimeException($name);
    } $checks[] = $name;
};
$denied = function ($fn, $status, $name) use ($check) {
    try {
        $fn();
        throw new RuntimeException('Unexpected acceptance: '.$name);
    } catch (HttpException $e) {
        $check($e->getStatusCode() === $status, $name);
    }
};
$author = User::where('email', 'author@referrals-preview.test')->sole();
$reviewer = User::where('email', 'reviewer@referrals-preview.test')->sole();
$grant = fn ($user, $key, $allowed) => $user->permissionOverrides()->updateExistingPivot(Permission::where('key', 'referrals.'.$key)->sole()->id, ['allowed' => $allowed]);
DB::beginTransaction();
try {
    $service = app(ReferralCasework::class);
    $record = CareReferral::where('reference', '01M56FK502AJ1TSMS9Y7C0M859')->sole();
    $record->update(['owner_id' => $reviewer->id]);
    $grant($reviewer, 'viewAny', false);
    $grant($reviewer, 'viewAssigned', true);
    $make = fn ($audience, $owner, $status = 'open') => ReferralWorkItem::create(['referral_id' => $record->id, 'owner_id' => $owner->id,
        'kind' => 'follow_up', 'audience' => $audience, 'status' => $status, 'details' => ['title' => 'Fictional '.$audience.' preparation', 'description' => 'Preserve this fictional description'],
        'due_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(), 'lock_version' => 1]);
    $primary = $make('intake', $reviewer);
    $funding = $make('funding', $reviewer);
    $other = $make('intake', $author);
    $completed = $make('intake', $reviewer, 'completed');
    $record->update(['primary_work_item_id' => $primary->id]);
    $original = $record->fresh()->toArray();
    $input = ['site_id' => $record->site_id, 'owner_id' => $author->id, 'team' => 'Fictional reviewed transfer', 'reason' => 'Fictional accountable handover'];
    $assign = fn ($data) => $service->assign($author->fresh(), $record->id, $record->fresh()->lock_version, $data);
    $denied(fn () => $assign($input), 422, 'Owner change cannot strand an assigned-only outgoing task owner');
    $check((int) $record->fresh()->owner_id === $reviewer->id && $primary->fresh()->lock_version === 1, 'Blocked assignment rolls back parent and follow-up changes');
    $grant($author, 'tasks.manage', false);
    $denied(fn () => $assign([...$input, 'transfer_open_work' => true]), 403, 'Transferring open work requires task management');
    $grant($author, 'tasks.manage', true);
    $grant($author, 'funding.manage', false);
    $denied(fn () => $assign([...$input, 'transfer_open_work' => true]), 403, 'Transfer cannot silently move work outside actor audience authority');
    $check((int) $primary->fresh()->owner_id === $reviewer->id && $primary->fresh()->lock_version === 1, 'A later restricted item failure rolls back earlier item transfer');
    $grant($author, 'funding.manage', true);
    $denied(fn () => $service->assign($author->fresh(), $record->id, 9999, [...$input, 'transfer_open_work' => true]), 409, 'Stale assignment version is rejected');
    $assign([...$input, 'transfer_open_work' => true]);
    $check((int) $record->fresh()->owner_id === $author->id && (int) $primary->fresh()->owner_id === $author->id && (int) $funding->fresh()->owner_id === $author->id, 'Explicit transfer changes referral and outgoing open-work owners together');
    $check($primary->fresh()->due_at->equalTo($primary->due_at) && $primary->fresh()->ends_at->equalTo($primary->ends_at) && $primary->fresh()->details === $primary->details, 'Transfer preserves due dates, duration and source notes');
    $check($record->fresh()->primary_work_item_id === $primary->id && $record->fresh()->next_action === $record->next_action, 'Primary follow-up and next-action content remain linked');
    $check($other->fresh()->lock_version === 1 && $completed->fresh()->lock_version === 1 && (int) $completed->fresh()->owner_id === $reviewer->id, 'Other owners and completed history are retained');
    $check(! app(ReferralAccess::class)->query($reviewer->fresh())->whereKey($record->id)->exists(), 'Outgoing assigned-only owner loses record access without an extra permission grant');
    $check(ReferralEvent::where('referral_id', $record->id)->where('kind', 'work.reassigned')->where('audience', 'funding')->count() === 1, 'Funding work transfer is recorded only in the funding audience');
    $grant($reviewer, 'funding.manage', false);
    $denied(fn () => $assign([...$input, 'owner_id' => $reviewer->id, 'transfer_open_work' => true]), 422, 'Recipient must be able to manage every transferred information audience');
    $check((int) $record->fresh()->owner_id === $author->id && $funding->fresh()->lock_version === 2, 'Recipient denial preserves the existing assignment and task versions');
    $grant($reviewer, 'funding.manage', true);
    $case = ReferralFundingCase::create(['referral_id' => $record->id, 'settings_version_id' => $record->settings_version_id, 'pathway' => 'dss_nasc', 'navigator_id' => $reviewer->id, 'status' => 'exploring', 'details' => []]);
    $grant($reviewer, 'funding.manage', false);
    $denied(fn () => $assign([...$input, 'site_id' => null]), 422, 'Site change cannot retain a navigator without current assistance access');
    $check($record->fresh()->site_id === $record->site_id, 'Navigator rejection rolls back the destination scope');
    $grant($reviewer, 'funding.manage', true);
    $assign([...$input, 'site_id' => null]);
    $check($record->fresh()->site_id === null && (int) $case->fresh()->navigator_id === $reviewer->id, 'Eligible navigator and other task owners are retained across a reviewed site change');
    $linked = CareReferral::where('reference', '01M55ZQRKFN2MMX8HVQ1DXY9V2')->sole();
    $denied(fn () => $service->assign($author->fresh(), $linked->id, $linked->lock_version, [...$input, 'site_id' => null]), 422, 'Linked-person site cannot be moved through referral assignment alone');
    echo json_encode(['passed' => $checks, 'count' => count($checks)]).PHP_EOL;
} finally {
    DB::rollBack();
}
