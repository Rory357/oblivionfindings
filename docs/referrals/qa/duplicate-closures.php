<?php

require __DIR__.'/inspect-delivery.php';

use App\Domain\Referrals\Http\Controllers\ReferralReportController;
use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralEvent;
use App\Domain\Referrals\Models\ReferralFundingCase;
use App\Domain\Referrals\Models\ReferralWorkItem;
use App\Domain\Referrals\Services\ReferralDuplicateClosure;
use App\Domain\Referrals\Services\ReferralLifecycle;
use App\Domain\Referrals\Services\ReferralPresenter;
use App\Domain\Referrals\Services\ReferralReports;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

$checks = [];
$check = function ($ok, $label) use (&$checks) {
    if (! $ok) {
        throw new RuntimeException($label);
    }$checks[] = $label;
};
$denied = function ($fn, $status, $label) use ($check) {
    try {
        $fn();
        throw new RuntimeException('Unexpected acceptance: '.$label);
    } catch (HttpException $e) {
        $check($e->getStatusCode() === $status, $label);
    } catch (ValidationException $e) {
        $check($status === 422, $label);
    }
};
DB::beginTransaction();
try {
    $actor = User::where('email', 'author@referrals-preview.test')->sole();
    $grant = fn ($key, $allowed) => $actor->permissionOverrides()->updateExistingPivot(Permission::where('key', 'referrals.'.$key)->sole()->id, ['allowed' => $allowed]);
    $grant('restricted', false);
    $grant('duplicates.manage', true);
    $template = CareReferral::where('reference', '01M56FK502AJ1TSMS9Y7C0M859')->sole();
    $make = function ($name) use ($template, $actor) {
        $r = $template->replicate(['reference']);
        $r->forceFill(['source' => 'qa_duplicate_closure', 'stage' => 'assessment', 'owner_id' => $actor->id, 'identity' => ['first_name' => $name], 'primary_work_item_id' => null, 'next_action' => 'Fictional intake review', 'next_due_at' => now()->addDay(), 'hold_reason' => null]);
        $r->save();

        return $r;
    };
    $source = $make('Fictional duplicate source');
    $target = $make('Private original marker');
    $lifecycle = app(ReferralLifecycle::class);
    $duplicates = app(ReferralDuplicateClosure::class);
    $data = ['closure_reason' => 'duplicate', 'reason' => 'Reviewed fictional repeated enquiry', 'person_informed' => false, 'communication_note' => 'Fictional check; no external communication', 'cancel_open_work' => false, 'duplicate_reference' => $target->reference, 'duplicate_version' => $target->lock_version, 'duplicate_basis' => 'Private basis marker: synthetic person and same dated enquiry reviewed.', 'same_enquiry_confirmed' => true];
    $close = fn ($input) => $lifecycle->close($actor->fresh(), $source->id, $source->fresh()->lock_version, $input);
    $check($duplicates->preview($actor->fresh(), $source, $target->reference)['reference'] === $target->reference, 'Authorized original preview returns a versioned target');
    $denied(fn () => $close([...$data, 'same_enquiry_confirmed' => false]), 422, 'Same-enquiry human confirmation is required');
    $denied(fn () => $close([...$data, 'duplicate_reference' => $source->reference]), 422, 'Referral cannot duplicate itself');
    $grant('duplicates.manage', false);
    $denied(fn () => $close($data), 403, 'Duplicate closure requires independent duplicate-management permission');
    $grant('duplicates.manage', true);
    $target->update(['restricted' => true]);
    $denied(fn () => $duplicates->preview($actor->fresh(), $source, $target->reference), 404, 'Restricted original is unavailable without revealing its details');
    $denied(fn () => $close($data), 404, 'Final duplicate save independently enforces original visibility');
    $target->update(['restricted' => false, 'owner_id' => null]);
    $denied(fn () => $close($data), 422, 'Original needs an accountable owner');
    $target->update(['owner_id' => $actor->id, 'stage' => 'closed']);
    $denied(fn () => $close($data), 422, 'Closed original cannot create a dead-end duplicate chain');
    $target->update(['stage' => 'assessment', 'lock_version' => $target->lock_version + 1]);
    $denied(fn () => $close($data), 409, 'Changed original version invalidates the prior review');
    $data['duplicate_version'] = $target->lock_version;
    $funding = ReferralFundingCase::create(['referral_id' => $source->id, 'settings_version_id' => $source->settings_version_id, 'navigator_id' => $actor->id, 'pathway' => 'dss_nasc', 'status' => 'exploring', 'details' => []]);
    $denied(fn () => $close($data), 422, 'Duplicate classification does not bypass open funding resolution');
    $check(! isset($source->fresh()->intake['duplicate_closures']), 'Failed closure rolls back protected duplicate history');
    $funding->update(['status' => 'withdrawn']);
    $work = ReferralWorkItem::create(['referral_id' => $source->id, 'owner_id' => $actor->id, 'kind' => 'follow_up', 'audience' => 'intake', 'status' => 'open', 'details' => ['title' => 'Fictional follow-up to review'], 'due_at' => now()->addDay()]);
    $denied(fn () => $close($data), 422, 'Open follow-ups require explicit reviewed cancellation');
    $originalState = $target->fresh()->getAttributes();
    $close([...$data, 'cancel_open_work' => true]);
    $check($source->fresh()->closure_reason === 'duplicate' && $work->fresh()->status === 'cancelled', 'Confirmed duplicate closes source and explicitly reviewed work');
    $check($target->fresh()->getAttributes() === $originalState, 'Original record and its version remain unchanged');
    $projection = $duplicates->present($actor->fresh(), $source->fresh());
    $check($projection[0]['accessible'] && $projection[0]['target']['reference'] === $target->reference, 'Authorized history links the original');
    $audit = ReferralEvent::where('referral_id', $source->id)->where('kind', 'referral.closed')->sole()->payload;
    $check(! str_contains(json_encode($audit), $target->reference) && ! str_contains(json_encode($audit), 'Private basis marker') && $audit['duplicate_reviewed'] === true, 'General activity contains no original identifier or protected review basis');
    $target->update(['restricted' => true]);
    $projection = $duplicates->present($actor->fresh(), $source->fresh());
    $check(! $projection[0]['accessible'] && ! isset($projection[0]['target']) && ! isset($projection[0]['basis']), 'Revoked target visibility removes identifiers and basis from history');
    $target->update(['restricted' => false]);
    $grant('duplicates.manage', false);
    $check(! $duplicates->present($actor->fresh(), $source->fresh())[0]['accessible'], 'Revoked duplicate permission also redacts protected history');
    $grant('duplicates.manage', true);
    $reports = app(ReferralReports::class);
    $filters = $reports->filters(['source' => 'qa_duplicate_closure']);
    $cutoff = CarbonImmutable::now('UTC');
    $r = $reports->build($actor->fresh(), $filters, $cutoff);
    $check(array_column($r['groups']['classification'], 'count', 'key')['duplicate'] === 1 && $reports->query($actor->fresh(), [...$filters, 'classification' => 'duplicate'], $cutoff)->count() === 1, 'Duplicate classification and drill-down reconcile');
    $request = Request::create('/operations/referrals/reports/export', 'POST', [...$filters, 'classification' => 'duplicate', 'format' => 'records']);
    $request->setUserResolver(fn () => $actor->fresh());
    $csv = app(ReferralReportController::class)->export($request, app(ReferralPresenter::class))->getContent();
    $check(str_contains($csv, $source->reference) && ! str_contains($csv, $target->reference) && ! str_contains($csv, 'Private basis marker'), 'Duplicate record export contains only selected records and no protected original details');
    $meta = AuditLog::where('action', 'referrals.report_export_prepared')->latest('id')->firstOrFail()->meta;
    $check($meta['classification'] === 'duplicate', 'Export audit retains the exact classification');
    $request->merge(['format' => 'summary']);
    $summary = app(ReferralReportController::class)->export($request, app(ReferralPresenter::class))->getContent();
    $check(str_contains($summary, 'Confirmed duplicate closures') && ! str_contains($summary, $source->reference) && ! str_contains($summary, 'Private basis marker'), 'Summary export includes classification labels without referral identities or review narratives');
    $lifecycle->reopen($actor->fresh(), $source->id, $source->fresh()->lock_version, ['reason' => 'Fictional renewed support enquiry', 'next_action' => 'Review reopened source', 'next_due_at' => now()->addDay()->toISOString()]);
    $check($duplicates->present($actor->fresh(), $source->fresh())[0]['reopened_at'] !== null, 'Reopening preserves protected duplicate history and marks its end');
    $check($reports->query($actor->fresh(), [...$filters, 'classification' => 'duplicate'], $cutoff)->count() === 0 && $reports->query($actor->fresh(), [...$filters, 'classification' => 'other'], $cutoff)->count() === 2, 'Reopened work returns to the other-enquiries cohort');
    $test = [...$data, 'closure_reason' => 'test'];
    $denied(fn () => $close($test), 422, 'Test classification requires explicit no-genuine-request confirmation');
    $close([...$test, 'test_entry_confirmed' => true]);
    $check($reports->query($actor->fresh(), [...$filters, 'classification' => 'test'], $cutoff)->count() === 1, 'Confirmed test closure is separately reportable');
    $lifecycle->close($actor->fresh(), $target->id, $target->fresh()->lock_version, ['closure_reason' => 'spam', 'reason' => 'Fictional spam classification', 'person_informed' => false, 'communication_note' => 'Fictional spam; no contact appropriate', 'cancel_open_work' => false]);
    $r = $reports->build($actor->fresh(), $filters, $cutoff);
    $check(array_column($r['groups']['classification'], 'count', 'key') === ['other' => 0, 'duplicate' => 0, 'test' => 1, 'spam' => 1], 'All closure classifications remain visible by default with reconciled counts');
    echo json_encode(['passed' => $checks, 'count' => count($checks)]).PHP_EOL;
} finally {
    DB::rollBack();
}
