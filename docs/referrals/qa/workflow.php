<?php

require __DIR__.'/inspect-delivery.php';

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralEvent;
use App\Domain\Referrals\Models\ReferralEvidence;
use App\Domain\Referrals\Models\ReferralSettingsVersion;
use App\Domain\Referrals\Services\ReferralLifecycle;
use App\Domain\Referrals\Services\ReferralSettings;
use App\Domain\Referrals\Services\ReferralWorkflow;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

$actor = User::where('email', 'author@referrals-preview.test')->sole();
$record = CareReferral::where('reference', '01M56ACS2FJVSWQTTN8TW1DZ8B')->sole();
if (($record->identity['first_name'] ?? '') !== 'Fictional') {
    throw new RuntimeException('Fictional case required.');
}
$checks = [];
$check = function ($ok, $name) use (&$checks) {
    if (! $ok) {
        throw new RuntimeException($name);
    } $checks[] = $name;
};
$denied = function ($fn, $code, $name) use ($check) {
    try {
        $fn();
        throw new RuntimeException('Unexpected acceptance: '.$name);
    } catch (HttpException $error) {
        $check($error->getStatusCode() === $code, $name);
    }
};
$invalid = function ($fn, $name) use ($check) {
    try {
        $fn();
        throw new RuntimeException('Unexpected valid workflow');
    } catch (ValidationException) {
        $check(true, $name);
    }
};
DB::beginTransaction();
try {
    $workflow = app(ReferralWorkflow::class);
    $settings = app(ReferralSettings::class);
    $configuration = $version->configuration;
    unset($configuration['workflow']);
    $check(! isset($settings->validate($configuration)['workflow']), 'Legacy publications retain compatible default transitions');
    $configuration['workflow'] = ['transitions' => [['from' => 'new', 'to' => 'triage', 'role_id' => null, 'evidence' => []]]];
    $invalid(fn () => $settings->validate($configuration), 'Reject unreachable assessment and funding stages');
    $role = Role::create(['name' => 'qa_workflow_'.Str::lower(Str::random(8)), 'label' => 'Fictional workflow reviewer', 'type' => 'custom', 'level' => 0]);
    $configuration['workflow']['transitions'] = [
        ['from' => 'new', 'to' => 'triage', 'role_id' => null, 'evidence' => []],
        ['from' => 'triage', 'to' => 'assessment', 'role_id' => $role->id, 'evidence' => ['funding:agreement']],
        ['from' => 'assessment', 'to' => 'funding_assistance', 'role_id' => null, 'evidence' => []],
        ['from' => 'funding_assistance', 'to' => 'assessment', 'role_id' => null, 'evidence' => []],
    ];
    $check($settings->requiresGovernance($version->configuration, $configuration), 'Workflow change requires governance');
    $repeated = $configuration;
    $repeated['workflow']['transitions'][2]['evidence'] = ['funding:agreement'];
    $check(count($settings->validate($repeated)['workflow']['transitions']) === 4, 'Different transitions may require the same evidence');
    $repeated['workflow']['transitions'][2]['evidence'][] = 'funding:agreement';
    $invalid(fn () => $settings->validate($repeated), 'Repeated evidence within one transition is rejected');
    $bad = $configuration;
    $bad['workflow']['transitions'][0]['evidence'] = ['assessment:identity'];
    $invalid(fn () => $settings->validate($bad), 'Initial triage cannot force personal evidence collection');
    $bad = $configuration;
    $bad['workflow']['transitions'][] = $bad['workflow']['transitions'][0];
    $invalid(fn () => $settings->validate($bad), 'Duplicate transition rejected');
    $bad = $configuration;
    $bad['workflow']['transitions'][1]['to'] = 'converted';
    $invalid(fn () => $settings->validate($bad), 'Configuration cannot introduce a conversion bypass');
    foreach ($configuration['stages'] as &$stage) {
        if ($stage['id'] === 'assessment') {
            $stage['label'] = 'QA assessment';
        }
    }
    unset($stage);
    $candidate = ReferralSettingsVersion::create(['version' => ReferralSettingsVersion::max('version') + 1, 'state' => 'published', 'configuration' => $settings->validate($configuration), 'effective_at' => now(), 'published_at' => now()]);
    $record->update(['stage' => 'triage']);
    $check(count($workflow->targets($actor, $record)) === 2, 'Existing case keeps its pinned workflow despite new publication');
    $record->update(['settings_version_id' => $candidate->id]);
    $check($workflow->targets($actor, $record) === [], 'Role-specific targets are hidden without that role');
    $input = ['stage' => 'assessment', 'reason' => 'Fictional workflow test only', 'next_action' => 'Review fictional case', 'next_due_at' => now()->addDay()->toISOString()];
    $move = fn ($data) => app(ReferralLifecycle::class)->move($actor->fresh(), $record->id, $record->fresh()->lock_version, $data);
    $denied(fn () => $move($input), 403, 'Direct request cannot bypass responsible role');
    $actor->roles()->attach($role->id);
    $check($workflow->targets($actor->fresh(), $record)[0]['label'] === 'QA assessment', 'Allowed target uses the pinned configured label');
    $check($workflow->targets($actor->fresh(), $record)[0]['required_evidence'] === ['funding:agreement'], 'Stage wizard receives the visible policy checklist before save');
    $denied(fn () => $move([...$input, 'stage' => 'funding_assistance']), 422, 'Direct request cannot skip a disallowed stage');
    $document = ReferralEvidence::where('referral_id', $record->id)->where('audience', 'funding')->sole();
    // This fixture's file is generic; classify it only inside the rolled-back scenario.
    $document->update(['metadata' => [...$document->metadata, 'category' => 'agreement']]);
    $document->update(['state' => 'quarantined']);
    $denied(fn () => $move($input), 422, 'Quarantined evidence does not satisfy workflow');
    $document->update(['state' => 'available']);
    $metadata = $document->metadata;
    $document->update(['metadata' => [...$metadata, 'expires_at' => now()->subDay()->toISOString()]]);
    $denied(fn () => $move($input), 422, 'Expired evidence does not satisfy workflow');
    $document->update(['metadata' => $metadata]);
    $permission = Permission::where('key', 'referrals.funding.view')->sole();
    $actor->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => false]);
    $target = $workflow->targets($actor->fresh(), $record)[0];
    $check($target['required_evidence'] === [] && $target['restricted_requirements'], 'Restricted evidence categories are withheld from the stage wizard');
    $denied(fn () => $move($input), 403, 'Evidence audience access is rechecked at transition');
    $actor->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => true]);
    $move($input);
    $check($record->fresh()->stage === 'assessment', 'Role and current evidence allow the configured transition');
    $proof = ReferralEvent::where('referral_id', $record->id)->where('kind', 'workflow.evidence_checked')->sole();
    $check($proof->audience === 'funding' && $proof->payload['documents'][0]['evidence_id'] === $document->id, 'Evidence proof remains in its permitted audience');
    echo json_encode(['passed' => $checks, 'count' => count($checks)]).PHP_EOL;
} finally {
    DB::rollBack();
}
