<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\HouseLedgerEntry;
use App\Models\Site;
use App\Services\Sites\HouseLedgerPresenter;
use App\Services\Sites\HouseLedgerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class HouseLedgerController extends Controller
{
    public function __construct(private HouseLedgerService $ledgerService) {}

    public function index(Request $request, Site $site)
    {
        $this->authorizeLedgerAccess($request, $site, 'sites.ledger.view');

        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $ledger = $this->ledgerService->getOrCreateLedger($site);
        $perPage = min(max((int) $request->integer('per_page', 10), 1), 50);

        $entries = $ledger->entries()
            ->with(['recordedBy:id,name', 'approvedBy:id,name'])
            ->when($request->filled('from'), fn ($query) => $query->where('entry_date', '>=', $request->date('from')->toDateString()))
            ->when($request->filled('to'), fn ($query) => $query->where('entry_date', '<=', $request->date('to')->toDateString()))
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        $payload = HouseLedgerPresenter::payload($site, $ledger, $entries, $request->user(), [
            'from' => $request->query('from'),
            'to' => $request->query('to'),
        ]);

        if ($request->expectsJson()) {
            return response()->json($payload);
        }

        return Inertia::render('sites/ledger/index', $payload);
    }

    public function store(Request $request, Site $site)
    {
        $this->authorizeLedgerAccess($request, $site, 'sites.ledger.create');

        $data = $request->validate([
            'entry_type' => ['required', 'string', 'in:income,expense,adjustment,transfer'],
            'category' => ['required', 'string', 'max:50'],
            'description' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'entry_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
            'attachment' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,gif,webp'],
        ]);

        // Handle file upload
        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $path = $file->storeAs(
                "house-ledger/{$site->id}",
                time().'_'.preg_replace('/\s+/', '_', $file->getClientOriginalName()),
                'private'
            );
            $data['attachments'] = [[
                'path' => $path,
                'disk' => 'private',
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'size' => $file->getSize(),
            ]];
        }
        unset($data['attachment']);

        $entry = $this->ledgerService->addEntry($site, $data, $request->user()->id);
        $ledger = $entry->ledger;

        if ($request->expectsJson()) {
            $entry->load(['recordedBy:id,name', 'approvedBy:id,name']);

            return response()->json([
                'ledger' => HouseLedgerPresenter::ledger($ledger->refresh()),
                'entry' => HouseLedgerPresenter::entry($entry),
            ], 201);
        }

        return redirect()->back()->with('success', 'Ledger entry recorded.');
    }

    public function downloadAttachment(Request $request, Site $site, HouseLedgerEntry $entry)
    {
        $this->authorizeLedgerAccess($request, $site, 'sites.ledger.view');

        // Verify entry belongs to this site's ledger
        $ledger = $this->ledgerService->getOrCreateLedger($site);
        abort_unless($entry->house_ledger_id === $ledger->id, 404);

        $attachments = $entry->attachments;
        if (empty($attachments) || ! isset($attachments[0])) {
            abort(404, 'No attachment found.');
        }

        $attachment = $attachments[0];
        $disk = $attachment['disk'] ?? 'private';

        abort_unless(
            Storage::disk($disk)->exists($attachment['path']),
            404,
            'Attachment file is missing from storage.'
        );

        return Storage::disk($disk)->download(
            $attachment['path'],
            $attachment['original_name'] ?? basename($attachment['path'])
        );
    }

    public function reconcile(Request $request, Site $site)
    {
        $this->authorizeLedgerAccess($request, $site, 'sites.ledger.manage');

        $ledger = $this->ledgerService->reconcile($site, $request->user()->id);

        if ($request->expectsJson()) {
            return response()->json([
                'ledger' => HouseLedgerPresenter::ledger($ledger->refresh()),
            ]);
        }

        return redirect()->back()->with('success', 'Ledger reconciled.');
    }

    private function authorizeLedgerAccess(Request $request, Site $site, string $permission): void
    {
        if (! in_array($site->type, ['house', 'residential'], true)) {
            abort(404, 'Ledger is only available for house/residential sites.');
        }

        $this->authorize('view', $site);

        if (! $request->user()?->canDo($permission)) {
            abort(403);
        }
    }
}
