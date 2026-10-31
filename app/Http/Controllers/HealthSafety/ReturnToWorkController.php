<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Concerns\ServesPrivateAttachments;
use App\Http\Controllers\Controller;
use App\Models\ReturnToWorkPlan;
use App\Models\WorkplaceInjury;
use App\Models\WorkplaceInjuryAttachment;
use App\Services\HealthSafety\InjuryAccessService;
use App\Services\HealthSafety\InjuryRegisterService;
use App\Services\HealthSafety\InjuryWorkspaceService;
use App\Services\HealthSafety\WorkplaceInjuryRecoveryService;
use App\Services\HealthSafety\WorkplaceInjurySourceService;
use App\Support\HealthSafety\InjuryReturn;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Permanent injury pages. Domain services own all source authority, versions and writes. */
class ReturnToWorkController extends Controller
{
    use ServesPrivateAttachments;

    public function __construct(private readonly InjuryAccessService $access, private readonly InjuryWorkspaceService $workspace,
        private readonly InjuryRegisterService $register, private readonly WorkplaceInjurySourceService $source,
        private readonly WorkplaceInjuryRecoveryService $recovery) {}

    public function index(Request $request): Response|RedirectResponse|JsonResponse
    {
        if ($request->filled('injury')) {
            $id = $this->positive($request->input('injury'));
            $this->access->withCurrentViewer($request->user(), function ($current, $reads, $sites) use ($id): int {
                $identity = $reads->query(WorkplaceInjury::query())->select('id')->findOrFail($id);
                return (int) $this->access->assertInjury($identity, $current, $reads, $sites)->id;
            });
            return redirect(InjuryReturn::record($id, InjuryReturn::fromRegister($request->query()),
                is_string($request->input('section')) ? $request->input('section') : null,
                $request->only(['plan', 'assessment', 'duty', 'attachment']),
                is_string($request->input('action')) ? $request->input('action') : null));
        }
        $props = $this->register->index($request->user(), $request->query());
        return $this->json($request) ? response()->json($props) : Inertia::render('health-safety/injuries/workspace-index', $props);
    }

    public function create(Request $request): Response|JsonResponse
    {
        $capture = $this->workspace->captureOptions($request->user(), $this->siteFilter($request), $request->only(['search', 'page']));
        if ($request->has('receipt_key')) { abort_unless($this->json($request), 404); }
        $confirmation = $this->confirmation($request);
        $href = $confirmation ? $this->readableHref($request, $confirmation['injury_id']) : null;
        $props = ['capture' => $capture, 'return_to' => InjuryReturn::sanitize($request->input('return_to')),
            'injury_confirmation' => $confirmation, 'injury_href' => $href];
        return $this->json($request) ? response()->json($props) : Inertia::render('health-safety/injuries/create', $props);
    }

    public function show(Request $request, WorkplaceInjury $injury): Response|JsonResponse
    {
        if ($request->has('receipt_key')) { abort_unless($this->json($request), 404); }
        $detail = $this->workspace->detail($request->user(), (int) $injury->id);
        $props = ['detail' => $detail, 'return_to' => InjuryReturn::sanitize($request->input('return_to')),
            'injury_confirmation' => $this->confirmation($request, (int) $injury->id),
            'injury_href' => InjuryReturn::record((int) $injury->id, $request->input('return_to'))];
        return $this->json($request) ? response()->json($props) : Inertia::render('health-safety/injuries/show', $props);
    }

