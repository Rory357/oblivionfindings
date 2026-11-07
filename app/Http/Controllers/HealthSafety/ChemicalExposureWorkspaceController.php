<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Controller;
use App\Services\HealthSafety\ChemicalExposureDeliveryWorkspaceService;
use App\Services\HealthSafety\ChemicalExposureDownloadService;
use App\Services\HealthSafety\ChemicalExposureEvidenceService;
use App\Services\HealthSafety\ChemicalExposureLedger;
use App\Services\HealthSafety\ChemicalExposureSourceService;
use App\Services\HealthSafety\ChemicalExposureWorkspaceService;
use App\Support\HealthSafety\ChemicalExposureReturn;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Ordinary private source transport; canonical services own fresh source and operation authority. */
final class ChemicalExposureWorkspaceController extends Controller
{
    public function __construct(private readonly ChemicalExposureWorkspaceService $workspace,
        private readonly ChemicalExposureSourceService $source, private readonly ChemicalExposureEvidenceService $evidence,
        private readonly ChemicalExposureDownloadService $downloads,
        private readonly ChemicalExposureDeliveryWorkspaceService $deliveryWorkspace,
        private readonly \App\Services\HealthSafety\ChemicalExposureAssociationRecoveryService $recovery) {}

    public function index(Request $r)
    {
        $this->queryKeys($r, [...ChemicalExposureReturn::FILTERS]);
        return $this->present($r, 'health-safety/chemical-exposure-index', [...$this->workspace->index($r->user(), $r->query()),
            'chemical_exposure_confirmation' => null]);
    }
    public function show(Request $r, string|int $exposure)
    {
        $exposure = $this->id($exposure);
        $this->queryKeys($r, [...ChemicalExposureWorkspaceService::PAGES, 'section', 'action', 'followup', 'evidence', 'return_to', 'receipt_key']);
        $this->explicitKeyIsJsonOnly($r);
        foreach (['followup', 'evidence'] as $field) {
            if (array_key_exists($field, $r->query())) { $this->selection($r->input($field)); }
        }
        $followup = array_key_exists('followup', $r->query()) ? (int) $r->input('followup') : null;
        try {
            $detail = $this->workspace->detail($r->user(), $exposure,
                $r->only(ChemicalExposureWorkspaceService::PAGES), $followup);
        } catch (\Illuminate\Validation\ValidationException $refused) {
            if (($refused->errors()['source'][0] ?? null) !== self::UNPROVED_LEGACY_LINK) { throw $refused; }
            // #15: an older exposure saved with an unconfirmed link keeps its private details sealed (after the viewer's
            // access and Site were checked). The page opens withheld, with the "Earlier link to an incident" card.
            $detail = $this->withheldLegacyLink($exposure);
        }
        return $this->present($r, 'health-safety/chemical-exposure-show', ['detail' => $detail,
            'return_to' => ChemicalExposureReturn::sanitize($r->input('return_to')),
            'chemical_exposure_confirmation' => $this->confirmation($r, $exposure),
            // Exposure managers only: this exposure hasn't reached the H&S register yet (job 3).
            'delivery_alert' => app(\App\Services\HealthSafety\ChemicalExposureDeliveryProblems::class)->forExposure($r->user(), $exposure),
            'earlier_link' => $this->earlierLink($r, $exposure, $detail)]);
    }

