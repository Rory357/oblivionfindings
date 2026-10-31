<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Controller;
use App\Services\HealthSafety\PpeCommandService;
use App\Services\HealthSafety\PpePickerService;
use App\Services\HealthSafety\PpeRegisterService;
use App\Services\HealthSafety\PpeWorkspaceService;
use App\Support\HealthSafety\PpeReturn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use LogicException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Throwable;

/** HTTP composition only; canonical services own current access, source proof and every write. */
class PpeController extends Controller
{
    public function __construct(private readonly PpeWorkspaceService $workspace, private readonly PpeRegisterService $register,
        private readonly PpePickerService $pickers, private readonly PpeCommandService $commands) {}

    public function index(Request $request): HttpResponse|JsonResponse|RedirectResponse
    {
        if ($request->has('item') || $request->has('allocation')) {
            abort_if($request->has('item') && $request->has('allocation'), 422);
            $kind = $request->has('item') ? 'inventory' : 'allocations';
            $id = $this->positive($request->input($kind === 'inventory' ? 'item' : 'allocation'));
            $kind === 'inventory' ? $this->workspace->item($request->user(), $id) : $this->workspace->allocation($request->user(), $id);
            return redirect(PpeReturn::record($kind, $id, PpeReturn::fromRegister($request->query()),
                is_string($request->input('section')) ? $request->input('section') : null, $request->only(['attachment', ...PpeReturn::PAGES]),
                is_string($request->input('action')) ? $request->input('action') : null));
        }
        $props = [...$this->register->index($request->user(), $this->filters($request)), 'ppe_confirmation' => $this->confirmation($request)];
        return $request->expectsJson() ? response()->json($props)->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer') : $this->page($request, 'health-safety/ppe/index', $props);
    }

    public function showInventory(Request $request, int $inventory): HttpResponse|JsonResponse { return $this->show($request, 'inventory', $inventory); }
    public function showAllocation(Request $request, int $allocation): HttpResponse|JsonResponse { return $this->show($request, 'allocations', $allocation); }
    public function showInspection(Request $request, int $inspection): HttpResponse|JsonResponse { return $this->show($request, 'inspections', $inspection); }
    private function show(Request $request, string $kind, int $id): HttpResponse|JsonResponse
    {
        abort_if($request->has('receipt_key') && ! $request->expectsJson(), 404);
        $pages = $request->validate(array_fill_keys(PpeReturn::PAGES, ['sometimes', 'integer', 'min:1', 'max:2147483647']));
        $detail = match ($kind) {
            'inventory' => $this->workspace->item($request->user(), $id, $pages),
            'allocations' => $this->workspace->allocation($request->user(), $id, $pages),
            'inspections' => $this->workspace->inspection($request->user(), $id, $pages),
        };
        $confirmation = $this->confirmation($request);
        if ($confirmation !== null && ($confirmation['root_type'] !== 'inventory' || $confirmation['root_id'] !== ($detail['command_context']['root_id'] ?? null))) { $confirmation = null; }
        $props = ['kind' => $kind, 'detail' => $detail, 'return_to' => PpeReturn::sanitize($request->input('return_to')),
            'ppe_confirmation' => $confirmation];
        return $request->expectsJson() ? response()->json($props)->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer') : $this->page($request, 'health-safety/ppe/show', $props);
    }

