<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ServesPrivateAttachments;
use App\Models\Site;
use App\Services\HealthSafety\ChemicalAccessService;
use App\Services\HealthSafety\ChemicalCommandService;
use App\Services\HealthSafety\ChemicalRegisterReadService;
use App\Support\HealthSafety\InjuryReturn;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Public reference workspace only. Identifiable exposures remain separately authorised. */
final class ChemicalPublicWorkspaceController extends Controller
{
    use ServesPrivateAttachments;

    private const REGISTER = '/health-safety/substances';
    private const RECEIPT_KEYS = ['accepted', 'actor_id', 'command', 'idempotency_key', 'root_type', 'root_id',
        'source_type', 'source_id', 'parent_id', 'expected_version', 'parent_expected_version', 'version',
        'source_version_id', 'changed'];
    private const FILTER_KEYS = ['tab', 'q', 'site_id', 'physical_form', 'status', 'controlled', 'sds_state', 'period', 'page'];

    public function __construct(private readonly ChemicalRegisterReadService $reader,
        private readonly ChemicalCommandService $commands, private readonly ChemicalAccessService $access) {}

    public function index(Request $r)
    {
        $filters = $this->filters($r);
        // Prove current index admission before converging an old modal bookmark.
        $props = $this->reader->index($r->user(), $filters);
        if ($r->filled('substance')) {
            $id = $this->id($r->input('substance'));
            $this->reader->detail($r->user(), $id);
            return $this->headers(redirect($this->record($id, $this->register($filters))));
        }
        return $this->present($r, 'health-safety/substances/index', $props);
    }

    public function create(Request $r)
    {
        $capture = $this->capture($r);
        $receipt = $this->hint($r);
        return $this->present($r, 'health-safety/substances/create', ['capture' => $capture,
            'return_to' => $this->back($r->input('return_to')) ?? self::REGISTER,
            'chemical_confirmation' => $receipt,
            'chemical_href' => $receipt ? $this->readableHref($r, $this->parentId($receipt)) : null]);
    }

    public function captureOptions(Request $r) { return $this->headers(response()->json($this->capture($r))); }

    private function capture(Request $r): array
    {
        return $this->access->withCurrentViewer($r->user(), function ($current, $reads, $sites): array {
            $this->access->assertOperation($current, 'capture');
            return ['sites' => $reads->query(Site::query())->whereIn('id', $sites)->active()->notArchived()
                ->whereNull('archived_at')->orderBy('name')->get(['id', 'name'])->toArray(),
                'options' => ['physical_forms' => ['solid', 'liquid', 'gas', 'powder', 'aerosol', 'paste', 'other'],
                    'lifecycle_statuses' => ['active', 'inactive', 'removed'],
                    'sds_file_extensions' => ['pdf', 'doc', 'docx'], 'sds_max_kb' => 10240],
                'can' => ['create' => true]];
        });
    }

    public function show(Request $r, $substance)
    {
        $id = $this->id($substance);
        $detail = $this->reader->detail($r->user(), $id);
        return $this->present($r, 'health-safety/substances/show', ['detail' => $detail,
            'return_to' => $this->back($r->input('return_to')) ?? self::REGISTER,
            'chemical_confirmation' => $this->hint($r, $id), 'chemical_href' => $this->record($id, $r->input('return_to'))]);
    }

    public function history(Request $r, $substance)
    {
        $id = $this->id($substance);
        $selection = $r->validate(['source_type' => ['sometimes', 'in:catalogue,sds,storage'],
            'source_id' => ['sometimes', 'required_with:source_type', 'integer', 'min:1', 'max:9007199254740991']]);
        $history = $this->access->withCurrentViewer($r->user(), function ($current, $reads, $sites) use ($id, $selection): array {
            $parent = $this->access->assertCatalogue($id, $current, $reads, 'view', true);
            $kind = $selection['source_type'] ?? 'catalogue';
            $source = match ($kind) {
                'storage' => $this->access->assertStorage($id, $this->id($selection['source_id']), $current, $reads, $sites),
                'sds' => $this->access->assertSds($id, $this->id($selection['source_id']), $current, $reads),
                default => null,
            };
            if ($kind === 'catalogue' && isset($selection['source_id'])) { abort_unless($this->id($selection['source_id']) === $id, 404); }
            return $this->commands->historyForCurrentReads($parent, $source, $current, $reads, $sites);
        });
        return $this->headers(response()->json(['history' => $history]));
    }

    public function confirmation(Request $r, $substance, string $key)
    {
        abort_unless($r->expectsJson(), 404);
        $id = $this->id($substance);
        $selection = $r->validate(['source_type' => ['sometimes', 'in:catalogue,sds,storage'],
            'source_id' => ['sometimes', 'required_with:source_type', 'integer', 'min:1', 'max:9007199254740991']]);
        $kind = $selection['source_type'] ?? 'catalogue';
        $sourceId = isset($selection['source_id']) ? $this->id($selection['source_id']) : $id;
        $receipt = $this->commands->confirmationForViewer($r->user(), $kind, $sourceId, $key);
        if ($receipt !== null && $this->parentId($receipt) !== $id) { $receipt = null; }
        return $this->headers(response()->json(['chemical_confirmation' => $receipt,
            'chemical_href' => $receipt ? $this->readableHref($r, $id) : null]));
    }