    public function captureOptions(Request $request): JsonResponse
    {
        return response()->json($this->workspace->captureOptions($request->user(), $this->siteFilter($request), $request->only(['search', 'page'])));
    }
    public function commandOptions(Request $request, WorkplaceInjury $injury): JsonResponse
    {
        return response()->json($this->workspace->commandOptions($request->user(), (int) $injury->id, $request->only(['search', 'page', 'site_id'])));
    }
    public function recoveryOptions(Request $request, WorkplaceInjury $injury): JsonResponse
    {
        return response()->json($this->workspace->recoveryOptions($request->user(), (int) $injury->id, $request->only(['search', 'page'])));
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        return $this->accepted($request, $this->source->create($request->user(), $this->commandInput($request)), true);
    }
    public function update(Request $request, WorkplaceInjury $injury): RedirectResponse|JsonResponse
    {
        return $this->accepted($request, $this->source->update($injury, $request->user(), $this->commandInput($request)));
    }
    public function transitionStatus(Request $request, WorkplaceInjury $injury): RedirectResponse|JsonResponse
    {
        return $this->accepted($request, $this->source->transition($injury, $request->user(), $this->commandInput($request)));
    }
    public function assignCoordinator(Request $request, WorkplaceInjury $injury): RedirectResponse|JsonResponse
    {
        return $this->accepted($request, $this->source->assign($injury, $request->user(), $this->commandInput($request)));
    }
    public function recordReview(Request $request, WorkplaceInjury $injury): RedirectResponse|JsonResponse
    {
        return $this->accepted($request, $this->recovery->recordReview($injury, $request->user(), $this->commandInput($request)));
    }
    public function storeRtwPlan(Request $request, WorkplaceInjury $injury): RedirectResponse|JsonResponse
    {
        return $this->accepted($request, $this->recovery->createPlan($injury, $request->user(), $this->commandInput($request)));
    }
    public function updateRtwPlan(Request $request, ReturnToWorkPlan $rtwPlan): RedirectResponse|JsonResponse
    {
        return $this->accepted($request, $this->recovery->updatePlan($this->parentInjury($rtwPlan), $rtwPlan,
            $request->user(), $this->commandInput($request)));
    }
    public function storeCapacityAssessment(Request $request, WorkplaceInjury $injury): RedirectResponse|JsonResponse
    {
        return $this->accepted($request, $this->recovery->recordCapacity($injury, $request->user(), $this->commandInput($request)));
    }
    public function storeModifiedDuty(Request $request, ReturnToWorkPlan $rtwPlan): RedirectResponse|JsonResponse
    {
        return $this->accepted($request, $this->recovery->addModifiedDuty($this->parentInjury($rtwPlan), $rtwPlan,
            $request->user(), $this->commandInput($request)));
    }
    public function uploadAttachment(Request $request, WorkplaceInjury $injury): RedirectResponse|JsonResponse
    {
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);
        return $this->accepted($request, $this->recovery->uploadEvidence($injury, $request->user(), $file, $this->commandInput($request, ['file'])));
    }
    public function destroyAttachment(Request $request, WorkplaceInjury $injury, WorkplaceInjuryAttachment $attachment): RedirectResponse|JsonResponse
    {
        return $this->accepted($request, $this->recovery->removeEvidence($injury, $attachment, $request->user(), $this->commandInput($request)));
    }
    public function downloadAttachment(Request $request, WorkplaceInjury $injury, WorkplaceInjuryAttachment $attachment): StreamedResponse
    {
        $proof = $this->access->attachmentForDownload($injury, $attachment, $request->user());
        return $this->streamPrivateAttachment($proof['disk'], $proof['path'], $proof['original_name'], $proof['mime']);
    }

    public function export(Request $request): StreamedResponse
    {
        // Only materialised CSV cells survive the current callback; no delayed query or actor escapes into streaming.
        $output = fopen('php://temp/maxmemory:2097152', 'w+');
        if ($output === false) { throw new LogicException('The injury export could not be prepared.'); }
        try {
            $this->putCsv($output, $this->register->exportHeaders());
            $meta = $this->register->export($request->user(), $request->query(), fn (array $cells) => $this->putCsv($output, $cells));
            rewind($output);
        } catch (Throwable $failure) {
            fclose($output);
            throw $failure;
        }
        return response()->streamDownload(function () use ($output): void {
            try { fpassthru($output); } finally { fclose($output); }
        }, $meta['filename'], ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, no-cache']);
    }

    private function parentInjury(ReturnToWorkPlan $plan): WorkplaceInjury
    {
        $parent = ReturnToWorkPlan::query()->whereKey($plan->id)->value('workplace_injury_id');
        abort_unless($parent, 404);
        return WorkplaceInjury::query()->select('id')->findOrFail($parent);
    }

    private function accepted(Request $request, array $result, bool $created = false): RedirectResponse|JsonResponse
    {
        $keys = ['accepted', 'injury_id', 'source_type', 'source_id', 'actor_id', 'command', 'idempotency_key',
            'expected_version', 'version', 'source_version_id', 'changed', 'receipt_id', 'replayed'];
        $confirmation = array_intersect_key($result, array_flip($keys));
        if (($confirmation['accepted'] ?? null) !== true || ! is_int($confirmation['injury_id'] ?? null)) {
            throw new LogicException('The injury command did not return a persisted acceptance.');
        }
        $detail = $this->optionalDetail($request, $confirmation['injury_id']);
        $href = $detail === null ? null : InjuryReturn::record($confirmation['injury_id'], $request->input('return_to'));
        if ($this->json($request)) {
            return response()->json([$created ? 'capture' : 'detail' => $created ? $this->optionalCapture($request) : $detail,
                'injury_confirmation' => $confirmation, 'injury_href' => $href]);
        }
        $target = $href ?? '/health-safety/injuries/create';
        return redirect($target)->with('success', 'Workplace injury work recorded.')->with('injury_confirmation', $confirmation);
    }

    private function optionalDetail(Request $request, int $id): ?array
    {
        try { return $this->workspace->detail($request->user(), $id); }
        catch (ModelNotFoundException) { return null; }
        catch (HttpExceptionInterface $error) {
            if (! in_array($error->getStatusCode(), [403, 404], true)) { throw $error; }
            return null;
        }
    }
    private function optionalCapture(Request $request): ?array
    {
        try { return $this->workspace->captureOptions($request->user()); }
        catch (HttpExceptionInterface $error) {
            if (! in_array($error->getStatusCode(), [403, 404], true)) { throw $error; }
            return null;
        }
    }
    private function readableHref(Request $request, int $id): ?string
    {
        return $this->optionalDetail($request, $id) === null ? null : InjuryReturn::record($id, $request->input('return_to'));
    }
    private function confirmation(Request $request, ?int $id = null): ?array
    {
        $hint = $request->session()->get('injury_confirmation');
        $key = $request->has('receipt_key') ? $request->input('receipt_key') : (is_array($hint) ? ($hint['idempotency_key'] ?? null) : null);
        if (! is_string($key)) { return null; }
        if ($request->has('receipt_key')) {
            abort_unless(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $key) === 1, 422);
        }
        if ($id !== null && ! $request->has('receipt_key') && ($hint['injury_id'] ?? null) !== $id) { return null; }
        try {
            return $id === null ? $this->source->confirmationForKey($request->user(), $key)
                : $this->source->confirmationForViewer($request->user(), $id, $key);
        } catch (ModelNotFoundException|LogicException) { return null; }
        catch (HttpExceptionInterface $error) {
            if (! in_array($error->getStatusCode(), [403, 404], true)) { throw $error; }
            return null;
        }
    }
    private function commandInput(Request $request, array $extra = []): array
    {
        return $request->except(['_token', '_method', 'return_to', 'section', 'action', ...$extra]);
    }
    private function siteFilter(Request $request): ?int
    {
        return $request->filled('site_id') ? $this->positive($request->input('site_id')) : null;
    }
    private function positive(mixed $value): int
    {
        abort_unless((is_int($value) || is_string($value)) && preg_match('/^[1-9]\d*$/D', (string) $value) === 1
            && strlen((string) $value) <= 16 && (float) $value <= 9007199254740991, 404);
        return (int) $value;
    }
    private function json(Request $request): bool { return $request->expectsJson() && ! $request->header('X-Inertia'); }
}
