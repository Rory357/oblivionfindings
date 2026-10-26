<?php

require __DIR__.'/inspect-delivery.php';

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralEvent;
use App\Domain\Referrals\Models\ReferralSettingsVersion;
use App\Domain\Referrals\Services\PublicReferralIntake;
use App\Domain\Referrals\Services\ReferralForms;
use App\Domain\Referrals\Services\ReferralHandoverPreview;
use App\Domain\Referrals\Services\ReferralPresenter;
use App\Domain\Referrals\Services\ReferralSettings;
use App\Models\Permission;
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
        throw new RuntimeException('Unexpected valid data: '.$name);
    } catch (ValidationException) {
        $check(true, $name);
    }
};
$denied = function ($fn, $code, $name) use ($check) {
    try {
        $fn();
        throw new RuntimeException('Unexpected acceptance: '.$name);
    } catch (HttpException $error) {
        $check($error->getStatusCode() === $code, $name);
    }
};
$actor = User::where('email', 'author@referrals-preview.test')->sole();
$company = CareReferral::where('reference', '01M56ACS2FJVSWQTTN8TW1DZ8B')->sole();
DB::beginTransaction();
try {
    $forms = app(ReferralForms::class);
    $settings = app(ReferralSettings::class);
    $configuration = $version->configuration;
    $field = ['id' => 'qa_text', 'label' => 'Fictional question', 'help' => 'QA only', 'type' => 'text', 'options' => [], 'enabled' => true, 'public' => true,
        'required' => 'intake', 'audience' => 'intake', 'destination' => 'referral_intake', 'service_ids' => ['supported_living'], 'purposes' => ['support']];
    $configuration['forms']['fields'] = [$field,
        [...$field, 'id' => 'qa_date', 'type' => 'date', 'required' => 'optional'],
        [...$field, 'id' => 'qa_number', 'type' => 'number', 'required' => 'optional'],
        [...$field, 'id' => 'qa_yes_no', 'type' => 'checkbox'],
        [...$field, 'id' => 'qa_choice', 'type' => 'choice', 'options' => ['A', 'B'], 'required' => 'optional'],
        [...$field, 'id' => 'qa_funding', 'required' => 'handover', 'audience' => 'funding', 'destination' => 'referral_funding'],
    ];
    $configuration = $settings->validate($configuration);
    $check($settings->requiresGovernance($version->configuration, $configuration), 'Adding questions requires governed publication');
    $bad = $configuration;
    $bad['forms']['fields'][0]['destination'] = 'client.status';
    $invalid(fn () => $settings->validate($bad), 'Reject arbitrary destinations');
    $bad = $configuration;
    $bad['forms']['fields'][0]['type'] = 'sql';
    $invalid(fn () => $settings->validate($bad), 'Reject executable field types');
    $bad = $configuration;
    $bad['forms']['fields'][0]['destination'] = 'referral_funding';
    $invalid(fn () => $settings->validate($bad), 'Reject mismatched sensitivity and destination');
    $bad = $configuration;
    $bad['forms']['fields'][0]['public'] = false;
    $invalid(fn () => $settings->validate($bad), 'Intake-required question must be available at intake');
    $bad = $configuration;
    array_pop($bad['forms']['fields']);
    $invalid(fn () => $forms->stableReferences($configuration, $bad), 'Published question cannot be removed');
    $bad = $configuration;
    $bad['forms']['fields'][0]['audience'] = 'funding';
    $invalid(fn () => $forms->stableReferences($configuration, $bad), 'Published sensitivity cannot be silently changed');
    $check(! array_key_exists('audience', $forms->publicFields($configuration)[0]), 'Public form projection omits internal destination and audience');
    $candidate = ReferralSettingsVersion::create(['version' => ReferralSettingsVersion::max('version') + 1, 'state' => 'published', 'configuration' => $configuration, 'published_at' => now(), 'effective_at' => now()]);
    $payload = ['submission_token' => (string) Str::uuid(), 'settings_version_id' => $candidate->id, 'notice_version' => $configuration['public']['notice_version'],
        'purpose' => 'support', 'relationship' => 'self', 'authority_status' => 'self',
        'contact' => ['name' => 'Fictional form QA', 'email' => '', 'phone' => '0200000000', 'channel' => 'phone', 'safe_time' => '', 'leave_message' => false, 'communication' => ''],
        'person' => ['first_name' => 'Fictional', 'last_name' => 'Forms', 'preferred_name' => ''], 'region' => 'Auckland', 'requested_service' => 'supported_living',
        'goals' => 'Fictional form verification only', 'funding_situation' => 'need_help', 'contact_permission' => true, 'privacy_acknowledged' => true, 'website' => '',
        'answers' => ['qa_text' => 'Fictional additional information', 'qa_date' => '2026-10-20', 'qa_number' => '0', 'qa_yes_no' => false, 'qa_choice' => 'A']];
    $invalid(fn () => $forms->intakeAnswers($configuration, [...$payload, 'answers' => []], $candidate->id), 'Required intake answers enforced on server');
    foreach (['qa_date' => '2026-02-30', 'qa_number' => '1e999', 'qa_choice' => 'C', 'qa_yes_no' => 'possibly'] as $key => $value) {
        $invalid(fn () => $forms->intakeAnswers($configuration, [...$payload, 'answers' => [...$payload['answers'], $key => $value]], $candidate->id), 'Reject invalid '.$key);
    }
    $invalid(fn () => $forms->intakeAnswers($configuration, [...$payload, 'purpose' => 'business'], $candidate->id), 'Process enquiries cannot supply hidden personal answers');
    $invalid(fn () => $forms->intakeAnswers($configuration, [...$payload, 'requested_service' => 'unsure'], $candidate->id), 'Service-conditional hidden answers rejected');
    $intake = app(PublicReferralIntake::class);
    $receipt = $intake->submit($payload);
    $record = CareReferral::where('reference', $receipt['reference'])->sole();
    $check($record->intake['form_answers']['qa_yes_no']['value'] === false && $record->intake['form_answers']['qa_number']['value'] == 0, 'Explicit no and zero survive durable intake');
    $check(! str_contains(DB::table('care_referrals')->where('id', $record->id)->value('intake'), 'Fictional additional information'), 'Answers encrypted at rest');
    $check($intake->submit($payload) === $receipt && array_keys($receipt) === ['reference', 'status'], 'Retry recovers minimal receipt without leaking answers');
    $denied(fn () => $intake->submit([...$payload, 'answers' => [...$payload['answers'], 'qa_text' => 'Changed answer']]), 409, 'Retry cannot change the answers');
    $record->update(['site_id' => $company->site_id, 'owner_id' => $actor->id]);
    $check($forms->missing($record) === 1, 'Handover requirement may remain unanswered during intake');
    $preview = app(ReferralHandoverPreview::class)->inspect($actor, $record);
    $check(collect($preview['checks'])->firstWhere('key', 'form_answers')['passed'] === false, 'Canonical handover preview blocks missing required answers');
    $save = fn ($answers) => $forms->save($actor->fresh(), $record->id, $record->fresh()->lock_version, ['answers' => $answers, 'reason' => 'Fictional QA correction']);
    $denied(fn () => $save(['qa_funding' => 'Fictional funding source answer']), 422, 'Staff update requires reviewed collection authority');
    $record->update(['authority' => ['review' => ['state' => 'current', 'scopes' => ['collect' => true], 'review_due_at' => now()->addDay()->toISOString()]]]);
    $denied(fn () => $save(['qa_text' => 'Intake change', 'qa_funding' => 'Funding change']), 422, 'Mixed-audience reason cannot leak into a less restricted event');
    $save(['qa_funding' => 'Fictional funding source answer']);
    $check($forms->missing($record->fresh()) === 0, 'Staff completion satisfies the pinned form gate');
    $record->update(['entry_kind' => 'business']);
    $save(['qa_funding' => null]);
    $check($forms->missing($record->fresh()) === 1, 'A completed process enquiry cannot bypass required support questions');
    $save(['qa_funding' => 'Fictional funding source answer']);
    $event = ReferralEvent::where('referral_id', $record->id)->where('kind', 'form.answers_updated')->firstOrFail();
    $check($event->audience === 'funding', 'Answer edit audit stays in funding audience');
    $permission = Permission::where('key', 'referrals.funding.view')->sole();
    $actor->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => false]);
    $data = app(ReferralPresenter::class)->detail($actor->fresh(), $record->fresh());
    $check(! str_contains(json_encode($data), 'Fictional funding source answer') && ! in_array('qa_funding', array_column($data['form_fields'], 'id')), 'Revoked audience cannot read question answers or history');
    $manage = Permission::where('key', 'referrals.funding.manage')->sole();
    $actor->permissionOverrides()->updateExistingPivot($manage->id, ['allowed' => false]);
    $denied(fn () => $save(['qa_funding' => 'Cannot overwrite']), 403, 'Revoked audience cannot update by direct field reference');
    $actor->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => true]);
    $actor->permissionOverrides()->updateExistingPivot($manage->id, ['allowed' => true]);
    $changed = $configuration;
    $changed['forms']['fields'][0]['label'] = 'Changed current wording';
    $changed['forms']['fields'][0]['enabled'] = false;
    $new = ReferralSettingsVersion::create(['version' => $candidate->version + 1, 'state' => 'published', 'configuration' => $changed, 'published_at' => now(), 'effective_at' => now()]);
    $check($forms->fields($actor->fresh(), $record->fresh())[0]['label'] === 'Fictional question', 'Existing referral retains the original form version');
    $record->update(['settings_version_id' => $new->id]);
    $check($forms->history($actor->fresh(), $record)[0]['definition']['label'] === 'Fictional question', 'Archive and migration retain recorded original wording');
    echo json_encode(['passed' => $checks, 'count' => count($checks)]).PHP_EOL;
} finally {
    DB::rollBack();
}