    public function options(Request $request): JsonResponse
    {
        $filters = $request->validate(['site_id' => ['sometimes', 'integer', 'min:1'], 'staff_search' => ['sometimes', 'string', 'max:200'],
            'staff_page' => ['sometimes', 'integer', 'min:1', 'max:2147483647'], 'item_search' => ['sometimes', 'string', 'max:200'],
            'item_page' => ['sometimes', 'integer', 'min:1', 'max:2147483647']]);
        return response()->json($this->pickers->options($request->user(), $filters, 'create'))->header('Cache-Control', 'no-store');
    }
    public function commandConfirmation(Request $request): JsonResponse
    {
        abort_unless($request->expectsJson(), 404);
        return response()->json(['ppe_confirmation' => $this->confirmation($request)])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function evidenceRecovery(Request $request): HttpResponse|JsonResponse
    {
        $this->pickers->options($request->user(), [], 'create');
        return $this->page($request, 'health-safety/ppe/recovery');
    }
    public function stagingAttempts(Request $request): JsonResponse
    {
        abort_unless($request->expectsJson(), 404);
        $values = $request->validate(['after_id' => ['sometimes', 'integer', 'min:0', 'max:2147483647'], 'receipt_key' => ['sometimes', 'uuid']]);
        $result = isset($values['receipt_key']) ? $this->commands->stagingAttemptsForKey($request->user(), $values['receipt_key'], (int) ($values['after_id'] ?? 0))
            : $this->commands->stagingAttemptsForViewer($request->user(), (int) ($values['after_id'] ?? 0));
        return response()->json($result)->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }
    public function stagingAttempt(Request $request, int $staging): JsonResponse
    {
        abort_unless($request->expectsJson(), 404);
        return response()->json(['staging' => $this->commands->stagingForViewer($request->user(), $staging)])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }
    public function resumeStaging(Request $request, int $staging): JsonResponse
    {
        abort_unless($request->expectsJson(), 404);
        return response()->json(['staging' => $this->commands->resumeStagingCleanup($request->user(), $staging)])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }
    public function cleanupAttempts(Request $request): JsonResponse
    {
        abort_unless($request->expectsJson(), 404);
        $values = $request->validate(['after_id' => ['sometimes', 'integer', 'min:0', 'max:2147483647']]);
        return response()->json($this->commands->cleanupAttemptsForViewer($request->user(), (int) ($values['after_id'] ?? 0)))->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function export(Request $request): StreamedResponse
    {
        $output = fopen('php://temp', 'w+');
        if ($output === false) { throw new LogicException('The PPE export could not be prepared.'); }
        try {
            $this->putCsv($output, PpeRegisterService::CSV_HEADERS);
            $this->register->export($request->user(), $this->filters($request), fn (array $cells) => $this->putCsv($output, $cells));
            rewind($output);
        } catch (Throwable $failure) { fclose($output); throw $failure; }
        return response()->streamDownload(function () use ($output): void {
            try { fpassthru($output); } finally { fclose($output); }
        }, 'ppe-register-'.now('Pacific/Auckland')->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, no-cache']);
    }

    public function relocationCleanupAttempts(Request $request): JsonResponse
    {
        abort_unless($request->expectsJson(), 404);
        $values = $request->validate(['after_id' => ['sometimes', 'integer', 'min:0', 'max:2147483647']]);
        return response()->json($this->commands->relocationCleanupAttemptsForViewer($request->user(), (int) ($values['after_id'] ?? 0)))->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }
    public function relocationCleanup(Request $request, int $cleanup): JsonResponse
    {
        abort_unless($request->expectsJson(), 404);
        return response()->json(['relocation_cleanup' => $this->commands->relocationCleanupForViewer($request->user(), $cleanup)])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }
    public function resumeRelocationCleanup(Request $request, int $cleanup): JsonResponse
    {
        abort_unless($request->expectsJson(), 404);
        return response()->json(['relocation_cleanup' => $this->commands->resumeRelocationCleanup($request->user(), $cleanup)])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function storeType(Request $r): JsonResponse|RedirectResponse { return $this->accepted($r, $this->commands->createType($r->user(), $this->input($r))); }
    public function updateType(Request $r, int $type): JsonResponse|RedirectResponse { return $this->accepted($r, $this->commands->updateType($type, $r->user(), $this->input($r))); }
    public function activateType(Request $r, int $type): JsonResponse|RedirectResponse { return $this->accepted($r, $this->commands->setTypeActive($type, $r->user(), [...$this->input($r), 'active' => true])); }
    public function deactivateType(Request $r, int $type): JsonResponse|RedirectResponse { return $this->accepted($r, $this->commands->setTypeActive($type, $r->user(), [...$this->input($r), 'active' => false])); }
    public function storeInventory(Request $r): JsonResponse|RedirectResponse { return $this->accepted($r, $this->commands->createInventory($r->user(), $this->input($r))); }
    public function updateInventory(Request $r, int $inventory): JsonResponse|RedirectResponse { return $this->accepted($r, $this->commands->updateInventory($inventory, $r->user(), $this->input($r))); }
    public function condemn(Request $r, int $inventory): JsonResponse|RedirectResponse { return $this->accepted($r, $this->commands->condemn($inventory, $r->user(), $this->input($r))); }
    public function dispose(Request $r, int $inventory): JsonResponse|RedirectResponse { return $this->accepted($r, $this->commands->dispose($inventory, $r->user(), $this->input($r))); }
    public function allocate(Request $r, int $inventory): JsonResponse|RedirectResponse { return $this->accepted($r, $this->commands->allocate($inventory, $r->user(), $this->input($r))); }
    public function acknowledge(Request $r, int $allocation): JsonResponse|RedirectResponse { return $this->accepted($r, $this->commands->acknowledge($allocation, $r->user(), $this->input($r))); }
    public function acknowledgeOwn(Request $r, int $allocation): JsonResponse|RedirectResponse { return $this->accepted($r, $this->commands->acknowledge($allocation, $r->user(), $this->input($r), true)); }
    public function returnPpe(Request $r, int $allocation): JsonResponse|RedirectResponse { return $this->accepted($r, $this->commands->returnAllocation($allocation, $r->user(), $this->input($r))); }
    public function storeInspection(Request $r, int $inventory): JsonResponse|RedirectResponse { return $this->accepted($r, $this->commands->inspect($inventory, $r->user(), $this->input($r))); }
    public function uploadInventoryAttachment(Request $r, int $inventory): JsonResponse|RedirectResponse { return $this->accepted($r, $this->commands->uploadAttachment('inventory', $inventory, $r->user(), $this->input($r))); }
    public function uploadAllocationAttachment(Request $r, int $allocation): JsonResponse|RedirectResponse { return $this->accepted($r, $this->commands->uploadAttachment('allocation', $allocation, $r->user(), $this->input($r))); }
    public function uploadInspectionAttachment(Request $r, int $inspection): JsonResponse|RedirectResponse { return $this->accepted($r, $this->commands->uploadAttachment('inspection', $inspection, $r->user(), $this->input($r))); }
    public function destroyInventoryAttachment(Request $r, int $inventory, int $attachment): JsonResponse|RedirectResponse { return $this->accepted($r, $this->commands->removeAttachment('inventory', $inventory, $attachment, $r->user(), $this->input($r))); }
    public function destroyAllocationAttachment(Request $r, int $allocation, int $attachment): JsonResponse|RedirectResponse { return $this->accepted($r, $this->commands->removeAttachment('allocation', $allocation, $attachment, $r->user(), $this->input($r))); }
    public function destroyInspectionAttachment(Request $r, int $inspection, int $attachment): JsonResponse|RedirectResponse { return $this->accepted($r, $this->commands->removeAttachment('inspection', $inspection, $attachment, $r->user(), $this->input($r))); }
    public function relocateInventoryAttachment(Request $r, int $inventory, int $attachment): JsonResponse|RedirectResponse { return $this->accepted($r, $this->commands->relocateLegacyAttachment('inventory', $inventory, $attachment, $r->user(), $this->input($r))); }
    public function relocateAllocationAttachment(Request $r, int $allocation, int $attachment): JsonResponse|RedirectResponse { return $this->accepted($r, $this->commands->relocateLegacyAttachment('allocation', $allocation, $attachment, $r->user(), $this->input($r))); }
    public function relocateInspectionAttachment(Request $r, int $inspection, int $attachment): JsonResponse|RedirectResponse { return $this->accepted($r, $this->commands->relocateLegacyAttachment('inspection', $inspection, $attachment, $r->user(), $this->input($r))); }

    public function downloadInventoryAttachment(Request $r, int $inventory, int $attachment): StreamedResponse { return $this->download($r, 'inventory', $inventory, $attachment); }
    public function downloadAllocationAttachment(Request $r, int $allocation, int $attachment): StreamedResponse { return $this->download($r, 'allocations', $allocation, $attachment); }
    public function downloadInspectionAttachment(Request $r, int $inspection, int $attachment): StreamedResponse { return $this->download($r, 'inspections', $inspection, $attachment); }
    private function download(Request $request, string $kind, int $parentId, int $attachmentId): StreamedResponse
    {
        // This internal descriptor is deliberately never serialized into the page/receipt.
        $file = $this->workspace->evidence($request->user(), $kind, $parentId, $attachmentId);
        abort_unless($file['disk'] === 'private' && is_int($file['size']) && is_string($file['digest']), 404);
        $input = Storage::disk('private')->readStream($file['path']);
        abort_unless(is_resource($input), 404);
        $verified = fopen('php://temp/maxmemory:1048576', 'w+');
        if ($verified === false) { fclose($input); throw new LogicException('Evidence could not be prepared.'); }
        try {
            $size = stream_copy_to_stream($input, $verified);
            rewind($verified);
            $hash = hash_init('sha256');
            $hashed = hash_update_stream($hash, $verified);
            abort_unless($size === $file['size'] && $hashed === $file['size'] && hash_equals($file['digest'], hash_final($hash)), 404);
            rewind($verified);
        } catch (Throwable $failure) { fclose($verified); throw $failure; }
        finally { fclose($input); }
        // Emit only the verified opened bytes; never reopen the path after proof.
        return response()->streamDownload(function () use ($verified): void {
            try { fpassthru($verified); } finally { fclose($verified); }
        }, $file['original_name'], ['Content-Type' => $file['mime'] ?: 'application/octet-stream',
            'Content-Security-Policy' => "default-src 'none'; sandbox; frame-ancestors 'none'",
            'Referrer-Policy' => 'no-referrer', 'Cache-Control' => 'no-store, no-cache']);
    }
    public function cleanup(Request $request, int $cleanup): JsonResponse
    {
        abort_unless($request->expectsJson(), 404);
        return response()->json(['cleanup' => $this->commands->cleanupForViewer($request->user(), $cleanup)])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }
    public function resumeCleanup(Request $request, int $cleanup): JsonResponse
    {
        abort_unless($request->expectsJson(), 404);
        return response()->json(['cleanup' => $this->commands->resumeCleanup($request->user(), $cleanup)])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    private function page(Request $request, string $component, array $props = []): HttpResponse|JsonResponse
    {
        return Inertia::render($component, $props)->toResponse($request)
            ->header('Cache-Control', 'private, no-store, no-cache, must-revalidate')
            ->header('Referrer-Policy', 'no-referrer');
    }

    private function accepted(Request $request, array $result): JsonResponse|RedirectResponse
    {
        $keys = ['accepted', 'actor_id', 'command', 'idempotency_key', 'root_type', 'root_id', 'source_type', 'source_id', 'expected_version', 'version', 'source_version_id'];
        $receipt = array_intersect_key($result, array_flip($keys));
        if (($receipt['accepted'] ?? null) !== true || count($receipt) !== count($keys)) { throw new LogicException('The PPE command did not return persisted acceptance.'); }
        if ($request->expectsJson()) { return response()->json(['ppe_confirmation' => $receipt])->header('Cache-Control', 'no-store'); }
        $target = $receipt['root_type'] === 'type' ? PpeReturn::REGISTER.'?tab=types' : PpeReturn::record(
            in_array($receipt['source_type'], ['allocation', 'inspection'], true) ? $receipt['source_type'].'s' : 'inventory',
            in_array($receipt['source_type'], ['allocation', 'inspection'], true) ? $receipt['source_id'] : $receipt['root_id'], $request->input('return_to'));
        return redirect($target)->with('ppe_confirmation', $receipt)->with('success', 'PPE work recorded.');
    }
    private function confirmation(Request $request): ?array
    {
        abort_if($request->has('receipt_key') && ! $request->expectsJson(), 404);
        $explicit = $request->has('receipt_key');
        $hint = $explicit || $request->expectsJson() ? null : $request->session()->pull('ppe_confirmation');
        $key = $explicit ? $request->input('receipt_key') : (is_array($hint) ? ($hint['idempotency_key'] ?? null) : null);
        if ($key === null) { return null; }
        $valid = is_string($key) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $key);
        if (! $valid && ! $explicit) { return null; }
        abort_unless($valid, 422);
        try { return $this->commands->confirmationForKey($request->user(), $key); }
        catch (ModelNotFoundException $missing) { if ($explicit) { throw $missing; } return null; }
        catch (HttpExceptionInterface $denial) { if ($explicit || ! in_array($denial->getStatusCode(), [401, 403, 404], true)) { throw $denial; } return null; }
    }
    private function filters(Request $request): array
    {
        return $request->validate(['tab' => ['sometimes', 'in:'.implode(',', PpeReturn::TABS)], 'site_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'ppe_type_id' => ['sometimes', 'nullable', 'integer', 'min:1'], 'category' => ['sometimes', 'nullable', 'in:head,eye,ear,respiratory,hand,foot,high_visibility,body,fall_protection,other'],
            'status' => ['sometimes', 'nullable', 'in:available,allocated,maintenance,condemned,disposed'], 'search' => ['sometimes', 'nullable', 'string', 'max:200'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:2147483647'], 'allocations_page' => ['sometimes', 'integer', 'min:1', 'max:2147483647']]);
    }
    private function input(Request $request): array { return $request->except(['_token', '_method', 'return_to', 'section', 'action']); }
    private function positive(mixed $value): int
    {
        abort_unless((is_int($value) || is_string($value)) && preg_match('/^[1-9]\d*$/D', (string) $value) && strlen((string) $value) <= 16 && (float) $value <= 9007199254740991, 422);
        return (int) $value;
    }
}
