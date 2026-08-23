<?php

namespace App\Http\Controllers\Respite;

use App\Domain\Governance\Models\NotifiableIncident;
use App\Events\Respite\RespiteEvent;
use App\Http\Controllers\Controller;
use App\Models\ClientMedication;
use App\Models\MedicationAllergy;
use App\Models\RespiteAuditLog;
use App\Models\RespiteEvidencePack;
use App\Models\RespiteStay;
use App\Services\Respite\RespiteStayScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RespiteEvidencePackController extends Controller
{
    private const INTEGRITY_ERROR = 'This sealed evidence pack failed integrity verification and cannot be used.';

    public function __construct(
        private readonly RespiteStayScope $stayScope,
    ) {}

    public function index(Request $request): Response
    {
        $packs = RespiteEvidencePack::query()
            ->with(['stay.client', 'sealedBy'])
            ->whereHas('stay', fn ($stays) => $this->stayScope->applyAccessibleStayScope($stays, $request))
            ->when($request->stay_id, fn ($q, $stayId) => $q->where('stay_id', $stayId))
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when($request->sealed, fn ($q) => $q->whereNotNull('sealed_at'))
            ->when($request->unsealed, fn ($q) => $q->whereNull('sealed_at'))
            ->orderByDesc('created_at')
            ->paginate(20);

        $packs->getCollection()->each(function (RespiteEvidencePack $pack) use ($request): void {
            [$pack, $stay] = $this->authorizedPack($request, $pack);
            if (! $this->assertValidSealIfPresent($stay, $pack)) {
                $this->assertPackBindings($stay, $pack, null);
            }
        });

        return Inertia::render('respite/evidence-packs/index', [
            'packs' => $packs,
            'filters' => $request->only(['stay_id', 'status', 'sealed', 'unsealed']),
        ]);
    }

    public function create(Request $request): Response
    {
        $stays = RespiteStay::query()
            ->with('client')
            ->tap(fn ($query) => $this->stayScope->applyAccessibleStayScope($query, $request))
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
            'stay_id' => 'required|integer',
            'summary' => 'nullable|string|max:5000',
        ]);

        $pack = DB::transaction(function () use ($request, $validated): RespiteEvidencePack {
            $stay = $this->stayScope->resolveAuthorizedStay($request, (int) $validated['stay_id'], true);

            if (RespiteEvidencePack::query()->where('stay_id', $stay->id)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages([
                    'stay_id' => 'An evidence pack already exists for this respite stay.',
                ]);
            }

            $candidate = new RespiteEvidencePack([
                'stay_id' => $stay->id,
                'booking_id' => $stay->booking_id,
            ]);
            $this->stayScope->assertEvidenceGraph($stay, $candidate, 'stay_id', true, true);

            $attributes = [
                'stay_id' => $stay->id,
                'booking_id' => $stay->booking_id,
                'summary' => $validated['summary'] ?? null,
                'status' => 'draft',
                'items' => $this->buildManifest($stay),
                'created_by' => $request->user()?->id,
            ];
            $pack = RespiteEvidencePack::create($attributes);

            RespiteAuditLog::log(
                $pack,
                RespiteAuditLog::ACTION_CREATED,
                $request->user()?->id,
                null,
                $attributes,
                null,
                RespiteAuditLog::CATEGORY_EVIDENCE
            );

            return $pack;
        }, 3);

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
        [$evidencePack, $stay] = $this->authorizedPack(request(), $evidencePack);
        if (! $this->assertValidSealIfPresent($stay, $evidencePack)) {
            $this->assertPackBindings($stay, $evidencePack, null);
        }
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
        $validated = $request->validate([
            'summary' => 'nullable|string|max:5000',
            'status' => 'sometimes|in:draft,pending_review,complete',
        ]);

        $updated = DB::transaction(function () use ($request, $evidencePack, $validated): bool {
            [$evidencePack, $stay] = $this->authorizedPack($request, $evidencePack, true);
            if ($this->assertValidSealIfPresent($stay, $evidencePack)) {
                return false;
            }

            $this->assertPackBindings($stay, $evidencePack, 'manifest', true);

            $oldValues = $evidencePack->only(['summary', 'status']);
            $attributes = $validated;
            $attributes['updated_by'] = $request->user()?->id;
            $evidencePack->update($attributes);

            RespiteAuditLog::log(
                $evidencePack,
                RespiteAuditLog::ACTION_UPDATED,
                $request->user()?->id,
                $oldValues,
                $attributes,
                null,
                RespiteAuditLog::CATEGORY_EVIDENCE
            );

            return true;
        }, 3);

        if (! $updated) {
            return back()->with('error', 'Cannot modify a sealed evidence pack.');
        }

        return back()->with('success', 'Evidence pack updated.');
    }

    public function addItem(Request $request, RespiteEvidencePack $evidencePack): RedirectResponse
    {
        $validated = $request->validate([
            'type' => 'required|in:document,photo,form,note,checklist,signature,other',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'file_path' => 'nullable|string|max:500',
            'metadata' => 'nullable|array',
            'metadata.incident_id' => 'nullable|integer',
            'metadata.linked_incident_id' => 'nullable|integer',
            'metadata.related_incident_id' => 'nullable|integer',
            'metadata.daily_note_id' => 'nullable|integer',
            'metadata.note_id' => 'nullable|integer',
            'metadata.restraint_event_id' => 'nullable|integer',
            'metadata.behaviour_support_plan_id' => 'nullable|integer',
        ]);

        $added = DB::transaction(function () use ($request, $evidencePack, $validated): bool {
            [$evidencePack, $stay] = $this->authorizedPack($request, $evidencePack, true);
            if ($this->assertValidSealIfPresent($stay, $evidencePack)) {
                return false;
            }

            $this->assertPackBindings($stay, $evidencePack, 'metadata', true);

            $this->assertPackBindings($stay, $evidencePack, 'metadata', true, true);

            $items = $evidencePack->items ?? [];
            $items[] = [
                'id' => uniqid('item_'),
                'type' => $validated['type'],
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'file_path' => $validated['file_path'] ?? null,
                'metadata' => $this->stayScope->normalizeEvidenceMetadata(
                    $stay,
                    $validated['metadata'] ?? null,
                    null,
                    true,
                ),
                'added_at' => now()->toIso8601String(),
                'added_by' => $request->user()?->id,
            ];

            $evidencePack->update([
                'items' => $items,
                'updated_by' => $request->user()?->id,
            ]);

            RespiteAuditLog::log(
                $evidencePack,
                'item_added',
                $request->user()?->id,
                null,
                ['item_title' => $validated['title'], 'item_type' => $validated['type']],
                null,
                RespiteAuditLog::CATEGORY_EVIDENCE
            );

            return true;
        }, 3);

        if (! $added) {
            return back()->with('error', 'Cannot add items to a sealed evidence pack.');
        }

        return back()->with('success', 'Item added to evidence pack.');
    }

    public function removeItem(Request $request, RespiteEvidencePack $evidencePack): RedirectResponse
    {
        $validated = $request->validate([
            'item_id' => 'required|string',
            'reason' => 'required|string|max:500',
        ]);

        $removed = DB::transaction(function () use ($request, $evidencePack, $validated): bool {
            [$evidencePack, $stay] = $this->authorizedPack($request, $evidencePack, true);
            if ($this->assertValidSealIfPresent($stay, $evidencePack)) {
                return false;
            }

            $this->assertPackBindings($stay, $evidencePack, 'item_id', true);

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
                'updated_by' => $request->user()?->id,
            ]);

            RespiteAuditLog::log(
                $evidencePack,
                'item_removed',
                $request->user()?->id,
                ['removed_item' => $removedItem],
                null,
                $validated['reason'],
                RespiteAuditLog::CATEGORY_EVIDENCE
            );

            return true;
        }, 3);

        if (! $removed) {
            return back()->with('error', 'Cannot remove items from a sealed evidence pack.');
        }

        return back()->with('success', 'Item removed from evidence pack.');
    }

    public function seal(Request $request, RespiteEvidencePack $evidencePack): RedirectResponse
    {
        $validated = $request->validate([
            'seal_reason' => 'required|string|max:500',
        ]);

        $sealedPack = DB::transaction(function () use ($request, $evidencePack, $validated): ?RespiteEvidencePack {
            [$evidencePack, $stay] = $this->authorizedPack($request, $evidencePack, true);
            if ($this->assertValidSealIfPresent($stay, $evidencePack)) {
                return null;
            }

            $this->assertPackBindings($stay, $evidencePack, 'manifest', true);

            $this->assertPackBindings($stay, $evidencePack, 'manifest', true, true);

            $manifest = $this->buildManifestForPack($evidencePack, $stay, 'manifest', true, true);
            if (! RespiteEvidencePack::hasDeterministicManifestStructure($manifest)) {
                throw ValidationException::withMessages([
                    'manifest' => 'Evidence pack items must have unique, non-empty identifiers before sealing.',
                ]);
            }

            $incomplete = collect($manifest)->where('required', true)->where('complete', false)->values();
            if ($incomplete->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'manifest' => 'Complete required evidence before sealing: '.$incomplete->pluck('title')->join(', '),
                ]);
            }

            $sealedAt = now();
            $sealedManifestDigest = RespiteEvidencePack::sealedContentDigestFor(
                $evidencePack->sealedContentProjection(
                    $this->stayScope->siteId($stay),
                    (int) $stay->client_id,
                    $manifest,
                    $sealedAt,
                    'sealed',
                ),
            );
            $evidencePack->update([
                'status' => 'sealed',
                'items' => $manifest,
                'sealed_at' => $sealedAt,
                'sealed_by_user_id' => $request->user()?->id,
                'sealed_manifest_version' => RespiteEvidencePack::SEALED_MANIFEST_VERSION,
                'sealed_manifest_digest' => $sealedManifestDigest,
                'updated_by' => $request->user()?->id,
            ]);

            RespiteAuditLog::log(
                $evidencePack,
                'sealed',
                $request->user()?->id,
                null,
                [
                    'sealed_at' => $sealedAt->toIso8601String(),
                    'sealed_manifest_version' => RespiteEvidencePack::SEALED_MANIFEST_VERSION,
                    'sealed_manifest_digest' => $sealedManifestDigest,
                ],
                $validated['seal_reason'],
                RespiteAuditLog::CATEGORY_EVIDENCE
            );

            return $evidencePack;
        }, 3);

        if (! $sealedPack) {
            return back()->with('error', 'Evidence pack is already sealed.');
        }

        event(new RespiteEvent('respite.evidence_pack.sealed', [
            'id' => $sealedPack->id,
            'stay_id' => $sealedPack->stay_id,
            'sealed_by' => auth()->id(),
        ]));

        return back()->with('success', 'Evidence pack sealed.');
    }

    public function forStay(RespiteStay $stay): Response
    {
        $stay = $this->stayScope->resolveAuthorizedStay(request(), (int) $stay->id);
        $pack = RespiteEvidencePack::where('stay_id', $stay->id)
            ->where('booking_id', $stay->booking_id)
            ->with('sealedBy')
            ->first();

        if ($pack) {
            if (! $this->assertValidSealIfPresent($stay, $pack)) {
                $this->assertPackBindings($stay, $pack, null);
            }
        }

        return Inertia::render('respite/evidence-packs/for-stay', [
            'stay' => $stay->load('client'),
            'pack' => $pack,
        ]);
    }

    public function export(RespiteEvidencePack $evidencePack)
    {
        [$packId, $encodedPayload] = DB::transaction(function () use ($evidencePack): array {
            [$lockedPack, $stay] = $this->authorizedPack(request(), $evidencePack, true);
            $siteId = $this->stayScope->siteId($stay);

            if ($this->assertValidSealIfPresent($stay, $lockedPack)) {
                $content = $lockedPack->sealedContentProjection($siteId, (int) $stay->client_id);
            } else {
                $this->assertPackBindings($stay, $lockedPack, null);
                $content = $lockedPack->sealedContentProjection(
                    $siteId,
                    (int) $stay->client_id,
                    $this->buildManifestForPack($lockedPack, $stay, null),
                );
            }

            $payload = [
                ...$content,
                'sealed_manifest_version' => $lockedPack->sealed_manifest_version,
                'sealed_manifest_digest' => $lockedPack->sealed_manifest_digest,
            ];
            $encodedPayload = json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            );

            RespiteAuditLog::log(
                $lockedPack,
                RespiteAuditLog::ACTION_EXPORTED,
                auth()->id(),
                null,
                [
                    'export_format' => 'json',
                    'sealed_manifest_version' => $lockedPack->sealed_manifest_version,
                    'sealed_manifest_digest' => $lockedPack->sealed_manifest_digest,
                ],
                null,
                RespiteAuditLog::CATEGORY_EVIDENCE
            );

            return [$lockedPack->id, $encodedPayload];
        }, 3);

        return response()->streamDownload(function () use ($encodedPayload) {
            echo $encodedPayload;
        }, "respite-evidence-pack-{$packId}.json", [
            'Content-Type' => 'application/json',
        ]);
    }

    private function buildManifestForPack(
        RespiteEvidencePack $pack,
        ?RespiteStay $stay = null,
        ?string $field = 'metadata',
        bool $lock = false,
        bool $requireCurrentPlans = false,
    ): array {
        $stay ??= $pack->stay;
        abort_unless($stay, 404);
        $stay->loadMissing(['booking.serviceAgreement', 'handovers', 'communications', 'dailyNotes', 'medicationReconciliations', 'restraintEvents', 'incidents', 'complaints']);

        return [
            ...$this->buildManifest($stay),
            ...$this->customItems($pack, $stay, $field, $lock, $requireCurrentPlans),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function customItems(
        RespiteEvidencePack $pack,
        RespiteStay $stay,
        ?string $field,
        bool $lock,
        bool $requireCurrentPlans,
    ): array {
        return collect($pack->items ?? [])
            ->reject(fn (array $item): bool => str_starts_with((string) ($item['id'] ?? ''), 'manifest_'))
            ->map(function (array $item) use ($stay, $field, $lock, $requireCurrentPlans): array {
                $item['metadata'] = $this->stayScope->normalizeEvidenceMetadata(
                    $stay,
                    is_array($item['metadata'] ?? null) ? $item['metadata'] : null,
                    $field,
                    $lock,
                    $requireCurrentPlans,
                );

                return $item;
            })
            ->values()
            ->all();
    }

    /** @return array{0:RespiteEvidencePack,1:RespiteStay} */
    private function authorizedPack(
        Request $request,
        RespiteEvidencePack $routePack,
        bool $lock = false,
    ): array {
        $stay = $this->stayScope->resolveAuthorizedStay($request, (int) $routePack->stay_id, $lock);
        $pack = $this->stayScope->evidencePack($stay, (int) $routePack->id, null, $lock);

        return [$pack, $stay];
    }

    private function assertPackBindings(
        RespiteStay $stay,
        RespiteEvidencePack $pack,
        ?string $field,
        bool $lock = false,
        bool $requireCurrentPlans = false,
    ): void {
        $this->stayScope->assertEvidenceGraph($stay, $pack, $field, $lock, $requireCurrentPlans);
        $this->customItems($pack, $stay, $field, $lock, $requireCurrentPlans);
    }

    private function assertValidSealIfPresent(RespiteStay $stay, RespiteEvidencePack $pack): bool
    {
        if (! $pack->hasSealEvidence()) {
            return false;
        }

        abort_unless(
            $pack->hasValidSealedManifest(
                $this->stayScope->siteId($stay),
                (int) $stay->client_id,
            ),
            409,
            self::INTEGRITY_ERROR,
        );

        return true;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function buildManifest(RespiteStay $stay): array
    {
        $stay->loadMissing(['booking.serviceAgreement', 'handovers', 'communications', 'dailyNotes', 'medicationReconciliations', 'restraintEvents', 'incidents', 'complaints']);
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
        $rightsComplete = $booking !== null
            && $booking->code_of_rights_provided === true
            && $booking->consent_to_respite === true
            && $booking->advocate_offered !== null
            && filled($booking->consent_capacity_basis)
            && filled($booking->rights_format_provided)
            && filled($booking->rights_recorded_at);
        $consentComplete = (bool) $booking?->consent_authority && $agreementSigned && $rightsComplete;
        $lifeThreateningAllergies = MedicationAllergy::query()
            ->where('client_id', $stay->client_id)
            ->where('severity', 'life_threatening')
            ->count();
        $anaphylaxisAcknowledged = filled(data_get($stay->admission_risk_screen, 'anaphylaxis_acknowledgement.recorded_at'));
        $openComplaints = $stay->complaints->whereNotIn('status', ['resolved'])->count();
        $withinPlanRestraints = $stay->restraintEvents->where('within_support_plan', true);
        $bspAcknowledged = $withinPlanRestraints->isEmpty()
            || $withinPlanRestraints->every(fn ($event) => filled($event->behaviour_support_plan_id));

        return [
            [
                'id' => 'manifest_consent_rights',
                'type' => 'consent_rights',
                'title' => 'Consent, PPPR authority and rights evidence',
                'required' => true,
                'complete' => $consentComplete,
                'count' => $consentComplete ? 1 : 0,
                'rights_complete' => $rightsComplete,
            ],
            [
                'id' => 'manifest_anaphylaxis',
                'type' => 'anaphylaxis_acknowledgement',
                'title' => 'Anaphylaxis acknowledgement and escalation plan',
                'required' => $lifeThreateningAllergies > 0,
                'complete' => $lifeThreateningAllergies === 0 || $anaphylaxisAcknowledged,
                'count' => $lifeThreateningAllergies,
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
                'id' => 'manifest_bsp_acknowledgements',
                'type' => 'bsp_acknowledgements',
                'title' => 'Behaviour support plan linkage for restrictive practice',
                'required' => $withinPlanRestraints->isNotEmpty(),
                'complete' => $bspAcknowledged,
                'count' => $withinPlanRestraints->count(),
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
                'id' => 'manifest_complaints',
                'type' => 'complaints',
                'title' => 'Complaints, advocacy and resolution notes',
                'required' => false,
                'complete' => $openComplaints === 0,
                'count' => $stay->complaints->count(),
                'open_count' => $openComplaints,
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
