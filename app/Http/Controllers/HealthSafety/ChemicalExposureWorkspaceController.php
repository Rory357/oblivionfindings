<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Controller;
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
        private readonly ChemicalExposureDownloadService $downloads) {}

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
        $detail = $this->workspace->detail($r->user(), $exposure,
            $r->only(ChemicalExposureWorkspaceService::PAGES), $followup);
        return $this->present($r, 'health-safety/chemical-exposure-show', ['detail' => $detail,
            'return_to' => ChemicalExposureReturn::sanitize($r->input('return_to')),
            'chemical_exposure_confirmation' => $this->confirmation($r, $exposure)]);
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
