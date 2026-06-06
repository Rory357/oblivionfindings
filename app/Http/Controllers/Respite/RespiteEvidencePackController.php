<?php

namespace App\Http\Controllers\Respite;

use App\Domain\Governance\Models\NotifiableIncident;
use App\Events\Respite\RespiteEvent;
use App\Http\Controllers\Controller;
use App\Models\ClientMedication;
use App\Models\RespiteAuditLog;
use App\Models\RespiteEvidencePack;
use App\Models\RespiteStay;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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

        $stay = RespiteStay::with(['booking.serviceAgreement', 'handovers', 'communications', 'dailyNotes', 'medicationReconciliations', 'restraintEvents', 'incidents'])
            ->findOrFail($validated['stay_id']);

        $validated['booking_id'] = $stay->booking_id;
        $validated['status'] = 'draft';
        $validated['items'] = $this->buildManifest($stay);
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

        $manifest = $this->buildManifestForPack($evidencePack);
        $incomplete = collect($manifest)->where('required', true)->where('complete', false)->values();

        if ($incomplete->isNotEmpty()) {
            $evidencePack->update([
                'items' => $manifest,
                'updated_by' => auth()->id(),
            ]);

            throw ValidationException::withMessages([
                'manifest' => 'Complete required evidence before sealing: '.$incomplete->pluck('title')->join(', '),
            ]);
        }

        $evidencePack->update([
            'status' => 'sealed',
            'items' => $manifest,
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

    private function buildManifestForPack(RespiteEvidencePack $pack): array
    {
        $pack->loadMissing(['stay.booking.serviceAgreement', 'stay.handovers', 'stay.communications', 'stay.dailyNotes', 'stay.medicationReconciliations', 'stay.restraintEvents', 'stay.incidents']);

        return $this->buildManifest($pack->stay);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function buildManifest(RespiteStay $stay): array
    {
        $stay->loadMissing(['booking.serviceAgreement', 'handovers', 'communications', 'dailyNotes', 'medicationReconciliations', 'restraintEvents', 'incidents']);
        $booking = $stay->booking;
        $incidentIds = $stay->incidents->pluck('id');
        $pendingNotifiables = $incidentIds->isNotEmpty()
            ? NotifiableIncident::whereIn('related_incident_id', $incidentIds)->where('status', 'pending')->count()
            : 0;
        $activeMedicationCount = ClientMedication::query()->where('client_id', $stay->client_id)->active()->count();
        $admissionMedRecDone = $stay->medicationReconciliations
            ->where('type', 'admission')
            ->whereIn('status', ['completed', 'overridden'])
            ->isNotEmpty();
        $agreementSigned = in_array($booking?->agreement_status, ['signed', 'waived'], true)
            || (bool) $booking?->serviceAgreement?->signed_at
            || (bool) $booking?->serviceAgreement?->signed_date;
        $consentComplete = (bool) $booking?->consent_authority && $agreementSigned;

        return [
            [
                'id' => 'manifest_consent_rights',
                'type' => 'consent_rights',
                'title' => 'Consent, PPPR authority and rights evidence',
                'required' => true,
                'complete' => $consentComplete,
                'count' => $consentComplete ? 1 : 0,
            ],
            [
                'id' => 'manifest_restraints',
                'type' => 'restraint_events',
                'title' => 'Restraint events and reviews',
                'required' => true,
                'complete' => $stay->restraintEvents->whereNull('reviewed_at')->isEmpty(),
                'count' => $stay->restraintEvents->count(),
            ],
            [
                'id' => 'manifest_incidents',
                'type' => 'incidents_notifiable',
                'title' => 'Incidents and notifiable-event references',
                'required' => true,
                'complete' => $stay->incidents->whereNotIn('status', ['reviewed', 'closed'])->isEmpty() && $pendingNotifiables === 0,
                'count' => $stay->incidents->count(),
                'pending_notifiable_count' => $pendingNotifiables,
            ],
            [
                'id' => 'manifest_med_rec',
                'type' => 'medication_reconciliation',
                'title' => 'Medication reconciliation',
                'required' => $activeMedicationCount > 0,
                'complete' => $activeMedicationCount === 0 || $admissionMedRecDone,
                'count' => $stay->medicationReconciliations->count(),
            ],
            [
                'id' => 'manifest_handovers',
                'type' => 'handovers_communications',
                'title' => 'Handovers and family/whanau communications',
                'required' => false,
                'complete' => true,
                'count' => $stay->handovers->count() + $stay->communications->count(),
            ],
            [
                'id' => 'manifest_daily_notes',
                'type' => 'daily_wellbeing_notes',
                'title' => 'Daily wellbeing and cultural support notes',
                'required' => false,
                'complete' => true,
                'count' => $stay->dailyNotes->count(),
            ],
        ];
    }
}
