<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ServiceContext;
use App\Models\User;
use App\Models\TimelineEvent;
use App\Services\EnhancedMarService;
use App\Services\MedicationAlertService;
use App\Services\MedicationIncidentIntegrationService;
use App\Services\MarScheduleService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DailyMarController extends Controller
{
    public function show(Request $request, Client $client, MarScheduleService $mar)
    {
        // Viewing MAR aligns with the client's view permission, and assigned-support-worker scoping.
        $this->authorize('view', $client);

        $user = $request->user();
        $canRecord = ($user?->canDo('clients.update') ?? false) || ($user?->canDo('medications.administer.record') ?? false);

        $date = $request->query('date')
            ? \Carbon\Carbon::parse($request->query('date'))->startOfDay()
            : now()->startOfDay();

        $client->load([
            'site:id,name',
            'serviceContext:id,type,name',
            'supportWorkers:id,name,email',
            'medications.stock',
        ]);

        $activeMeds = $client->medications
            ->filter(fn (ClientMedication $m) => (bool) ($m->active ?? true))
            ->values();

        // Pull all administrations for the selected day (for matching).
        $admins = ClientMedicationAdministration::query()
            ->where('client_id', $client->id)
            ->whereBetween('scheduled_for', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
            ->with([
                'medication:id,client_id,name,dosage,frequency,is_prn,controlled_drug',
                'administeredBy:id,name,email',
                'witnessedBy:id,name,email',
                'serviceContext:id,name,type',
            ])
            ->get();

        $byKey = $admins->keyBy(function (ClientMedicationAdministration $a) {
            $ts = optional($a->scheduled_for)->format('Y-m-d H:i') ?? '';
            return $a->client_medication_id . '|' . $ts;
        });

        $rows = [];
        foreach ($activeMeds as $med) {
            $scheduledTimes = $mar->scheduledTimesForDate($med, $date);
            foreach ($scheduledTimes as $scheduledFor) {
                $key = $med->id . '|' . $scheduledFor->format('Y-m-d H:i');
                $existing = $byKey->get($key);

                $adminPayload = null;
                if ($existing) {
                    $lateMinutes = null;
                    if ($existing->scheduled_for && $existing->administered_at) {
                        $diff = $existing->scheduled_for->diffInMinutes($existing->administered_at, false);
                        $lateMinutes = $diff > 0 ? $diff : 0;
                    }
                    $adminPayload = [
                        'id' => $existing->id,
                        'status' => $existing->status,
                        'reason' => $existing->reason,
                        'dose_given' => $existing->dose_given,
                        'scheduled_for' => $existing->scheduled_for,
                        'administered_at' => $existing->administered_at,
                        'notes' => $existing->notes,
                        'created_at' => $existing->created_at,
                        'late_minutes' => $lateMinutes,
                        'administeredBy' => $existing->administeredBy ? $existing->administeredBy->only(['id', 'name', 'email']) : null,
                        'witnessedBy' => $existing->witnessedBy ? $existing->witnessedBy->only(['id', 'name', 'email']) : null,
                        'serviceContext' => $existing->serviceContext ? [
                            'id' => $existing->serviceContext->id,
                            'name' => $existing->serviceContext->name,
                            'type' => (string) ($existing->serviceContext->type?->value ?? $existing->serviceContext->type),
                        ] : null,
                    ];
                }

                $statusMeta = $mar->statusForDose(now(), $scheduledFor, $adminPayload);

                [$wStart, $wEnd] = $mar->windowForScheduled($scheduledFor);

                $rows[] = [
                    'client_medication_id' => $med->id,
                    'medication' => [
                        'id' => $med->id,
                        'name' => $med->name,
                        'dosage' => $med->dosage,
                        'route' => $med->route,
                        'frequency' => $med->frequency,
                        'is_prn' => (bool) $med->is_prn,
                        'controlled_drug' => (bool) $med->controlled_drug,
                        'active' => (bool) $med->active,
                    ],
                    'scheduled_for' => $scheduledFor,
                    'window_start' => $wStart,
                    'window_end' => $wEnd,
                    'state' => $statusMeta['state'],
                    'administration' => $adminPayload,
                ];
            }
        }

        // PRN meds shown separately
        $prn = $activeMeds
            ->filter(fn (ClientMedication $m) => (bool) $m->is_prn)
            ->map(fn (ClientMedication $m) => [
                'id' => $m->id,
                'name' => $m->name,
                'dosage' => $m->dosage,
                'route' => $m->route,
                'frequency' => $m->frequency,
                'prn_reason' => $m->prn_reason,
                'max_per_day' => $m->max_per_day,
                'controlled_drug' => (bool) $m->controlled_drug,
                'active' => (bool) $m->active,
            ])
            ->values();

        // Step 9: audit/history panel (last 30 administrations)
        $history = ClientMedicationAdministration::query()
            ->where('client_id', $client->id)
            ->with([
                'medication:id,client_id,name,dosage,frequency,is_prn,controlled_drug',
                'administeredBy:id,name,email',
                'witnessedBy:id,name,email',
                'serviceContext:id,name,type',
            ])
            ->orderByDesc('administered_at')
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->map(function (ClientMedicationAdministration $a) {
                $lateMinutes = null;
                if ($a->scheduled_for && $a->administered_at) {
                    $diff = $a->scheduled_for->diffInMinutes($a->administered_at, false);
                    $lateMinutes = $diff > 0 ? $diff : 0;
                }
                return [
                    'id' => $a->id,
                    'status' => $a->status,
                    'reason' => $a->reason,
                    'dose_given' => $a->dose_given,
                    'scheduled_for' => $a->scheduled_for,
                    'administered_at' => $a->administered_at,
                    'notes' => $a->notes,
                    'created_at' => $a->created_at,
                    'late_minutes' => $lateMinutes,
                    'medication' => $a->medication,
                    'administeredBy' => $a->administeredBy,
                    'witnessedBy' => $a->witnessedBy,
                    'serviceContext' => $a->serviceContext ? [
                        'id' => $a->serviceContext->id,
                        'name' => $a->serviceContext->name,
                        'type' => (string) ($a->serviceContext->type?->value ?? $a->serviceContext->type),
                    ] : null,
                ];
            })
            ->values();

        $witnesses = User::staff()
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role'])
            ->filter(fn (User $u) => $u->canDo('medications.controlled.witness'))
            ->values()
            ->map(fn (User $u) => $u->only(['id', 'name', 'email']));

        return inertia('operations/clients/mar', [
            'client' => [
                'id' => $client->id,
                'first_name' => $client->first_name,
                'last_name' => $client->last_name,
                'service_context' => $client->serviceContext ? [
                    'id' => $client->serviceContext->id,
                    'type' => $client->serviceContext->type?->value,
                    'name' => $client->serviceContext->name,
                ] : null,
                'site' => $client->site ? ['id' => $client->site->id, 'name' => $client->site->name] : null,
            ],
            'date' => $date->toDateString(),
            'rows' => collect($rows)->sortBy(fn ($r) => $r['scheduled_for'])->values(),
            'prn' => $prn,
            'history' => $history,
            'settings' => [
                'window_before_minutes' => $mar->windowBeforeMinutes(),
                'window_after_minutes' => $mar->windowAfterMinutes(),
                'due_soon_minutes' => $mar->dueSoonMinutes(),
            ],
            'can' => [
                'record' => $canRecord,
                'controlled_record' => ($user?->canDo('clients.update') ?? false) || ($user?->canDo('medications.controlled.record') ?? false),
                'controlled_witness' => ($user?->canDo('clients.update') ?? false) || ($user?->canDo('medications.controlled.witness') ?? false),
            ],
            'witnesses' => $witnesses,
        ]);
    }

    public function record(Request $request, Client $client, ClientMedication $medication, MarScheduleService $mar)
    {
        $this->authorize('view', $client);
        abort_unless($medication->client_id === $client->id, 404);

        $user = $request->user();
        abort_unless(($user?->canDo('clients.update') ?? false) || ($user?->canDo('medications.administer.record') ?? false), 403);

        $data = $request->validate([
            'status' => ['required', 'in:given,refused,missed,withheld'],
            'reason' => ['nullable', 'string', 'max:255'],
            'dose_given' => ['nullable', 'string', 'max:255'],
            'scheduled_for' => ['nullable', 'date'],
            'administered_at' => ['nullable', 'date'],
            'shift_id' => ['nullable', 'integer'],
            'witnessed_by' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string'],
        ]);

        // Require a reason whenever the outcome is not "given".
        if (($data['status'] ?? 'given') !== 'given' && empty($data['reason'])) {
            return back()->withInput()->with('error', 'Please provide a reason when the medication is not given.');
        }

        // For PRN (as-needed) medication, require an indication/reason even when "given".
        if ($medication->is_prn && (($data['status'] ?? 'given') === 'given') && empty($data['reason'])) {
            return back()->withInput()->with('error', 'Please provide the PRN indication (reason) for as-needed medication.');
        }

        // For scheduled doses, enforce time window guidance.
        $scheduledFor = !empty($data['scheduled_for']) ? \Carbon\Carbon::parse($data['scheduled_for']) : null;
        $adminAt = !empty($data['administered_at']) ? \Carbon\Carbon::parse($data['administered_at']) : now();

        if ($scheduledFor && !$medication->is_prn) {
            [$wStart, $wEnd] = $mar->windowForScheduled($scheduledFor);
            $outside = $adminAt->lessThan($wStart) || $adminAt->greaterThan($wEnd);
            if ($outside && empty($data['reason'])) {
                return back()->withInput()->with('error', 'This dose is outside the allowed time window. Please provide a reason to proceed.');
            }
        }

        // Controlled drugs: require permission + witness for ALL actions (given, refused, missed, withheld).
        if ($medication->controlled_drug) {
            abort_unless(($user?->canDo('clients.update') ?? false) || ($user?->canDo('medications.controlled.record') ?? false), 403);
            if (empty($data['witnessed_by'])) {
                return back()->withInput()->with('error', 'A witness is required when administering a controlled drug.');
            }
            if ((int) $data['witnessed_by'] === (int) $user->id) {
                return back()->withInput()->with('error', 'The witness must be a different user.');
            }

            $witness = User::query()->find($data['witnessed_by']);
            if (!$witness || !$witness->canDo('medications.controlled.witness')) {
                return back()->withInput()->with('error', 'Selected witness is not authorised to witness controlled drug actions.');
            }
        }

        $result = app(EnhancedMarService::class)->recordAdministration(
            $client,
            $medication,
            array_merge($data, [
                'scheduled_for' => $scheduledFor?->toIso8601String(),
                'administered_at' => $adminAt->toIso8601String(),
            ]),
            $user->id,
            $data['shift_id'] ?? null
        );

        if (! ($result['success'] ?? false)) {
            if (
                $medication->is_prn
                && ($result['safety_check']['blocked'] ?? false)
                && $medication->fresh()->isPrnBlocked()
            ) {
                $limitIncidentKey = 'emar:prn-over-limit:' . $client->id . ':' . $medication->id . ':' . now()->format('YmdHi');
                if (Cache::add($limitIncidentKey, true, now()->addMinutes(15))) {
                    app(MedicationIncidentIntegrationService::class)
                        ->handlePrnOverLimit($client, $medication->fresh(), $user->id);
                }
            }

            return back()->withInput()->with('error', $result['error'] ?? 'Failed to record administration.');
        }

        /** @var ClientMedicationAdministration $a */
        $a = $result['administration'];

        $statusLabel = ucfirst(str_replace('_', ' ', $data['status']));
        TimelineEvent::create([
            'source_type' => ClientMedicationAdministration::class,
            'source_id' => $a->id,
            'occurred_at' => $a->administered_at ?? now(),
            'type' => 'medication_' . $data['status'],
            'actor_user_id' => $user->id,
            'client_id' => $client->id,
            'shift_id' => $data['shift_id'] ?? null,
            'site_id' => $client->site_id,
            'subject' => $statusLabel . ': ' . $medication->name . ($medication->dosage ? ' ' . $medication->dosage : ''),
            'body' => $data['notes'] ?? null,
            'meta' => array_filter([
                'medication_name' => $medication->name,
                'dosage' => $medication->dosage,
                'dose_given' => $data['dose_given'] ?? null,
                'status' => $data['status'],
                'reason' => $data['reason'] ?? null,
                'witnessed_by' => $data['witnessed_by'] ?? null,
            ]),
            'visibility' => 'internal',
            'is_pinned' => false,
            'created_by' => $user->id,
        ]);

        if ($data['status'] === 'missed') {
            app(MedicationIncidentIntegrationService::class)->handleMissedDose($a, $user->id);
        } elseif ($data['status'] === 'refused' && ($medication->high_risk || $medication->controlled_drug)) {
            app(MedicationIncidentIntegrationService::class)->handleRefusedDose($a);
        }

        if (($a->late_minutes ?? null) && $a->late_minutes > 120) {
            app(MedicationIncidentIntegrationService::class)->handleLateDose($a, $a->late_minutes);
        }

        app(MedicationAlertService::class)->generateClientAlerts($client);

        app(NotificationService::class)->notifyCrud($request->user(), 'created', 'medication administration', $a, $client, [
            'title' => 'Medication administration recorded',
            'url' => url("/clients/{$client->id}/mar?date=" . ($scheduledFor ? $scheduledFor->toDateString() : now()->toDateString())),
        ]);

        return back()->with('success', 'Medication administration recorded.');
    }
}
