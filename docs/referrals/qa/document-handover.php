<?php

require __DIR__.'/inspect-delivery.php';

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralConversion;
use App\Domain\Referrals\Models\ReferralEvidence;
use App\Domain\Referrals\Services\ReferralCareHandoverRevisions;
use App\Domain\Referrals\Services\ReferralConversionService;
use App\Domain\Referrals\Services\ReferralDocumentHandovers;
use App\Domain\Referrals\Services\ReferralEvidenceFiles;
use App\Domain\Referrals\Services\ReferralHandoverPreview;
use App\Http\Controllers\ClientDocumentController;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\Permission;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\Client\ActionsAggregator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

$checks = [];
$check = function ($ok, $name) use (&$checks) {
    if (! $ok) {
        throw new RuntimeException($name);
    }
    $checks[] = $name;
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
    Auth::setUser($actor);
    $referral = CareReferral::where('source', 'qa_profile_handover_layout')->sole();
    $preview = app(ReferralHandoverPreview::class)->inspect($actor, $referral);
    $source = ReferralEvidence::where('referral_id', $referral->id)->where('state', 'available')->sole();
    $source->update(['metadata' => [...$source->metadata, 'expires_at' => now()->addWeek()->toISOString()]]);
    $review = ['evidence_id' => $source->id, 'title' => 'Fictional reviewed care document', 'purpose' => 'Fictional whole-file review for staff care preparation only', 'confirmed' => true];
    $input = ['target_id' => null, 'target_fingerprint' => null, 'service_context_id' => $preview['service_contexts'][0]['id'], 'onboarding_owner_id' => $actor->id,
        'identity_review' => 'Fictional identity review for document QA', 'handover_note' => 'Fictional continuing funding assistance remains assigned',
        'evidence_ids' => [$source->id], 'duplicate_decisions' => [], 'acknowledge_pending_work' => true, 'acknowledge_care_handover' => true,
        'acknowledge_separate_start' => true, 'document_handover' => [$review]];
    $private = $source->replicate(['reference']);
    $private->forceFill(['audience' => 'funding'])->save();
    $people = Client::count();
    $convert = app(ReferralConversionService::class);
    $denied(fn () => $convert->convert($actor, $referral->id, $referral->lock_version, [...$input, 'document_handover' => [[...$review, 'evidence_id' => $private->id]]]), 422, 'Private funding evidence cannot be released through an assessment handover');
    $check(Client::count() === $people && ClientDocument::where('source_referral_evidence_id', $private->id)->doesntExist(), 'Rejected release leaves no partial person or document');
    $receipt = $convert->convert($actor, $referral->id, $referral->lock_version, $input);
    $check($convert->convert($actor, $referral->id, $referral->lock_version, $input) === $receipt, 'Exact handover retry returns the same receipt');
    $person = Client::findOrFail($receipt['person']['id']);
    $document = ClientDocument::where('client_id', $person->id)->where('source_referral_evidence_id', $source->id)->sole();
    $release = app(ReferralDocumentHandovers::class);
    $projected = collect([$document->fresh()]);
    $release->project($projected);
    $check($projected->first()->care_release === ['review_required' => false], 'Canonical lists show a current care release without exposing source provenance');
    $check($document->portal_visible === false && $person->status === 'onboarding' && $person->service_start_date === null, 'Release is staff-only and never starts services');
    $check(hash('sha256', $release->bytes($actor, $person, $document)) === $source->sha256, 'Canonical read returns the exact reviewed source bytes');
    $check(! str_contains($document->getRawOriginal('referral_release'), 'whole-file'), 'Release purpose and provenance are encrypted');
    $check(! array_key_exists('referral_release', $document->toArray()) && ! array_key_exists('source_referral_evidence_id', $document->toArray()), 'Generic model serialization hides the private source link and review');
    $conversion = ReferralConversion::where('referral_id', $referral->id)->sole();
    $check($conversion->receipt['document_mappings'][0]['document_id'] === $document->id, 'Encrypted conversion receipt records the canonical document mapping');
    $check(TimelineEvent::where('source_type', ClientDocument::class)->where('source_id', $document->id)->doesntExist(), 'Private release does not produce a generic title-bearing timeline event');
    $audit = AuditLog::where('auditable_type', ClientDocument::class)->where('auditable_id', $document->id)->pluck('meta')->toJson();
    $check(! str_contains($audit, $review['title']) && ! str_contains($audit, 'whole-file'), 'Generic document audit snapshots omit release titles and private review notes');
    $source->update(['state' => 'quarantined']);
    $projected = collect([$document->fresh()]);
    $release->project($projected);
    $check($projected->first()->care_release === ['review_required' => true], 'Canonical lists show review-needed status after source quarantine');
    $denied(fn () => $release->bytes($actor, $person, $document), 409, 'Quarantine is enforced on canonical reads after release');
    $source->update(['state' => 'available']);
    $referral->refresh()->update(['authority' => ['review' => [...$referral->authority['review'], 'version' => 2]],
        'assessment' => [...$referral->assessment, 'version' => 2, 'authority_version' => 2]]);
    $denied(fn () => $release->bytes($actor, $person, $document), 409, 'Changed authority and assessment invalidate the old release');
    $care = app(ReferralCareHandoverRevisions::class);
    $carePreview = $care->preview($actor, $referral->fresh());
    $renew = ['submission_key' => (string) Str::uuid(), 'acknowledge_care_handover' => true, 'reason' => 'Fictional current care and whole-file review',
        'assessment_version' => 2, 'authority_version' => 2, 'person_fingerprint' => $carePreview['person']['fingerprint'],
        'evidence_ids' => [$source->id], 'document_handover' => [$review]];
    $care->publish($actor, $referral->id, $referral->fresh()->lock_version, $renew);
    $document->refresh();
    $check($document->referral_release['version'] === 2 && hash('sha256', $release->bytes($actor, $person, $document)) === $source->sha256, 'Explicit care revision renews the same canonical link after fresh review');
    $check(ClientDocument::where('source_referral_evidence_id', $source->id)->count() === 1 && $conversion->fresh()->receipt['document_mappings'][0]['version'] === 1, 'Renewal avoids duplicates and preserves the original conversion mapping');
    if ($permission = Permission::where('key', 'referrals.restricted')->first()) {
        $actor->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => false]]);
    }
    $referral->update(['restricted' => true]);
    $check(! ClientDocument::query()->whereKey($document->id)->visibleReferralReleases($actor->fresh())->exists(), 'Restricted source is hidden from canonical document lists');
    $expiring = new ReflectionMethod(ActionsAggregator::class, 'expiringDocuments');
    $check($expiring->invoke(app(ActionsAggregator::class), $person, true, $actor->fresh())->isEmpty(), 'Restricted release titles do not leak into expiring-document actions');
    $denied(fn () => $release->bytes($actor->fresh(), $person, $document), 404, 'Restricted source cannot be reached by its canonical document id');
    $referral->update(['restricted' => false]);
    Auth::setUser($actor->fresh());
    $controller = app(ClientDocumentController::class);
    $request = Request::create('/operations/clients/'.$person->id.'/documents/'.$document->id, 'PUT', ['portal_visible' => true]);
    $request->setUserResolver(fn () => $actor->fresh());
    $denied(fn () => $controller->update($request, $person, $document), 422, 'Canonical editing cannot widen a staff-care release into portal sharing');
    $request = Request::create('/operations/clients/'.$person->id.'/documents/'.$document->id, 'PUT', ['category' => 'med_chart']);
    $request->setUserResolver(fn () => $actor->fresh());
    $denied(fn () => $controller->update($request, $person, $document), 422, 'A handover file cannot become a medication chart through reclassification');
    $request = Request::create('/operations/clients/'.$person->id.'/documents/'.$document->id, 'PUT', ['version' => '99']);
    $request->setUserResolver(fn () => $actor->fresh());
    $denied(fn () => $controller->update($request, $person, $document), 422, 'A canonical edit cannot replace the reviewed release version');
    $request = Request::create('/operations/clients/'.$person->id.'/documents/'.$document->id, 'DELETE');
    $request->setUserResolver(fn () => $actor->fresh());
    $controller->destroy($request, $person, $document);
    $check(! ClientDocument::whereKey($document->id)->exists() && app(ReferralEvidenceFiles::class)->bytes($source->fresh()) !== null, 'Removing the canonical link retains original referral evidence bytes');
    echo json_encode(['passed' => $checks, 'count' => count($checks)]).PHP_EOL;
} finally {
    DB::rollBack();
}