    /**
     * #15 (boards 15-1 to 15-3, approved 2 Nov): an exposure saved with a link to an incident before links were checked.
     * An exposure manager gets every later review and can record one; anyone else who can open the record sees only
     * whether it was reviewed (and the incident's reference and title when they can open that incident). Null when the
     * record has no such link.
     */
    private function earlierLink(Request $r, int $exposure, array $detail): ?array
    {
        if (($detail['exposure_access'] ?? null) !== 'readable' || ($detail['source_integrity'] ?? null) !== 'legacy_unknown') { return null; }
        $user = $r->user();
        if ($user->canDo('hazards.manage')) {
            try {
                $card = $this->recovery->card($user, $exposure);
                return [...$card, 'state' => $card['reviews'] === [] ? 'unreviewed' : 'reviewed', 'manager' => true];
            } catch (\Illuminate\Validation\ValidationException) {
                return null; // Not a linked-unknown origin: no card.
            } catch (HttpExceptionInterface $denial) {
                if (! in_array($denial->getStatusCode(), [403, 404], true)) { throw $denial; }
            } catch (ModelNotFoundException) {
                // Falls through to the reader's summary.
            } catch (LogicException $broken) {
                // A review history that doesn't prove itself is never shown; the page still opens with the summary.
                report($broken);
            }
        }
        $root = \App\Models\SubstanceExposureRecord::query()->whereKey($exposure)->first(['id', 'related_incident_id', 'original_context', 'source_schema_version']);
        if ($root === null || $root->related_incident_id === null || $root->original_context !== null) { return null; }
        $incident = app(\App\Services\Incidents\IncidentAccessService::class)->apply(\App\Models\ClientIncident::query(), $user)
            ->whereKey($root->related_incident_id)->first(['id', 'reference_number', 'title', 'occurred_at']);
        $reviewed = \App\Models\ChemicalExposureRecoveryCase::query()->where('exposure_id', $exposure)->where('revision', '>', 0)->exists();

        return ['state' => $reviewed ? 'reviewed' : 'unreviewed', 'manager' => false, 'expected_version' => null,
            'linked_incident' => $incident === null ? ['id' => null, 'reference' => null, 'title' => null, 'occurred_at' => null, 'readable' => false]
                : ['id' => (int) $incident->id, 'reference' => $incident->reference_number === null ? null : (string) $incident->reference_number, 'title' => $incident->title,
                    'occurred_at' => $incident->occurred_at?->toISOString(), 'readable' => true],
            'reviews' => [], 'files' => [], 'can' => ['record_review' => false, 'add_file' => false]];
    }

    private const UNPROVED_LEGACY_LINK = 'Original legacy association is unproved. Review its source before private body access.';

    /** The withheld page for an older exposure with an unconfirmed link: no record, no sections, nothing private. */
    private function withheldLegacyLink(int $exposure): array
    {
        $none = ['items' => null, 'page' => null, 'source_integrity' => 'repair_required'];

        return ['exposure_access' => 'readable', 'id' => $exposure, 'record' => null, 'source_integrity' => 'legacy_unknown',
            'command_context' => null, 'history' => $none, 'reviews' => $none, 'followups' => $none, 'attachments' => $none,
            'delivery' => ['state' => 'unconfirmed', 'attempts' => null],
            'can' => ['correct' => false, 'review' => false, 'assign_followup' => false, 'upload_evidence' => false, 'mark_entered_in_error' => false],
            'focused_followup_id' => null, 'followup_focus_state' => 'not_requested'];
    }

    /** #15: record a later review of the earlier link (exposure managers; the service proves access and the version). */
    public function reviewEarlierLink(Request $r, string|int $exposure)
    {
        $exposure = $this->id($exposure);
        $this->recovery->recordReview($r->user(), $exposure, $this->input($r));

        return redirect()->to('/health-safety/chemical-exposures/'.$exposure.'?section=connections')
            ->with('success', 'Review saved. The old record is unchanged; your review sits beside it.');
    }

    /** #15: the review form's incident search (JSON; exposure managers; the service re-checks access). */
    public function earlierLinkIncidents(Request $r, string|int $exposure)
    {
        $exposure = $this->id($exposure);
        $this->jsonOnly($r); $this->queryKeys($r, ['search']);
        $search = $r->query('search');
        abort_unless($search === null || is_string($search), 422);

        return $this->headers(response()->json(['items' => $this->recovery->incidentChoices($r->user(), $exposure, (string) ($search ?? ''))]));
    }

