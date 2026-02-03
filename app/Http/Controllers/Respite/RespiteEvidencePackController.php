<?php

namespace App\Http\Controllers\Respite;

use App\Http\Controllers\Controller;
use App\Models\RespiteEvidencePack;
use App\Models\RespiteStay;
use App\Models\RespiteAuditLog;
use App\Events\Respite\RespiteEvent;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class RespiteEvidencePackController extends Controller
{
    public function index(Request $request): Response
    {
        $packs = RespiteEvidencePack::query()
            ->with(['stay.client', 'sealedBy'])
            ->when($request->stay_id, fn ($q, $stayId) => $q->where('stay_id', $stayId))
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when($request->sealed, fn ($q) => $q->whereNotNull('sealed_at'))
            ->when($request->unsealed, fn ($q) => $q->whereNull('sealed_at'))
            ->orderByDesc('created_at')
            ->paginate(20);

        return Inertia::render('respite/evidence-packs/index', [
            'packs' => $packs,
            'filters' => $request->only(['stay_id', 'status', 'sealed', 'unsealed']),
        ]);
    }

    public function create(Request $request): Response
    {
        $stays = RespiteStay::query()
            ->with('client')
            ->whereDoesntHave('evidencePack')
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('respite/evidence-packs/create', [
            'stays' => $stays,
            'stayId' => $request->stay_id,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'stay_id' => 'required|exists:respite_stays,id|unique:respite_evidence_packs,stay_id',
            'summary' => 'nullable|string|max:5000',
        ]);

        $validated['status'] = 'draft';
        $validated['items'] = [];
        $validated['created_by'] = auth()->id();

        $pack = RespiteEvidencePack::create($validated);

        RespiteAuditLog::log(
            $pack,
            RespiteAuditLog::ACTION_CREATED,
            auth()->id(),
            null,
            $validated,
            null,
            RespiteAuditLog::CATEGORY_EVIDENCE
        );

        event(new RespiteEvent('respite.evidence_pack.created', [
            'id' => $pack->id,
            'stay_id' => $pack->stay_id,
        ]));

        return redirect()
            ->route('respite.evidence-packs.show', $pack)
            ->with('success', 'Evidence pack created.');
    }

    public function show(RespiteEvidencePack $evidencePack): Response
    {
        $evidencePack->load(['stay.client', 'sealedBy']);

        RespiteAuditLog::log(
            $evidencePack,
            RespiteAuditLog::ACTION_VIEWED,
            auth()->id(),
            null,
            null,
            null,
            RespiteAuditLog::CATEGORY_EVIDENCE
        );

        return Inertia::render('respite/evidence-packs/show', [
            'pack' => $evidencePack,
        ]);
    }

    public function update(Request $request, RespiteEvidencePack $evidencePack): RedirectResponse
    {
        if ($evidencePack->sealed_at) {
            return back()->with('error', 'Cannot modify a sealed evidence pack.');
        }

        $oldValues = $evidencePack->only(['summary', 'status']);

        $validated = $request->validate([
            'summary' => 'nullable|string|max:5000',
            'status' => 'sometimes|in:draft,pending_review,complete',
        ]);

        $validated['updated_by'] = auth()->id();
        $evidencePack->update($validated);

        RespiteAuditLog::log(
            $evidencePack,
            RespiteAuditLog::ACTION_UPDATED,
            auth()->id(),
            $oldValues,
            $validated,
            null,
            RespiteAuditLog::CATEGORY_EVIDENCE
        );

        return back()->with('success', 'Evidence pack updated.');
    }

    public function addItem(Request $request, RespiteEvidencePack $evidencePack): RedirectResponse
    {
        if ($evidencePack->sealed_at) {
            return back()->with('error', 'Cannot add items to a sealed evidence pack.');
        }

        $validated = $request->validate([
            'type' => 'required|in:document,photo,form,note,checklist,signature,other',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'file_path' => 'nullable|string|max:500',
            'metadata' => 'nullable|array',
        ]);

        $items = $evidencePack->items ?? [];
        $items[] = [
            'id' => uniqid('item_'),
            'type' => $validated['type'],
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'file_path' => $validated['file_path'] ?? null,
            'metadata' => $validated['metadata'] ?? null,
            'added_at' => now()->toIso8601String(),
            'added_by' => auth()->id(),
        ];

        $evidencePack->update([
            'items' => $items,
            'updated_by' => auth()->id(),
        ]);

        RespiteAuditLog::log(
            $evidencePack,
            'item_added',
            auth()->id(),
            null,
            ['item_title' => $validated['title'], 'item_type' => $validated['type']],
            null,
            RespiteAuditLog::CATEGORY_EVIDENCE
        );

        return back()->with('success', 'Item added to evidence pack.');
    }

    public function removeItem(Request $request, RespiteEvidencePack $evidencePack): RedirectResponse
    {
        if ($evidencePack->sealed_at) {
            return back()->with('error', 'Cannot remove items from a sealed evidence pack.');
        }

        $validated = $request->validate([
            'item_id' => 'required|string',
            'reason' => 'required|string|max:500',
        ]);

        $items = $evidencePack->items ?? [];
        $removedItem = null;

        $items = array_filter($items, function ($item) use ($validated, &$removedItem) {
            if ($item['id'] === $validated['item_id']) {
                $removedItem = $item;
                return false;
            }
            return true;
        });

        $evidencePack->update([
            'items' => array_values($items),
            'updated_by' => auth()->id(),
        ]);

        RespiteAuditLog::log(
            $evidencePack,
            'item_removed',
            auth()->id(),
            ['removed_item' => $removedItem],
            null,
            $validated['reason'],
            RespiteAuditLog::CATEGORY_EVIDENCE
        );

        return back()->with('success', 'Item removed from evidence pack.');
    }

    public function seal(Request $request, RespiteEvidencePack $evidencePack): RedirectResponse
    {
        if ($evidencePack->sealed_at) {
            return back()->with('error', 'Evidence pack is already sealed.');
        }

        $validated = $request->validate([
            'seal_reason' => 'required|string|max:500',
        ]);

        $evidencePack->update([
            'status' => 'sealed',
            'sealed_at' => now(),
            'sealed_by_user_id' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        RespiteAuditLog::log(
            $evidencePack,
            'sealed',
            auth()->id(),
            null,
            ['sealed_at' => now()->toIso8601String()],
            $validated['seal_reason'],
            RespiteAuditLog::CATEGORY_EVIDENCE
        );

        event(new RespiteEvent('respite.evidence_pack.sealed', [
            'id' => $evidencePack->id,
            'stay_id' => $evidencePack->stay_id,
            'sealed_by' => auth()->id(),
        ]));

        return back()->with('success', 'Evidence pack sealed.');
    }

    public function forStay(RespiteStay $stay): Response
    {
        $pack = RespiteEvidencePack::where('stay_id', $stay->id)
            ->with('sealedBy')
            ->first();

        return Inertia::render('respite/evidence-packs/for-stay', [
            'stay' => $stay->load('client'),
            'pack' => $pack,
        ]);
    }

    public function export(RespiteEvidencePack $evidencePack)
    {
        RespiteAuditLog::log(
            $evidencePack,
            RespiteAuditLog::ACTION_EXPORTED,
            auth()->id(),
            null,
            ['export_format' => 'pdf'],
            null,
            RespiteAuditLog::CATEGORY_EVIDENCE
        );

        // TODO: Implement PDF export
        return back()->with('info', 'Export functionality coming soon.');
    }
}
