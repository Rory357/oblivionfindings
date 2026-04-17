<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\Shift;
use App\Services\EnhancedMarService;
use App\Support\ClientSafetyPayload;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PR 14 — Consolidated frontline client page.
 *
 * `/operations/clients/{client}/care` is the worker-facing entry point for a
 * single client. It is deliberately narrower than the admin show page: the
 * safety ribbon, the day-of-shift context a worker actually looks for, and a
 * single practical PRN launch. Admin detail (portal users, gallery, timeline,
 * funding, service agreements, personal assets, transport, compliance…) stays
 * on `operations.clients.show`.
 *
 * This controller does not replace the admin surface. It reuses
 * `ClientSafetyPayload` for the ribbon, `EnhancedMarService` for PRN recording
 * and the existing `Client` / `ClientMedication` data. No schema changes.
 */
class ClientCareController extends Controller
{
    public function __construct(
        protected EnhancedMarService $marService,
    ) {
    }

    public function show(Request $request, Client $client): Response
    {
        $this->authorize('view', $client);

        $user = $request->user();

        $client->load([
            'medicalProfile',
            'risks',
            'conditions',
            'emergencyContacts',
        ]);

        $activeShift = $this->activeShiftFor($user?->id, $client->id);

        $prnMedications = $this->prnMedicationsFor($client);

        $activeRisks = $client->risks
            ->filter(fn ($r) => (bool) ($r->active ?? false))
            ->sortByDesc(fn ($r) => $this->severityRank($r->severity))
            ->values()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'label' => (string) $r->label,
                'severity' => strtolower((string) $r->severity),
                'controls' => $r->controls,
                'review_date' => $r->review_date?->toDateString(),
            ])
            ->all();

        $conditions = $client->conditions
            ->sortBy('label')
            ->values()
            ->map(fn ($c) => [
                'id' => (int) $c->id,
                'label' => (string) $c->label,
                'severity' => $c->severity,
                'notes' => $c->notes,
            ])
            ->all();

        $contacts = $client->emergencyContacts
            ->sortBy(['contact_order', 'name'])
            ->values()
            ->take(4)
            ->map(fn ($c) => [
                'id' => (int) $c->id,
                'name' => (string) $c->name,
                'relationship' => $c->relationship,
                'phone' => $c->phone,
            ])
            ->all();

        $profile = $client->medicalProfile;

        return Inertia::render('operations/clients/care', [
            'client' => [
                'id' => $client->id,
                'first_name' => $client->first_name,
                'last_name' => $client->last_name,
                'preferred_name' => $client->preferred_name ?? null,
                'pronouns' => $client->pronouns ?? null,
                'photo_url' => $client->photo_url ?? null,
                'date_of_birth' => $client->date_of_birth?->toDateString(),
            ],
            'safety' => ClientSafetyPayload::forClient($client),
            'medical_notes' => $profile?->notes,
            'conditions' => $conditions,
            'active_risks' => $activeRisks,
            'emergency_contacts' => $contacts,
            'prn_medications' => $prnMedications,
            'active_shift' => $activeShift ? [
                'id' => $activeShift->id,
                'starts_at' => optional($activeShift->actual_starts_at ?? $activeShift->starts_at)?->toIso8601String(),
            ] : null,
            'can' => [
                'record_prn' => (bool) ($user?->canDo('medications.administer.record')
                    || $user?->canDo('clients.update')),
                'view_medical' => $user?->can('viewMedications', $client) ?? false,
                'view_risks' => true,
            ],
            'links' => [
                'full_profile' => route('operations.clients.show', $client),
                'medical' => route('operations.clients.medical.show', $client),
                'risks' => route('operations.clients.risks.index', $client),
                'mar' => route('operations.clients.mar.show', $client),
            ],
        ]);
    }

    /**
     * Client-scoped PRN recording. Reuses `EnhancedMarService` so safety
     * checks, over-limit handling and audit run identically to the
     * `/meds/today/prn` path — this is a second *launch point*, not a second
     * administration pathway.
     *
     * Null-shift context: when the worker doesn't currently have an active
     * (clocked-in) shift for this client, the admin note is prefixed
     * explicitly so reporting can tell the two apart. We never invent a
     * shift association.
     */
    public function recordPrn(Request $request, Client $client): RedirectResponse
    {
        $this->authorize('view', $client);

        $user = $request->user();
        abort_unless($user, 403);

        abort_unless(
            $user->canDo('medications.administer.record') || $user->canDo('clients.update'),
            403,
            'You do not have permission to record medication administrations.'
        );

        $data = $request->validate([
            'client_medication_id' => ['required', 'integer', 'exists:client_medications,id'],
            'reason' => ['required', 'string', 'max:500'],
            'dose_given' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $medication = ClientMedication::with('client')->findOrFail($data['client_medication_id']);

        abort_unless($medication->client_id === $client->id, 404);
        abort_unless($medication->is_prn, 422, 'This medication is not configured as an as-needed (PRN) med.');
        abort_unless($medication->active, 422, 'This medication is not currently active.');

        $activeShift = $this->activeShiftFor($user->id, $client->id);
        $shiftId = $activeShift?->id;

        // Make null-shift linkage explicit rather than silent. The worker's
        // message still wins; we just append a short contextual tag so an
        // auditor reading the administration row later can see that this PRN
        // was captured outside an active shift for this worker + client.
        //
        // Extension point (witness-required PRN): the prn-sheet already
        // surfaces a "Witness needed" hint for controlled/high-risk meds.
        // A future PR can intercept this handler and capture a witness
        // signature or passcode before delegating to the service — the
        // client-scoped route above gives that flow a place to live.
        //
        // Extension point (effect-check follow-up): `administered_at` +
        // `client_medication_id` is enough to enqueue a follow-up job once a
        // later PR owns that workflow. We deliberately don't schedule one
        // here.
        $baseNotes = trim((string) ($data['notes'] ?? ''));

        $notes = $baseNotes;
        if ($shiftId === null) {
            $marker = '[PRN from client page — no active shift]';
            $notes = $baseNotes === ''
                ? $marker
                : $marker . "\n" . $baseNotes;
        }

        $result = $this->marService->recordAdministration(
            $client,
            $medication,
            [
                'status' => 'given',
                'reason' => trim($data['reason']),
                'dose_given' => $data['dose_given'] ?? null,
                'notes' => $notes,
                'administered_at' => now()->toIso8601String(),
            ],
            $user->id,
            $shiftId,
        );

        if (! ($result['success'] ?? false)) {
            return back()->withErrors([
                'reason' => $result['error'] ?? 'Could not record this PRN dose.',
            ]);
        }

        $clientName = trim(($client->first_name ?? '') . ' ' . ($client->last_name ?? ''));

        return back()->with(
            'success',
            'Saved — ' . $medication->name . ' recorded for ' . $clientName,
        );
    }

    /**
     * PRN meds configured for this client, shaped for the PRN sheet. Same
     * payload shape as `WorkerMedsController::prnMedications` so the shared
     * component stays single-purpose.
     */
    private function prnMedicationsFor(Client $client): array
    {
        try {
            $medications = ClientMedication::where('client_id', $client->id)
                ->active()
                ->prn()
                ->orderBy('name')
                ->get();
        } catch (\Throwable) {
            return [];
        }

        $clientName = trim(($client->first_name ?? '') . ' ' . ($client->last_name ?? ''));

        return $medications->map(function (ClientMedication $med) use ($clientName) {
            $maxPerDay = $med->max_per_day ? (int) $med->max_per_day : null;
            $givenLast24h = $med->prnCountLast24Hours;
            $remaining = $maxPerDay !== null ? max(0, $maxPerDay - $givenLast24h) : null;

            return [
                'id' => $med->id,
                'client_id' => $med->client_id,
                'client_name' => $clientName,
                'name' => $med->name,
                'dose' => $med->dosage,
                'route' => $med->route,
                'form' => $med->form,
                'instructions' => $med->instructions,
                'prn_reason' => $med->prn_reason,
                'max_per_day' => $maxPerDay,
                'given_last_24h' => $givenLast24h,
                'remaining_today' => $remaining,
                'near_limit' => $med->isPrnNearLimit(),
                'over_limit' => $med->isPrnOverLimit(),
                'is_controlled' => (bool) ($med->controlled_drug ?? false),
                'requires_witness' => (bool) ($med->witness_required ?? false)
                    || (bool) ($med->controlled_drug ?? false),
            ];
        })->all();
    }

    /**
     * The worker's current clocked-in shift for this client, if any. Null is
     * a first-class state — a medication lead or covering worker may record a
     * PRN for a client they aren't rostered on right now.
     */
    private function activeShiftFor(?int $userId, int $clientId): ?Shift
    {
        if ($userId === null) {
            return null;
        }

        try {
            return Shift::query()
                ->where('user_id', $userId)
                ->where('client_id', $clientId)
                ->whereNotNull('actual_starts_at')
                ->whereNull('actual_ends_at')
                ->latest('actual_starts_at')
                ->first();
        } catch (\Throwable) {
            return null;
        }
    }

    private function severityRank(?string $severity): int
    {
        return match (strtolower((string) $severity)) {
            'critical' => 4,
            'high' => 3,
            'medium' => 2,
            'low' => 1,
            default => 0,
        };
    }
}