    /** #15: one file kept with the latest review (a PDF or Word file, up to 10 MB — the chemical evidence rule). */
    public function addEarlierLinkFile(Request $r, string|int $exposure)
    {
        $exposure = $this->id($exposure);
        $r->validate(['file' => ['required', 'file', 'mimes:pdf,doc,docx', 'max:10240']],
            ['file.mimes' => 'Choose a PDF or Word file.', 'file.max' => 'Choose a file up to 10 MB.']);
        app(\App\Services\HealthSafety\ChemicalExposureRecoveryEvidenceService::class)->upload($r->user(), $exposure, $r->file('file'), $this->input($r, ['file']));

        return redirect()->to('/health-safety/chemical-exposures/'.$exposure.'?section=connections')->with('success', 'File added to the review.');
    }

    public function downloadEarlierLinkFile(Request $r, string|int $exposure, string|int $file)
    {
        $exposure = $this->id($exposure); $file = $this->id($file);
        $descriptor = app(\App\Services\HealthSafety\ChemicalExposureRecoveryEvidenceService::class)->evidence($r->user(), $exposure, $file);

        return $this->headers(\Illuminate\Support\Facades\Storage::disk($descriptor['disk'])->download($descriptor['path'], $descriptor['original_name'],
            ['Content-Type' => $descriptor['mime'] ?: 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff']));
    }
    public function capture(Request $r)
    {
        $this->queryKeys($r, ['substance_id', 'site_id', 'after_id', 'search', 'incident_search', 'incident_page', 'return_to', 'receipt_key']);
        $this->explicitKeyIsJsonOnly($r);
        $substance = $this->id($r->input('substance_id'));
        $capture = $this->workspace->capture($r->user(), $substance, $r->only(['site_id', 'after_id', 'search', 'incident_search', 'incident_page']));
        $receipt = $this->confirmation($r, null, $substance);
        return $this->present($r, 'health-safety/chemical-exposure-capture', ['capture' => $capture,
            'return_to' => ChemicalExposureReturn::sanitize($r->input('return_to')),
            'chemical_exposure_confirmation' => $receipt,
            'chemical_exposure_href' => $receipt === null ? null : $this->workspace->href($r->user(), $receipt['root_id'])]);
    }
    public function captureOptions(Request $r)
    {
        $this->jsonOnly($r); $this->queryKeys($r, ['substance_id', 'site_id', 'after_id', 'search', 'incident_search', 'incident_page']);
        return $this->headers(response()->json($this->workspace->capture($r->user(), $this->id($r->input('substance_id')),
            $r->only(['site_id', 'after_id', 'search', 'incident_search', 'incident_page']))));
    }
    public function commandOptions(Request $r, string|int $exposure)
    {
        $exposure = $this->id($exposure);
        $this->jsonOnly($r); $this->queryKeys($r, ['site_id', 'after_id', 'search', 'incident_search', 'incident_page']);
        return $this->headers(response()->json($this->workspace->commandOptions($r->user(), $exposure, $r->query())));
    }
    public function receipt(Request $r, string|int $exposure)
    {
        $exposure = $this->id($exposure);
        $this->jsonOnly($r); $this->queryKeys($r, ['receipt_key']); $r->validate(['receipt_key' => ['required', 'uuid']]);
        return $this->headers(response()->json(['chemical_exposure_confirmation' => $this->confirmation($r, $exposure)]));
    }
    public function captureReceipt(Request $r)
    {
        $this->jsonOnly($r); $this->queryKeys($r, ['substance_id', 'receipt_key']); $r->validate(['receipt_key' => ['required', 'uuid']]);
        $substance = $this->id($r->input('substance_id'));
        // Minimal original create authority only; never call capture pickers or saved source detail here.
        return $this->headers(response()->json(['chemical_exposure_confirmation' => $this->confirmation($r, null, $substance)]));
    }
    public function storeExposureRecord(Request $r, string|int $substance)
    {
        $substance = $this->id($substance);
        return $this->accepted($r, $this->source->create($substance, $r->user(), $this->input($r)), $substance);
    }
    public function correct(Request $r, string|int $exposure) {
        $exposure = $this->id($exposure); return $this->accepted($r, $this->source->correct($exposure, $r->user(), $this->input($r))); }
    public function review(Request $r, string|int $exposure) {
        $exposure = $this->id($exposure); return $this->accepted($r, $this->source->review($exposure, $r->user(), $this->input($r))); }
    public function markEnteredInError(Request $r, string|int $exposure) {
        $exposure = $this->id($exposure); return $this->accepted($r, $this->source->markEnteredInError($exposure, $r->user(), $this->input($r))); }
    public function assignFollowup(Request $r, string|int $exposure) {
        $exposure = $this->id($exposure); return $this->accepted($r, $this->source->assignFollowup($exposure, $r->user(), $this->input($r))); }
    public function reassignFollowup(Request $r, string|int $exposure, string|int $followup) {
        $exposure = $this->id($exposure); $followup = $this->id($followup); return $this->accepted($r, $this->source->reassignFollowup($exposure, $followup, $r->user(), $this->input($r))); }
    public function completeFollowup(Request $r, string|int $exposure, string|int $followup) {
        $exposure = $this->id($exposure); $followup = $this->id($followup); return $this->accepted($r, $this->source->completeFollowup($exposure, $followup, $r->user(), $this->input($r))); }
    public function uploadEvidence(Request $r, string|int $exposure)
    {
        $exposure = $this->id($exposure);
        $r->validate(['file' => ['required', 'file']]);
        return $this->accepted($r, $this->evidence->upload($exposure, $r->user(), $r->file('file'), $this->input($r, ['file'])));
    }
    public function removeEvidence(Request $r, string|int $exposure, string|int $evidence) {
        $exposure = $this->id($exposure); $evidence = $this->id($evidence); return $this->accepted($r, $this->evidence->remove($exposure, $evidence, $r->user(), $this->input($r))); }
    public function downloadEvidence(Request $r, string|int $exposure, string|int $evidence) {
        $exposure = $this->id($exposure); $evidence = $this->id($evidence); return $this->downloads->download($r->user(), $exposure, $evidence); }
    public function deliveryAttempts(Request $r, string|int $exposure)
    {
        $exposure = $this->id($exposure);
        $this->jsonOnly($r); $this->queryKeys($r, []);
        return $this->headers(response()->json($this->workspace->deliveryAttempts($r->user(), $exposure)));
    }
    /** Exposure managers inspect delivery attempts (job 3). JSON only; GET never dispatches. */
    public function deliveries(Request $r, string|int $exposure)
    {
        $exposure = $this->id($exposure);
        $this->recoveryJson($r); $this->recoveryQuery($r, ['page']);
        return $this->headers(response()->json($this->deliveryWorkspace->index($r->user(), $exposure, $r->query())));
    }
    public function delivery(Request $r, string|int $exposure, string|int $delivery)
    {
        $exposure = $this->id($exposure); $delivery = $this->id($delivery);
        $this->recoveryJson($r); $this->recoveryQuery($r, []);
        return $this->headers(response()->json(['inspection' => $this->deliveryWorkspace->detail($r->user(), $exposure, $delivery)]));
    }
    /** Explicit POST with no input: a retry carries no new command, callback, prose or file. */
    public function retryDelivery(Request $r, string|int $exposure, string|int $delivery)
    {
        $exposure = $this->id($exposure); $delivery = $this->id($delivery);
        $this->recoveryJson($r); $this->recoveryQuery($r, []);
        if ($r->request->count() > 0 || $r->json()->count() > 0 || $r->allFiles() !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages(['delivery' => 'A retry takes no input.']);
        }
        return $this->headers(response()->json($this->deliveryWorkspace->retry($r->user(), $exposure, $delivery)));
    }
    /**
     * Evidence recovery: the signed-in manager's own upload attempts that never became a saved attachment, and removed
     * files whose physical clean-up is still pending. The page reads and acts through the JSON routes below; each
     * call re-checks current exposure access, and only the person who made the attempt sees it.
     */
    public function recovery(Request $r)
    {
        $this->queryKeys($r, []);
        $can = $this->recoveryAccess($r);

        return $this->headers(Inertia::render('health-safety/chemical-exposure-recovery', ['can' => ['inspect' => $can, 'retry' => $can]])->toResponse($r));
    }
    public function recoveryStages(Request $r)
    {
        $this->jsonOnly($r); $this->queryKeys($r, ['after_id']);
        return $this->headers(response()->json($this->evidence->stagingAttemptsForViewer($r->user(), $this->cursor($r))));
    }
    public function recoveryStage(Request $r, string|int $stage)
    {
        $stage = $this->id($stage); $this->jsonOnly($r); $this->queryKeys($r, []);
        return $this->headers(response()->json(['staging' => $this->evidence->inspectStaging($r->user(), $stage)]));
    }
    public function bindRecoveryStage(Request $r, string|int $stage)
    {
        $stage = $this->id($stage); $this->noInput($r);
        return $this->headers(response()->json(['staging' => $this->evidence->bindAcceptedStage($r->user(), $stage)]));
    }
    public function cleanRecoveryStage(Request $r, string|int $stage)
    {
        $stage = $this->id($stage); $this->noInput($r);
        return $this->headers(response()->json(['staging' => $this->evidence->retryStagingCleanup($r->user(), $stage)]));
    }
    public function recoveryCleanups(Request $r)
    {
        $this->jsonOnly($r); $this->queryKeys($r, ['after_id']);
        return $this->headers(response()->json($this->evidence->cleanupAttemptsForViewer($r->user(), $this->cursor($r))));
    }
    public function retryRecoveryCleanup(Request $r, string|int $cleanup)
    {
        $cleanup = $this->id($cleanup); $this->noInput($r);
        return $this->headers(response()->json(['cleanup' => $this->evidence->retryRemovalCleanup($r->user(), $cleanup)]));
    }
    /** A recovery action carries no new command, value or file: anything sent with it is refused. */
    private function noInput(Request $r): void
    {
        $this->jsonOnly($r); $this->queryKeys($r, []);
        if ($r->request->count() > 0 || $r->json()->count() > 0 || $r->allFiles() !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages(['recovery' => 'A recovery action takes no input.']);
        }
    }
    private function cursor(Request $r): int
    {
        if (! $r->has('after_id')) { return 0; }
        $this->selection($r->input('after_id'));
        return (int) $r->input('after_id');
    }
    /** Whether the viewer may use recovery now (exposure management); the JSON routes check again on every call. */
    private function recoveryAccess(Request $r): bool
    {
        try {
            return $this->workspace->recoveryAdmission($r->user())['can']['inspect'];
        } catch (HttpExceptionInterface $denied) {
            if (! in_array($denied->getStatusCode(), [403, 404], true)) { throw $denied; }
            return false;
        }
    }
    private function accepted(Request $r, array $receipt, ?int $substanceId = null)
    {
        if (array_diff(array_keys($receipt), ChemicalExposureLedger::RECEIPT_KEYS)
            || count($receipt) !== count(ChemicalExposureLedger::RECEIPT_KEYS) || ($receipt['accepted'] ?? null) !== true
            || $receipt['actor_id'] !== (int) $r->user()->id || ! is_string($r->input('idempotency_key'))
            || $receipt['idempotency_key'] !== strtolower($r->input('idempotency_key'))) {
            throw new LogicException('The exposure command did not return its exact persisted actor-bound acceptance.');
        }
        $href = $this->workspace->href($r->user(), $receipt['root_id']);
        if ($this->directJson($r)) {
            return $this->headers(response()->json(['chemical_exposure_confirmation' => $receipt, 'chemical_exposure_href' => $href]));
        }
        $target = $href === null && $substanceId !== null ? ChemicalExposureReturn::capture($substanceId, $r->input('return_to'))
            : ChemicalExposureReturn::record($receipt['root_id'], $r->input('return_to'),
                is_string($r->input('section')) ? $r->input('section') : null);
        return $this->headers(redirect($target)->with('chemical_exposure_confirmation_hint',
            ['idempotency_key' => $receipt['idempotency_key'], 'root_id' => $receipt['root_id']])
            ->with('success', $receipt['changed'] ? 'Exposure work recorded.' : 'No change needed · confirmed.'));
    }
    private function confirmation(Request $r, ?int $id = null, ?int $substanceId = null): ?array
    {
        $this->explicitKeyIsJsonOnly($r);
        $explicit = $r->has('receipt_key');
        $hint = ! $explicit && ! $this->directJson($r) ? $r->session()->pull('chemical_exposure_confirmation_hint') : null;
        $key = $explicit ? $r->input('receipt_key') : (is_array($hint) ? ($hint['idempotency_key'] ?? null) : null);
        if ($key === null) { return null; }
        if (! is_string($key) || ! Str::isUuid($key)) { if ($explicit) { abort(422); } return null; }
        if (! $explicit && $id !== null && (! is_array($hint) || ($hint['root_id'] ?? null) !== $id)) { return null; }
        try { return $this->workspace->confirmation($r->user(), $key, $id, $substanceId); }
        catch (ModelNotFoundException $missing) { if ($explicit) { throw $missing; } return null; }
        catch (HttpExceptionInterface $denied) {
            if ($explicit || ! in_array($denied->getStatusCode(), [403, 404], true)) { throw $denied; } return null;
        }
    }
    private function input(Request $r, array $extra = []): array
    {
        return $r->except(['_token', '_method', 'return_to', 'section', 'action', ...$extra]);
    }
    private function queryKeys(Request $r, array $keys): void { abort_if(array_diff(array_keys($r->query()), $keys) !== [], 422); }
    private function selection(mixed $value): void
    {
        abort_unless((is_int($value) || is_string($value)) && preg_match('/^[1-9]\d*$/D', (string) $value)
            && strlen((string) $value) <= 10 && (float) $value <= 2147483647, 422);
    }
    private function id(mixed $value): int
    {
        abort_unless((is_int($value) || is_string($value)) && preg_match('/^[1-9]\d*$/D', (string) $value)
            && strlen((string) $value) <= 10 && (float) $value <= 2147483647, 404);
        return (int) $value;
    }
    private function directJson(Request $r): bool { return ! $r->header('X-Inertia') && $r->expectsJson(); }
    private function jsonOnly(Request $r): void { abort_unless($this->directJson($r), 404); }
    private function recoveryJson(Request $r): void { abort_unless($this->directJson($r), 406); }
    private function recoveryQuery(Request $r, array $keys): void { abort_if(array_diff(array_keys($r->query()), $keys) !== [], 400); }
    private function explicitKeyIsJsonOnly(Request $r): void { if ($r->has('receipt_key')) { $this->jsonOnly($r); } }
    private function present(Request $r, string $component, array $props)
    {
        if ($this->directJson($r)) { return $this->headers(response()->json($props)); }
        // These props are already eagerly current. A partial must replace retained private rows and counts.
        $fresh = array_map(fn ($value) => Inertia::always($value), $props);
        return $this->headers(Inertia::render($component, $fresh)->toResponse($r));
    }
    private function headers($response)
    {
        $response->headers->set('Cache-Control', 'private, no-store, no-cache, must-revalidate');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        return $response;
    }
}
