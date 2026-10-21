<?php

require __DIR__.'/inspect-delivery.php';

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralConversion;
use App\Domain\Referrals\Models\ReferralEvent;
use App\Domain\Referrals\Models\ReferralServiceStart;
use App\Domain\Referrals\Models\ReferralSettingsVersion;
use App\Domain\Referrals\Services\ReferralChecklists;
use App\Domain\Referrals\Services\ReferralHandoverPreview;
use App\Domain\Referrals\Services\ReferralLifecycle;
use App\Domain\Referrals\Services\ReferralPresenter;
use App\Domain\Referrals\Services\ReferralSettings;
use App\Domain\Referrals\Services\ReferralStartReadiness;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

$checks = [];
$check = function ($ok, $name) use (&$checks) {
    if (! $ok) {
        throw new RuntimeException($name);
    } $checks[] = $name;
};
$invalid = function ($fn, $name) use ($check) {
    try {
        $fn();
        throw new RuntimeException('Unexpected acceptance: '.$name);
    } catch (ValidationException) {
        $check(true, $name);
    }
};
$denied = function ($fn, $status, $name) use ($check) {
    try {
        $fn();
        throw new RuntimeException('Unexpected acceptance: '.$name);
    } catch (HttpException $exception) {
        $check($exception->getStatusCode() === $status, $name);
    }
};
$actor = User::where('email', 'author@referrals-preview.test')->sole();
$record = CareReferral::where('reference', '01M56ACS2FJVSWQTTN8TW1DZ8B')->sole();
DB::beginTransaction();
try {
    $service = app(ReferralChecklists::class);
    $settings = app(ReferralSettings::class);
    $role = Role::create(['name' => 'qa_checklist_'.Str::lower(Str::random(8)), 'label' => 'Fictional checklist reviewer', 'type' => 'custom', 'level' => 0]);
    $template = ['id' => 'qa_preparation', 'label' => 'Fictional preparation', 'enabled' => true, 'audience' => 'funding', 'role_id' => $role->id, 'service_ids' => ['supported_living'], 'gate' => 'assessment',
        'items' => [['id' => 'qa_step', 'title' => 'Discuss application next steps', 'instruction' => 'Fictional test only; does not approve funding', 'enabled' => true, 'required' => true]]];
    $configuration = [...$version->configuration, 'checklists' => [$template]];
    $settings->validate($configuration);
    $check($settings->requiresGovernance($version->configuration, $configuration), 'Checklist policy change requires governance');
    $bad = $configuration;
    $bad['checklists'][0]['gate'] = 'converted';
    $invalid(fn () => $settings->validate($bad), 'Checklist cannot define an activation or conversion bypass');
    $bad = $configuration;
    $bad['checklists'][0]['gate'] = 'none';
    $invalid(fn () => $settings->validate($bad), 'Required step must have an enforceable gate');
    $bad = $configuration;
    $bad['checklists'][0]['items'][] = $template['items'][0];
    $invalid(fn () => $settings->validate($bad), 'Duplicate step within one template rejected');
    $repeated = $configuration;
    $repeated['checklists'][] = [...$template, 'id' => 'qa_other'];
    $check(count($settings->validate($repeated)['checklists']) === 2, 'Different templates may reuse their own scoped step references');
    $invalid(fn () => $service->stableReferences($configuration, ['checklists' => []]), 'Published template cannot be deleted');
    $bad = $configuration;
    $bad['checklists'][0]['audience'] = 'intake';
    $invalid(fn () => $service->stableReferences($configuration, $bad), 'Published audience cannot be weakened');
    $candidate = ReferralSettingsVersion::create(['version' => ReferralSettingsVersion::max('version') + 1, 'state' => 'published', 'configuration' => $configuration, 'published_at' => now(), 'effective_at' => now()]);
    $check($service->missing($record, 'handover') === 0, 'Existing case retains the previous checklist policy');
    $record->update(['settings_version_id' => $candidate->id, 'stage' => 'triage', 'authority' => []]);
    $check($service->missing($record, 'triage') === 0 && $service->missing($record, 'assessment') === 1 && $service->missing($record, 'handover') === 1, 'Later gate includes earlier required preparation without blocking initial triage');
    $preview = app(ReferralHandoverPreview::class)->inspect($actor, $record);
    $check(! collect($preview['checks'])->firstWhere('key', 'checklists')['passed'], 'Direct handover preview cannot skip preparation');
    $started = CareReferral::where('reference', '01M55ZQRKFN2MMX8HVQ1DXY9V2')->sole();
    $started->update(['settings_version_id' => $candidate->id]);
    $conversion = ReferralConversion::where('referral_id', $started->id)->sole();
    $start = ReferralServiceStart::where('referral_id', $started->id)->sole();
    $person = Client::findOrFail($conversion->client_id);
    $readiness = app(ReferralStartReadiness::class)->inspect($started, $conversion, $person, $start->handover['selection']);
    $check(! $readiness['ready'] && in_array('Complete the required referral preparation checklists before approving the start.', $readiness['issues'], true), 'Service-start readiness independently rechecks required preparation');
    $check($person->fresh()->status === 'active' && $start->fresh()->status === $start->status && $start->fresh()->commenced_at !== null, 'Readiness review does not silently stop active support');
    $move = fn () => app(ReferralLifecycle::class)->move($actor->fresh(), $record->id, $record->fresh()->lock_version, ['stage' => 'assessment', 'reason' => 'Fictional stage transition', 'next_action' => 'Review fictional progress', 'next_due_at' => now()->addDay()->toISOString()]);
    $denied($move, 422, 'Stage command enforces checklist gate');
    $input = ['template_id' => 'qa_preparation', 'item_id' => 'qa_step', 'completed' => true, 'note' => 'Fictional preparation review only'];
    $save = fn ($input) => $service->record($actor->fresh(), $record->id, $record->fresh()->lock_version, $input);
    $denied(fn () => $save($input), 403, 'Configured responsible role required for direct completion');
    $actor->roles()->attach($role->id);
    $denied(fn () => $save($input), 422, 'Collection authority required before casework notes');
    $record->update(['authority' => ['review' => ['state' => 'current', 'scopes' => ['collect' => true], 'review_due_at' => now()->addDay()->toISOString()]]]);
    $save($input);
    $check($service->missing($record->fresh(), 'handover') === 0, 'Recorded exact checklist preparation satisfies the gate');
    $event = ReferralEvent::where('referral_id', $record->id)->where('kind', 'checklist.review_recorded')->sole();
    $check($event->audience === 'funding' && $event->payload['decision']['recorded_by'] === $actor->id, 'Named completion proof remains in its audience');
    $check(! str_contains(DB::table('care_referrals')->where('id', $record->id)->value('intake'), $input['note']), 'Checklist notes encrypted at rest');
    $denied(fn () => $save($input), 409, 'Already completed step cannot be duplicated');
    $move();
    $check($record->fresh()->stage === 'assessment', 'Complete preparation allows the working-stage command');
    $save([...$input, 'completed' => false, 'note' => 'Fictional reopening for review']);
    $check($service->missing($record->fresh(), 'handover') === 1, 'Reopening restores the later gate without deleting history');
    $save($input);
    $changed = $configuration;
    $changed['checklists'][0]['items'][0]['instruction'] = 'Changed fictional instruction requires a fresh review';
    $revision = ReferralSettingsVersion::create(['version' => $candidate->version + 1, 'state' => 'published', 'configuration' => $changed, 'published_at' => now(), 'effective_at' => now()]);
    $record->update(['settings_version_id' => $revision->id]);
    $check($service->missing($record->fresh(), 'handover') === 1 && $service->present($actor->fresh(), $record->fresh())[0]['items'][0]['decision']['completed'], 'Changed instructions invalidate completion but retain prior review');
    $permission = Permission::where('key', 'referrals.funding.view')->sole();
    $actor->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => false]);
    $data = app(ReferralPresenter::class)->detail($actor->fresh(), $record->fresh());
    $check($data['checklists'] === [] && ! str_contains(json_encode($data), 'Fictional preparation review only'), 'Revoked audience cannot see checklist or completion history');
    $denied(fn () => $save($input), 403, 'Revoked audience cannot directly complete a known item');
    $actor->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => true]);
    $record->update(['stage' => 'closed']);
    $denied(fn () => $save($input), 422, 'Closed referral cannot record checklist changes');
    echo json_encode(['passed' => $checks, 'count' => count($checks)]).PHP_EOL;
} finally {
    DB::rollBack();
}
