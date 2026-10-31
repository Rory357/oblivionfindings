<?php

require __DIR__.'/inspect-delivery.php';

use App\Domain\Privacy\Retention\RetentionContractException;
use App\Domain\Privacy\Retention\RetentionExecutionService;
use App\Http\Controllers\DataRetentionPolicyController;
use App\Models\AnonymizationLog;
use App\Models\Client;
use App\Models\DataRetentionPolicy;
use App\Models\LegalHold;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$checks = [];
$check = function ($ok, $label) use (&$checks) {
    if (! $ok) {
        throw new RuntimeException($label);
    } $checks[] = $label;
};
DB::beginTransaction();
try {
    $author = User::where('email', 'author@referrals-preview.test')->sole();
    $reviewer = User::where('email', 'reviewer@referrals-preview.test')->sole();
    $permission = Permission::where('key', 'privacy.manageRetention')->sole();
    $reviewer->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
    $reviewProjection = function ($policy) use ($reviewer) {
        $request = Request::create('/privacy/retention/review');
        $request->headers->set('X-Inertia', 'true');
        $request->setUserResolver(fn () => $reviewer->fresh());
        $response = app(DataRetentionPolicyController::class)->review($request, app(RetentionExecutionService::class))->toResponse($request);

        return collect($response->getData(true)['props']['policies'])->firstWhere('id', $policy->id);
    };
    $person = Client::create(['first_name' => 'Fictional', 'last_name' => 'ContactRetentionRollbackQA', 'status' => 'inactive',
        'phone' => '021 555 0199', 'email' => 'fictional-retention@example.test', 'contact_preferences' => ['phone' => '021 555 0199', 'email' => 'fictional-retention@example.test', 'safe_time' => 'Fictional confidential instructions']]);
    $person->forceFill(['created_at' => now()->subYears(2), 'contact_preferences' => ['phone' => '021 555 0199', 'email' => 'fictional-retention@example.test', 'safe_time' => 'Fictional confidential instructions']])->save();
    $policy = DataRetentionPolicy::create(['model_type' => Client::class, 'policy_name' => 'Fictional rollback-only contact retention',
        'hard_delete_after_years' => 1, 'retention_conditions' => ['status' => 'inactive', 'site_id' => null],
        'applies_to_soft_deleted' => true, 'active_case_exemption' => false, 'legal_hold_exemption' => true, 'active' => true, 'execution_state' => 'draft']);
    // Narrow the synthetic policy to a newly created isolated Site, never existing preview people.
    $site = Site::factory()->create(['name' => 'Fictional retention rollback site']);
    $person->update(['site_id' => $site->id]);
    $policy->update(['retention_conditions' => ['site_id' => $site->id]]);
    $service = app(RetentionExecutionService::class);
    $hold = LegalHold::create(['hold_reference' => 'QA-CONTACT-'.Str::uuid(), 'hold_type' => 'audit', 'reason' => 'Fictional rollback-only hold',
        'holdable_type' => Client::class, 'holdable_id' => $person->id, 'status' => 'active', 'imposed_at' => now(), 'imposed_by_user_id' => $reviewer->id]);
    $snapshot = $service->preview($policy, $author);
    $check($snapshot['eligible_count'] === 0 && $snapshot['exempt_count'] === 1, 'A legal hold protects the saved contact preferences');
    $check(in_array('contact_preferences', $snapshot['anonymization_fields'], true), 'Preview explicitly lists the saved contact details and instructions');
    $hold->update(['status' => 'released', 'released_at' => now(), 'released_by_user_id' => $reviewer->id, 'release_reason' => 'Fictional QA completes its hold check']);
    $service->preview($policy->fresh(), $author);
    $service->approve($policy->fresh(), $reviewer);
    $legacy = ['owner_key' => Client::class, 'retention_period_years' => null, 'archive_after_years' => null, 'hard_delete_after_years' => 1,
        'retention_conditions' => ['site_id' => $site->id], 'applies_to_soft_deleted' => true, 'legal_hold_exemption' => true, 'active_case_exemption' => false, 'active' => true];
    ksort($legacy);
    $oldFingerprint = hash('sha256', json_encode($legacy, JSON_THROW_ON_ERROR));
    $policy->refresh()->update(['preview_fingerprint' => $oldFingerprint, 'approved_fingerprint' => $oldFingerprint]);
    $projection = $reviewProjection($policy);
    $check(! $projection['preview_current'] && ! $projection['approval_current'], 'Review page marks historical previews and approvals as stale');
    try {
        $service->execute($policy->fresh(), 'manual', $reviewer);
        throw new RuntimeException('Legacy approval executed');
    } catch (RetentionContractException $e) {
        $check($e->reasonCode === 'approval_required', 'A legacy approval cannot silently authorize newly added fields');
    }
    $check($person->fresh()->contact_preferences['phone'] === '021 555 0199', 'Blocked stale approval preserves all contact information');
    $fresh = $service->preview($policy->fresh(), $author);
    $check($fresh['eligible_count'] === 1, 'Fresh preview is limited to the sole fictional eligible person');
    $service->approve($policy->fresh(), $reviewer);
    $projection = $reviewProjection($policy);
    $check($projection['preview_current'] && $projection['approval_current'], 'Review page recognizes the freshly reviewed and independently approved contract');
    $outcome = $service->execute($policy->fresh(), 'manual', $reviewer);
    $check($outcome['status'] === 'completed' && $outcome['result']['anonymized'] === 1, 'Independent fresh approval can proceed after the blocked attempt on the same day');
    $person->refresh();
    $check($person->phone === null && $person->email === null && $person->contact_preferences === null && $person->getRawOriginal('contact_preferences') === null, 'Both ordinary contact values and encrypted preference copies are cleared together');
    $check(in_array('contact_preferences', AnonymizationLog::where('model_type', Client::class)->where('model_id', $person->id)->sole()->fields_anonymized, true), 'The removal is included in the governed anonymization record');
    $check($service->execute($policy->fresh(), 'manual', $reviewer)['status'] === 'already_completed', 'An exact approved retry does not repeat anonymization');
    echo json_encode(['passed' => $checks, 'count' => count($checks)]).PHP_EOL;
} finally {
    DB::rollBack();
}