    public function recoverSds(Request $r, $substance, string $key)
    {
        abort_unless($r->expectsJson(), 404);
        $id = $this->id($substance);
        $receipt = $this->commands->recoverSds($id, $r->user(), $key);
        return $this->headers(response()->json(['chemical_confirmation' => $receipt,
            'chemical_href' => $receipt ? $this->readableHref($r, $id) : null]));
    }

    public function store(Request $r) { return $this->accepted($r, $this->commands->create($r->user(), $this->input($r))); }
    public function update(Request $r, $substance) { return $this->accepted($r, $this->commands->update($this->id($substance), $r->user(), $this->input($r))); }
    public function updateStatus(Request $r, $substance) { return $this->accepted($r, $this->commands->status($this->id($substance), $r->user(), $this->input($r))); }
    public function storeStorageLocation(Request $r, $substance) { return $this->accepted($r, $this->commands->createStorage($this->id($substance), $r->user(), $this->input($r))); }
    public function updateStorageLocation(Request $r, $substance, $storage) { return $this->accepted($r, $this->commands->updateStorage($this->id($substance), $this->id($storage), $r->user(), $this->input($r))); }
    public function auditStorageLocation(Request $r, $substance, $storage) { return $this->accepted($r, $this->commands->auditInventory($this->id($substance), $this->id($storage), $r->user(), $this->input($r))); }
    public function archiveStorageLocation(Request $r, $substance, $storage) { return $this->accepted($r, $this->commands->archiveStorage($this->id($substance), $this->id($storage), $r->user(), $this->input($r))); }
    public function restoreStorageLocation(Request $r, $substance, $storage) { return $this->accepted($r, $this->commands->restoreStorage($this->id($substance), $this->id($storage), $r->user(), $this->input($r))); }

    public function storeSds(Request $r, $substance)
    {
        $r->validate(['file' => ['required', 'file', 'mimes:pdf,doc,docx', 'max:10240']]);
        return $this->accepted($r, $this->commands->uploadSds($this->id($substance), $r->user(),
            $r->file('file'), $this->input($r, true)));
    }
    public function correctSdsMetadata(Request $r, $substance, $sds) { return $this->accepted($r, $this->commands->correctSdsMetadata($this->id($substance), $this->id($sds), $r->user(), $this->input($r))); }
    public function setCurrentSds(Request $r, $substance, $sds) { return $this->accepted($r, $this->commands->setCurrentSds($this->id($substance), $this->id($sds), $r->user(), $this->input($r))); }
    public function downloadSds(Request $r, $substance, $sds)
    {
        $proof = $this->reader->sdsForDownload($r->user(), $this->id($substance), $this->id($sds));
        return $this->headers($this->streamPrivateAttachment($proof['disk'], $proof['path'], $proof['name']));
    }

