<?php

require __DIR__.'/inspect-delivery.php';

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralEvent;
use App\Domain\Referrals\Services\ReferralPeopleContacts;
use App\Domain\Referrals\Services\ReferralPresenter;
use App\Models\NextOfKin;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

$checks = [];
$check = function ($ok, $label) use (&$checks) {
    if (! $ok) {
        throw new RuntimeException($label);
    } $checks[] = $label;
};
$denied = function ($action, $status, $label) use ($check) {
    try {
        $action();
        throw new RuntimeException('Unexpected acceptance: '.$label);
    } catch (HttpException $e) {
        $check($e->getStatusCode() === $status, $label);
    }
};
DB::beginTransaction();
try {
    $actor = User::where('email', 'reviewer@referrals-preview.test')->sole();
    $referral = CareReferral::where('source', 'qa_profile_handover_layout')->sole();
    $authority = $referral->authority;
    $authority['review']['scopes']['contact'] = true;
    $referral->update(['authority' => $authority]);
    $service = app(ReferralPeopleContacts::class);
    $input = ['submission_key' => (string) Str::uuid(), 'contact_id' => (string) Str::uuid(), 'contact_version' => 0,
        'name' => 'Fictional Support Person', 'role' => 'supporter', 'relationship' => 'Chosen support person', 'organisation' => null,
        'phone' => '021 555 0188', 'email' => null, 'channel' => 'phone', 'safe_time' => 'Fictional weekday afternoon contact', 'leave_message' => false,
        'communication' => 'Use plain language', 'state' => 'current', 'may_contact' => true, 'purpose' => 'Arrange participation in the support assessment',
        'authority_note' => null, 'review_note' => 'Fictional identity, details and permission checked with the person', 'review_due_at' => now()->addWeek()->toISOString(), 'confirmed' => true];
    $users = User::count();
    $kins = NextOfKin::count();
    $result = $service->save($actor, $referral->id, $referral->lock_version, $input);
    $check($service->save($actor, $referral->id, $referral->lock_version, $input) === $result, 'Exact retry reuses one contact receipt');
    $referral->refresh();
    $contact = $service->present($actor, $referral)[0];
    $check($contact['review_current'] && $contact['version'] === 1, 'Current reviewed contact is projected with its own version');
    $check(! str_contains($referral->getRawOriginal('intake'), $input['name']), 'Contact details are encrypted at rest');
    $event = ReferralEvent::where('referral_id', $referral->id)->where('kind', 'person_contact.reviewed')->sole();
    $check($event->audience === 'assessment' && ! str_contains($event->getRawOriginal('payload'), $input['phone']), 'History stays encrypted and within the assessment audience');
    $check(User::count() === $users && NextOfKin::count() === $kins, 'A contact role never creates an account or portal membership');
    $denied(fn () => $service->save($actor, $referral->id, $referral->lock_version, [...$input, 'name' => 'Changed replay']), 409, 'Reusing a submission key with changed details is refused');
    $next = [...$input, 'submission_key' => (string) Str::uuid(), 'contact_version' => 1];
    $denied(fn () => $service->save($actor, $referral->id, $referral->lock_version - 1, $next), 409, 'A stale referral cannot overwrite another review');
    $denied(fn () => $service->save($actor, $referral->id, $referral->lock_version, [...$next, 'review_due_at' => now()->addYear()->toISOString()]), 422, 'Contact permission cannot outlast its authority review');
    $denied(fn () => $service->save($actor, $referral->id, $referral->lock_version, [...$next, 'role' => 'representative']), 422, 'Representative roles require an explicit basis and limits');
    $denied(fn () => $service->save($actor, $referral->id, $referral->lock_version, [...$next, 'state' => 'pending']), 422, 'Pending contacts cannot carry current permissions');
    $authority['review']['version']++;
    $authority['review']['state'] = 'withdrawn';
    $authority['review']['scopes'] = ['collect' => false, 'contact' => false, 'assist' => false, 'service' => false];
    $referral->update(['authority' => $authority]);
    $check(! $service->present($actor, $referral->fresh())[0]['review_current'], 'Changed authority visibly invalidates the old contact review');
    $withdraw = [...$next, 'state' => 'withdrawn', 'may_contact' => false, 'confirmed' => false, 'review_note' => 'Fictional contact permission withdrawn by the person'];
    $denied(fn () => $service->save($actor, $referral->id, $referral->lock_version, [...$withdraw, 'phone' => '021 555 0198']), 422, 'Withdrawal cannot smuggle in new contact details without collection authority');
    $service->save($actor, $referral->id, $referral->lock_version, $withdraw);
    $check($service->present($actor, $referral->fresh())[0]['details']['state'] === 'withdrawn', 'Permission can be withdrawn after collection authority ends');
    $referral->refresh()->update(['stage' => 'closed']);
    $service->save($actor, $referral->id, $referral->lock_version, [...$withdraw, 'submission_key' => (string) Str::uuid(), 'contact_version' => 2, 'state' => 'archived']);
    $check($service->present($actor, $referral->fresh())[0]['details']['state'] === 'archived', 'A closed referral still allows contact permission to be retired');
    $permission = Permission::where('key', 'referrals.assessment.view')->sole();
    $actor->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => false]]);
    $check($service->present($actor->fresh(), $referral->fresh()) === [], 'Assessment-view denial hides contact details');
    $detail = app(ReferralPresenter::class)->detail($actor->fresh(), $referral->fresh());
    $check(! array_key_exists('people_contacts', $detail) && ! str_contains(json_encode($detail), $input['phone']), 'General referral and activity projection does not leak private contacts');
    $denied(fn () => $service->save($actor->fresh(), $referral->id, $referral->fresh()->lock_version, [...$withdraw, 'submission_key' => (string) Str::uuid(), 'contact_version' => 2]), 403, 'Write access also requires the assessment read boundary');
    echo json_encode(['passed' => $checks, 'count' => count($checks)]).PHP_EOL;
} finally {
    DB::rollBack();
}