    private function input(Request $r, bool $upload = false): array
    {
        return $r->except($upload ? ['_token', '_method', 'return_to', 'file'] : ['_token', '_method', 'return_to']);
    }
    private function accepted(Request $r, array $receipt)
    {
        if (array_keys(array_diff_key($receipt, array_flip(self::RECEIPT_KEYS))) !== []
            || count($receipt) !== count(self::RECEIPT_KEYS) || ($receipt['accepted'] ?? null) !== true) {
            throw new LogicException('The public chemical command did not return its persisted acceptance whitelist.');
        }
        $href = $this->readableHref($r, $this->parentId($receipt));
        if ($r->expectsJson()) { return $this->headers(response()->json(['chemical_confirmation' => $receipt, 'chemical_href' => $href])); }
        return $this->headers(redirect($href ?? self::REGISTER.'/create')
            ->with('chemical_confirmation_hint', array_intersect_key($receipt, array_flip(['source_type', 'source_id', 'idempotency_key'])))
            ->with('success', 'Chemical record saved.'));
    }
    private function hint(Request $r, ?int $parentId = null): ?array
    {
        if (! $r->hasSession()) { return null; }
        $hint = $r->session()->pull('chemical_confirmation_hint');
        if (! is_array($hint) || ! in_array($hint['source_type'] ?? null, ['catalogue', 'sds', 'storage'], true)
            || ! is_int($hint['source_id'] ?? null) || ! is_string($hint['idempotency_key'] ?? null)) { return null; }
        try { $receipt = $this->commands->confirmationForViewer($r->user(), $hint['source_type'], $hint['source_id'], $hint['idempotency_key']); }
        catch (ModelNotFoundException) { return null; }
        catch (HttpExceptionInterface $denied) { if (in_array($denied->getStatusCode(), [403, 404], true)) { return null; } throw $denied; }
        return $receipt !== null && ($parentId === null || $this->parentId($receipt) === $parentId) ? $receipt : null;
    }
    private function parentId(array $receipt): int
    {
        return $this->id($receipt['root_type'] === 'catalogue' ? $receipt['root_id'] : $receipt['parent_id']);
    }
    private function readableHref(Request $r, int $id): ?string
    {
        try {
            $readable = $this->access->withCurrentViewer($r->user(), function ($current, $reads) use ($id): bool {
                if (! $current->canDo('hazards.view')) { return false; }
                $parent = $this->access->assertCatalogue($id, $current, $reads, 'view', true);
                $this->commands->currentVersion($parent, $parent, $reads);
                return true;
            });
        } catch (ModelNotFoundException) { return null; }
        catch (HttpExceptionInterface $denied) { if (in_array($denied->getStatusCode(), [403, 404], true)) { return null; } throw $denied; }
        return $readable ? $this->record($id, $r->input('return_to')) : null;
    }
    private function present(Request $r, string $component, array $props)
    {
        $json = ! $r->header('X-Inertia') && $r->expectsJson();
        return $this->headers($json ? response()->json($props) : Inertia::render($component, $props)->toResponse($r));
    }
    private function headers($response)
    {
        $response->headers->set('Cache-Control', 'private, no-store, no-cache, must-revalidate');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        return $response;
    }
    private function id(mixed $value): int
    {
        abort_unless((is_int($value) || is_string($value)) && preg_match('/^[1-9]\d*$/D', (string) $value)
            && strlen((string) $value) <= 16 && (float) $value <= 9007199254740991, 404);
        return (int) $value;
    }
    private function filters(Request $r): array
    {
        return $r->validate(['tab' => ['sometimes', 'in:all,active,controlled,sds_expiring,sds_missing,inactive'],
            'q' => ['sometimes', 'nullable', 'string', 'max:200'], 'site_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'physical_form' => ['sometimes', 'nullable', 'in:solid,liquid,gas,powder,aerosol,paste,other'],
            'status' => ['sometimes', 'nullable', 'in:active,inactive,removed'], 'controlled' => ['sometimes', 'nullable', 'in:controlled,standard'],
            'sds_state' => ['sometimes', 'nullable', 'in:current,expiring,expired,missing,unknown,ambiguous,unavailable,not_yet_effective,repair_required'],
            'period' => ['sometimes', 'integer', 'min:7', 'max:365'], 'page' => ['sometimes', 'integer', 'min:1', 'max:2147483647']]);
    }
    private function register(array $filters): string
    {
        $query = array_intersect_key($filters, array_flip(self::FILTER_KEYS));
        return self::REGISTER.($query ? '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
    }
    private function record(int $id, mixed $back): string
    {
        $return = $this->back($back);
        return self::REGISTER.'/'.$id.($return === null ? '' : '?return_to='.rawurlencode($return));
    }
    private function back(mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) > 1500 || preg_match('/[\x00-\x20\x7f]/', $value)
            || str_contains($value, '\\') || str_contains($value, '#') || ! str_starts_with($value, '/') || str_starts_with($value, '//')) { return null; }
        $parts = parse_url($value);
        if (! is_array($parts) || isset($parts['host']) || isset($parts['scheme'])) { return null; }
        $path = $parts['path'] ?? '';
        if ($path === \App\Support\HealthSafety\ChemicalExposureReturn::BASE
            || preg_match('~^/health-safety/chemical-exposures/([1-9]\d*)$~D', $path)) {
            return \App\Support\HealthSafety\ChemicalExposureReturn::sanitize($value);
        }
        if ($path !== self::REGISTER) { return InjuryReturn::leaf($value); }
        $query = [];
        foreach (explode('&', $parts['query'] ?? '') as $pair) {
            [$key, $item] = array_pad(explode('=', $pair, 2), 2, '');
            $key = urldecode($key);
            $query[$key] = array_key_exists($key, $query) ? null : urldecode($item);
        }
        $safe = [];
        foreach (self::FILTER_KEYS as $key) {
            $item = $query[$key] ?? null;
            if (! is_string($item) || strlen($item) > 200 || preg_match('/[\x00-\x1f\x7f]/', $item)) { continue; }
            $allowed = match ($key) {
                'tab' => ['all', 'active', 'controlled', 'sds_expiring', 'sds_missing', 'inactive'],
                'physical_form' => ['solid', 'liquid', 'gas', 'powder', 'aerosol', 'paste', 'other'],
                'status' => ['active', 'inactive', 'removed'], 'controlled' => ['controlled', 'standard'],
                'sds_state' => ['current', 'expiring', 'expired', 'missing', 'unknown', 'ambiguous', 'unavailable', 'not_yet_effective', 'repair_required'],
                default => null,
            };
            if ($allowed !== null && ! in_array($item, $allowed, true)) { continue; }
            if (in_array($key, ['site_id', 'page', 'period'], true) && (! preg_match('/^[1-9]\d*$/D', $item)
                || strlen($item) > 10 || (int) $item > 2147483647)) { continue; }
            if ($key === 'period' && ((int) $item < 7 || (int) $item > 365)) { continue; }
            $safe[$key] = $item;
        }
        return $this->register($safe);
    }
}
