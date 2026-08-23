<?php

namespace App\Http\Controllers\Emar;

use App\Enums\Medication\NotGivenReason;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationDestruction;
use App\Models\MedicationError;
use App\Models\MedicationOrderVersion;
use App\Models\MedicationPharmacyOrder;
use App\Models\MedicationPrescriberOrder;
use App\Models\MedicationReview;
use App\Models\Site;
use App\Services\Emar\MarOmissionService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request, MarOmissionService $omissions)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('medications.audit.view'), 403);

        $clientId = $request->query('client_id');
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');
        $eventTypes = $request->query('event_types', []);
        if (is_string($eventTypes)) {
            $eventTypes = array_filter(explode(',', $eventTypes));
        }

        $events = collect();

        // 1. ClientMedication — started / ceased
        if (empty($eventTypes) || array_intersect(['medication_started', 'medication_ceased'], $eventTypes)) {
            $medQuery = ClientMedication::query()
                ->with(['client:id,first_name,last_name', 'createdByUser:id,name', 'ceasedByUser:id,name'])
                ->select('id', 'client_id', 'created_by', 'name', 'dosage', 'created_at', 'ceased_at', 'ceased_reason', 'ceased_by');

            if ($clientId) {
                $medQuery->where('client_id', $clientId);
            }

            $meds = $medQuery->get();

            foreach ($meds as $med) {
                $clientName = $med->client ? trim($med->client->first_name.' '.$med->client->last_name) : 'Unknown';

                if (empty($eventTypes) || in_array('medication_started', $eventTypes)) {
                    $ts = $med->created_at;
                    if ($this->withinDateRange($ts, $dateFrom, $dateTo)) {
                        $events->push([
                            'id' => 'med_start_'.$med->id,
                            'event_type' => 'medication_started',
                            'timestamp' => $ts->toIso8601String(),
                            'description' => "{$med->name} {$med->dosage} started for {$clientName}",
                            'performed_by' => $med->createdByUser->name ?? null,
                            'client_id' => $med->client_id,
                            'client_name' => $clientName,
                            'details' => [
                                'medication' => $med->name,
                                'dosage' => $med->dosage,
                            ],
                        ]);
                    }
                }

                if ($med->ceased_at && (empty($eventTypes) || in_array('medication_ceased', $eventTypes))) {
                    $ts = $med->ceased_at instanceof Carbon ? $med->ceased_at : Carbon::parse($med->ceased_at);
                    if ($this->withinDateRange($ts, $dateFrom, $dateTo)) {
                        $events->push([
                            'id' => 'med_cease_'.$med->id,
                            'event_type' => 'medication_ceased',
                            'timestamp' => $ts->toIso8601String(),
                            'description' => "{$med->name} ceased for {$clientName}",
                            'performed_by' => $med->ceasedByUser->name ?? null,
                            'client_id' => $med->client_id,
                            'client_name' => $clientName,
                            'details' => [
                                'medication' => $med->name,
                                'reason' => $med->ceased_reason,
                            ],
                        ]);
                    }
                }
            }
        }

        // 2. ClientMedicationAdministration — dose_administered / dose_refused / dose_missed
        $adminTypes = ['dose_administered', 'dose_refused', 'dose_missed'];
        if (empty($eventTypes) || array_intersect($adminTypes, $eventTypes)) {
            $adminQuery = ClientMedicationAdministration::query()
                ->with(['client:id,first_name,last_name', 'medication:id,name,dosage,deleted_at', 'administeredBy:id,name', 'witnessedBy:id,name'])
                ->select('id', 'client_id', 'client_medication_id', 'administered_by', 'witnessed_by', 'scheduled_for', 'administered_at', 'status', 'reason', 'reason_code', 'dose_given', 'notes');

            if ($clientId) {
                $adminQuery->where('client_id', $clientId);
            }
            if ($dateFrom) {
                $adminQuery->where('administered_at', '>=', Carbon::parse($dateFrom)->startOfDay());
            }
            if ($dateTo) {
                $adminQuery->where('administered_at', '<=', Carbon::parse($dateTo)->endOfDay());
            }

            $admins = $adminQuery->get();

            foreach ($admins as $admin) {
                $eventType = match ($admin->status) {
                    'refused' => 'dose_refused',
                    'missed' => 'dose_missed',
                    default => 'dose_administered',
                };

                if (! empty($eventTypes) && ! in_array($eventType, $eventTypes)) {
                    continue;
                }

                $clientName = $admin->client ? trim($admin->client->first_name.' '.$admin->client->last_name) : 'Unknown';
                $medName = $admin->medication?->historicalDisplayName() ?? 'Unknown medication';
                $performedBy = $admin->administeredBy->name ?? null;

                $descMap = [
                    'dose_administered' => "{$medName} {$admin->dose_given} administered to {$clientName}",
                    'dose_refused' => "{$medName} refused by {$clientName}",
                    'dose_missed' => "{$medName} dose missed for {$clientName}",
                ];

                $reasonLabel = $admin->reason_code
                    ? (NotGivenReason::tryFrom($admin->reason_code)?->label() ?? $admin->reason_code)
                    : null;

                $events->push([
                    'id' => 'admin_'.$admin->id,
                    'event_type' => $eventType,
                    'timestamp' => ($admin->administered_at ?? $admin->scheduled_for ?? $admin->created_at ?? now())->toIso8601String(),
                    'description' => $descMap[$eventType],
                    'performed_by' => $performedBy,
                    'witness' => $admin->witnessedBy->name ?? null,
                    'client_id' => $admin->client_id,
                    'client_name' => $clientName,
                    'details' => [
                        'medication' => $medName,
                        'dose' => $admin->dose_given,
                        'reason_code' => $reasonLabel,
                        'reason' => $admin->reason,
                        'notes' => $admin->notes,
                    ],
                ]);
            }
        }

        // 3. MedicationPrescriberOrder — prescriber_order
        if (empty($eventTypes) || in_array('prescriber_order', $eventTypes)) {
            $orderQuery = MedicationPrescriberOrder::query()
                ->with(['client:id,first_name,last_name'])
                ->select('id', 'client_id', 'medication_name', 'dose', 'prescriber_name', 'order_type', 'status', 'created_at');

            if ($clientId) {
                $orderQuery->where('client_id', $clientId);
            }
            if ($dateFrom) {
                $orderQuery->where('created_at', '>=', Carbon::parse($dateFrom)->startOfDay());
            }
            if ($dateTo) {
                $orderQuery->where('created_at', '<=', Carbon::parse($dateTo)->endOfDay());
            }

            foreach ($orderQuery->get() as $order) {
                $clientName = $order->client ? trim($order->client->first_name.' '.$order->client->last_name) : 'Unknown';

                $events->push([
                    'id' => 'order_'.$order->id,
                    'event_type' => 'prescriber_order',
                    'timestamp' => $order->created_at->toIso8601String(),
                    'description' => "Prescriber order ({$order->order_type}) for {$order->medication_name} — {$clientName}",
                    'performed_by' => $order->prescriber_name,
                    'client_id' => $order->client_id,
                    'client_name' => $clientName,
                    'details' => [
                        'medication' => $order->medication_name,
                        'dose' => $order->dose,
                        'order_type' => $order->order_type,
                        'status' => $order->status,
                    ],
                ]);
            }
        }

        // 4. MedicationReview — review_completed
        if (empty($eventTypes) || in_array('review_completed', $eventTypes)) {
            $reviewQuery = MedicationReview::query()
                ->with(['client:id,first_name,last_name', 'reviewer:id,name'])
                ->whereNotNull('completed_date')
                ->select('id', 'client_id', 'review_type', 'completed_date', 'reviewer_name', 'reviewer_user_id', 'clinical_summary');

            if ($clientId) {
                $reviewQuery->where('client_id', $clientId);
            }
            if ($dateFrom) {
                $reviewQuery->where('completed_date', '>=', $dateFrom);
            }
            if ($dateTo) {
                $reviewQuery->where('completed_date', '<=', $dateTo);
            }

            foreach ($reviewQuery->get() as $review) {
                $clientName = $review->client ? trim($review->client->first_name.' '.$review->client->last_name) : 'Unknown';
                $reviewerName = $review->reviewer->name ?? $review->reviewer_name ?? null;

                $events->push([
                    'id' => 'review_'.$review->id,
                    'event_type' => 'review_completed',
                    'timestamp' => Carbon::parse($review->completed_date)->toIso8601String(),
                    'description' => "Medication review ({$review->review_type}) completed for {$clientName}",
                    'performed_by' => $reviewerName,
                    'client_id' => $review->client_id,
                    'client_name' => $clientName,
                    'details' => [
                        'review_type' => $review->review_type,
                        'summary' => $review->clinical_summary,
                    ],
                ]);
            }
        }

        // 5. MedicationDestruction — destruction
        if (empty($eventTypes) || in_array('destruction', $eventTypes)) {
            $destructionQuery = MedicationDestruction::query()
                ->with(['client:id,first_name,last_name', 'destroyedByUser:id,name'])
                ->select('id', 'client_id', 'medication_name', 'quantity', 'unit', 'reason', 'disposal_method', 'destroyed_by', 'destroyed_at');

            if ($clientId) {
                $destructionQuery->where('client_id', $clientId);
            }
            if ($dateFrom) {
                $destructionQuery->where('destroyed_at', '>=', Carbon::parse($dateFrom)->startOfDay());
            }
            if ($dateTo) {
                $destructionQuery->where('destroyed_at', '<=', Carbon::parse($dateTo)->endOfDay());
            }

            foreach ($destructionQuery->get() as $dest) {
                $clientName = $dest->client ? trim($dest->client->first_name.' '.$dest->client->last_name) : 'Unknown';
                $performedBy = $dest->destroyedByUser->name ?? null;

                $events->push([
                    'id' => 'dest_'.$dest->id,
                    'event_type' => 'destruction',
                    'timestamp' => $dest->destroyed_at?->toIso8601String() ?? $dest->created_at->toIso8601String(),
                    'description' => "{$dest->medication_name} ({$dest->quantity} {$dest->unit}) destroyed for {$clientName}",
                    'performed_by' => $performedBy,
                    'client_id' => $dest->client_id,
                    'client_name' => $clientName,
                    'details' => [
                        'medication' => $dest->medication_name,
                        'quantity' => $dest->quantity,
                        'unit' => $dest->unit,
                        'reason' => $dest->reason,
                        'method' => $dest->disposal_method,
                    ],
                ]);
            }
        }

        // 6. MedicationOrderVersion — medication_changed
        if (empty($eventTypes) || in_array('medication_changed', $eventTypes)) {
            $versionQuery = MedicationOrderVersion::query()
                ->with(['client:id,first_name,last_name', 'changedBy:id,name'])
                ->where('version_number', '>', 1)
                ->select('id', 'client_id', 'client_medication_id', 'version_number', 'name', 'dosage', 'frequency', 'route', 'instructions', 'is_prn', 'dose_times', 'change_reason', 'changed_by', 'created_at');

            if ($clientId) {
                $versionQuery->where('client_id', $clientId);
            }
            if ($dateFrom) {
                $versionQuery->where('created_at', '>=', Carbon::parse($dateFrom)->startOfDay());
            }
            if ($dateTo) {
                $versionQuery->where('created_at', '<=', Carbon::parse($dateTo)->endOfDay());
            }

            $versions = $versionQuery->get();

            // Load every version of the affected medications so each change can be
            // diffed against its immediate predecessor (vN vs vN-1) — the data for
            // a real before→after already lives in the full per-version snapshots.
            $medIds = $versions->pluck('client_medication_id')->filter()->unique()->values();
            $byMedVersion = $medIds->isEmpty()
                ? collect()
                : MedicationOrderVersion::query()
                    ->whereIn('client_medication_id', $medIds)
                    ->get(['id', 'client_medication_id', 'version_number', 'name', 'dosage', 'frequency', 'route', 'instructions', 'is_prn', 'dose_times'])
                    ->groupBy('client_medication_id')
                    ->map(fn ($group) => $group->keyBy('version_number'));

            foreach ($versions as $ver) {
                $clientName = $ver->client ? trim($ver->client->first_name.' '.$ver->client->last_name) : 'Unknown';
                $performedBy = $ver->changedBy->name ?? null;

                $previous = $byMedVersion[$ver->client_medication_id][$ver->version_number - 1] ?? null;
                $changes = $previous ? $this->diffVersions($previous, $ver) : [];

                $events->push([
                    'id' => 'ver_'.$ver->id,
                    'event_type' => 'medication_changed',
                    'timestamp' => $ver->created_at->toIso8601String(),
                    'description' => "{$ver->name} {$ver->dosage} changed (v{$ver->version_number}) for {$clientName}",
                    'performed_by' => $performedBy,
                    'client_id' => $ver->client_id,
                    'client_name' => $clientName,
                    'details' => [
                        'medication' => $ver->name,
                        'dosage' => $ver->dosage,
                        'version' => $ver->version_number,
                        'reason' => $ver->change_reason,
                        'changes' => $changes,
                    ],
                ]);
            }
        }

        // 7. ClientControlledDrugEntry — controlled-drug movements (with witness)
        if (empty($eventTypes) || array_intersect(['cd_given', 'cd_received', 'cd_wasted', 'cd_balance_check', 'cd_adjustment'], $eventTypes)) {
            $cdQuery = ClientControlledDrugEntry::query()
                ->with(['client:id,first_name,last_name', 'medication:id,name', 'recordedBy:id,name', 'witnessedBy:id,name']);

            if ($clientId) {
                $cdQuery->where('client_id', $clientId);
            }
            if ($dateFrom) {
                $cdQuery->where('recorded_at', '>=', Carbon::parse($dateFrom)->startOfDay());
            }
            if ($dateTo) {
                $cdQuery->where('recorded_at', '<=', Carbon::parse($dateTo)->endOfDay());
            }

            // The CD register uses two naming conventions across its write paths
            // (storeCDEntry vs the MAR administration flow). Map every real
            // entry_type to a distinct audit event so receipts, disposals, counts
            // and adjustments are no longer mislabelled "given".
            $cdEventMap = [
                'administered' => 'cd_given',
                'administration' => 'cd_given',
                'receipt' => 'cd_received',
                'received' => 'cd_received',
                'transfer_in' => 'cd_received',
                'disposal' => 'cd_wasted',
                'wasted' => 'cd_wasted',
                'transfer_out' => 'cd_wasted',
                'balance_check' => 'cd_balance_check',
                'stock_count' => 'cd_balance_check',
                'adjustment' => 'cd_adjustment',
            ];

            foreach ($cdQuery->get() as $entry) {
                $eventType = $cdEventMap[$entry->entry_type] ?? 'cd_given';
                if (! empty($eventTypes) && ! in_array($eventType, $eventTypes)) {
                    continue;
                }

                $clientName = $entry->client ? trim($entry->client->first_name.' '.$entry->client->last_name) : 'Unknown';
                $medName = $entry->medication->name ?? 'Controlled drug';
                $qty = trim(($entry->quantity ?? '').' '.($entry->unit ?? ''));

                // Two-person sign-off is the NICE SC1 / CD-policy requirement for
                // administration and disposal of a controlled drug. Receipts,
                // counts and adjustments are not flagged for a missing witness.
                $witnessRequired = in_array($eventType, ['cd_given', 'cd_wasted'], true);

                $descMap = [
                    'cd_given' => "Controlled drug {$medName} ({$qty}) administered — {$clientName}",
                    'cd_received' => "Controlled drug {$medName} ({$qty}) received into stock — {$clientName}",
                    'cd_wasted' => "Controlled drug {$medName} ({$qty}) disposed / wasted — {$clientName}",
                    'cd_balance_check' => "CD balance check — {$medName} for {$clientName}",
                    'cd_adjustment' => "CD stock adjustment — {$medName} ({$qty}) for {$clientName}",
                ];

                $events->push([
                    'id' => 'cd_'.$entry->id,
                    'event_type' => $eventType,
                    'timestamp' => ($entry->recorded_at ?? $entry->created_at ?? now())->toIso8601String(),
                    'description' => $descMap[$eventType] ?? "Controlled drug {$medName} ({$qty}) — {$clientName}",
                    'performed_by' => $entry->recordedBy->name ?? null,
                    'witness' => $entry->witnessedBy->name ?? null,
                    'witness_required' => $witnessRequired,
                    'client_id' => $entry->client_id,
                    'client_name' => $clientName,
                    'details' => [
                        'medication' => $medName,
                        'entry_type' => str_replace('_', ' ', (string) $entry->entry_type),
                        'quantity' => $entry->quantity,
                        'unit' => $entry->unit,
                        'balance_before' => $entry->on_hand_before,
                        'balance_after' => $entry->on_hand_after,
                        'reason' => $entry->reason,
                    ],
                ]);
            }
        }

        // 8. MedicationError — medication_error
        if (empty($eventTypes) || in_array('medication_error', $eventTypes)) {
            $errorQuery = MedicationError::query()
                ->with(['client:id,first_name,last_name', 'reportedBy:id,name']);

            if ($clientId) {
                $errorQuery->where('client_id', $clientId);
            }
            if ($dateFrom) {
                $errorQuery->where('reported_at', '>=', Carbon::parse($dateFrom)->startOfDay());
            }
            if ($dateTo) {
                $errorQuery->where('reported_at', '<=', Carbon::parse($dateTo)->endOfDay());
            }

            foreach ($errorQuery->get() as $error) {
                $clientName = $error->client ? trim($error->client->first_name.' '.$error->client->last_name) : 'Unknown';

                $events->push([
                    'id' => 'err_'.$error->id,
                    'event_type' => 'medication_error',
                    'timestamp' => ($error->reported_at ?? $error->created_at ?? now())->toIso8601String(),
                    'description' => 'Medication error ('.str_replace('_', ' ', (string) $error->error_type).") reported — {$clientName}",
                    'performed_by' => $error->reportedBy->name ?? null,
                    'client_id' => $error->client_id,
                    'client_name' => $clientName,
                    'details' => [
                        'error_type' => $error->error_type,
                        'severity' => $error->severity,
                        'status' => $error->status,
                    ],
                    'outcome' => $error->severity,
                ]);
            }
        }

        // 9. MedicationPharmacyOrder — stock received (real delivery receipts only;
        //    non-CD ad-hoc stock has no movement log, so nothing is invented here).
        if (empty($eventTypes) || in_array('stock_received', $eventTypes)) {
            $stockQuery = MedicationPharmacyOrder::query()
                ->with(['client:id,first_name,last_name', 'medication:id,name', 'receivedByUser:id,name'])
                ->whereNotNull('delivered_at');

            if ($clientId) {
                $stockQuery->where('client_id', $clientId);
            }
            if ($dateFrom) {
                $stockQuery->where('delivered_at', '>=', Carbon::parse($dateFrom)->startOfDay());
            }
            if ($dateTo) {
                $stockQuery->where('delivered_at', '<=', Carbon::parse($dateTo)->endOfDay());
            }

            foreach ($stockQuery->get() as $order) {
                $clientName = $order->client ? trim($order->client->first_name.' '.$order->client->last_name) : 'Unknown';
                $medName = $order->medication->name ?? 'Medication';

                $events->push([
                    'id' => 'stock_recv_'.$order->id,
                    'event_type' => 'stock_received',
                    'timestamp' => $order->delivered_at->toIso8601String(),
                    'description' => "{$medName} delivery received ({$order->quantity_received} units) — {$clientName}",
                    'performed_by' => $order->receivedByUser->name ?? null,
                    'client_id' => $order->client_id,
                    'client_name' => $clientName,
                    'details' => [
                        'medication' => $medName,
                        'pharmacy' => $order->pharmacy_name,
                        'quantity_received' => $order->quantity_received,
                        'batch_number' => $order->batch_number,
                    ],
                ]);
            }
        }

        // 10. Omissions — scheduled doses never recorded (real "blank MAR slot"
        //     detection over a bounded recent window; reuses MarScheduleService).
        if (empty($eventTypes) || in_array('omission', $eventTypes)) {
            foreach ($omissions->omissionsForRange(
                $dateFrom ? Carbon::parse($dateFrom) : null,
                $dateTo ? Carbon::parse($dateTo) : null,
                $clientId ? (int) $clientId : null,
            ) as $omission) {
                $events->push($omission);
            }
        }

        // ── Enrich every event with category / source / site / flags so the
        //    redesigned page can facet, surface compliance gaps and render the
        //    read-only detail drawer. (See docs/emar-redesign/audit-plan.md.)
        $clientSite = Client::query()->pluck('site_id', 'id');
        $siteNames = Site::query()->pluck('name', 'id');
        $categoryOf = [
            'dose_administered' => 'doses', 'dose_refused' => 'doses', 'dose_missed' => 'doses', 'omission' => 'doses',
            'cd_given' => 'controlled', 'cd_received' => 'controlled', 'cd_wasted' => 'controlled',
            'cd_balance_check' => 'controlled', 'cd_adjustment' => 'controlled',
            'medication_started' => 'clinical', 'medication_ceased' => 'clinical', 'medication_changed' => 'clinical',
            'prescriber_order' => 'clinical', 'review_completed' => 'clinical',
            'destruction' => 'stock', 'stock_received' => 'stock', 'medication_error' => 'errors',
        ];
        $sourceOf = [
            'dose_administered' => 'MAR', 'dose_refused' => 'MAR', 'dose_missed' => 'MAR', 'omission' => 'MAR',
            'cd_given' => 'CD', 'cd_received' => 'CD', 'cd_wasted' => 'CD', 'cd_balance_check' => 'CD', 'cd_adjustment' => 'CD',
            'medication_started' => 'MAR', 'medication_ceased' => 'MAR', 'medication_changed' => 'MAR',
            'prescriber_order' => 'Orders', 'review_completed' => 'Clinical',
            'destruction' => 'Stock', 'stock_received' => 'Stock', 'medication_error' => 'Errors',
        ];

        $events = $events->map(function (array $e) use ($categoryOf, $sourceOf, $clientSite, $siteNames) {
            $type = $e['event_type'];
            $siteId = $e['client_id'] ? ($clientSite[$e['client_id']] ?? null) : null;

            $flags = [];
            if (($e['witness_required'] ?? false) && empty($e['witness'])) {
                $flags[] = 'missing_witness';
            }
            if (in_array($type, ['dose_missed', 'omission'], true)) {
                $flags[] = 'omission';
            }
            if (in_array($type, ['medication_started', 'medication_ceased'], true) && empty($e['performed_by'])) {
                $flags[] = 'no_actor';
            }
            // A refusal/omission must carry a coded reason (NotGivenReason) — only
            // when neither the code nor a free-text reason is present is it a gap.
            if (in_array($type, ['dose_refused', 'dose_missed'], true)
                && empty($e['details']['reason_code'] ?? null)
                && empty($e['details']['reason'] ?? null)) {
                $flags[] = 'no_reason';
            }

            return array_merge($e, [
                'category' => $categoryOf[$type] ?? 'clinical',
                'source' => $sourceOf[$type] ?? 'MAR',
                'site_id' => $siteId,
                'site_name' => $siteId ? ($siteNames[$siteId] ?? null) : null,
                'witness' => $e['witness'] ?? null,
                'witness_required' => $e['witness_required'] ?? false,
                'outcome' => $e['outcome'] ?? null,
                'flags' => $flags,
            ]);
        });

        // Staff + site filters (post-enrichment — site is derived per event).
        $staffFilter = $request->query('staff');
        if ($staffFilter) {
            $events = $events->filter(fn ($e) => $e['performed_by'] === $staffFilter)->values();
        }
        $siteFilter = $request->integer('site_id') ?: null;
        if ($siteFilter) {
            $events = $events->filter(fn ($e) => (int) ($e['site_id'] ?? 0) === $siteFilter)->values();
        }

        // Sort by timestamp descending
        $sorted = $events->sortByDesc('timestamp')->values();

        // Stats — anchor the week/month boundaries to the worker timezone so they
        // match how the timeline groups days: a dose at 09:00 NZT is "today" even
        // though it is stored as the previous day in UTC. Carbon compares absolute
        // instants, so only the boundary's timezone needs to be right.
        $now = Carbon::now(config('app.worker_timezone', 'Pacific/Auckland'));
        $weekStart = $now->copy()->startOfWeek();
        $monthStart = $now->copy()->startOfMonth();
        $isGap = fn ($e) => ! empty(array_intersect($e['flags'] ?? [], ['missing_witness', 'omission']));

        $stats = [
            'total' => $sorted->count(),
            'this_week' => $sorted->filter(fn ($e) => Carbon::parse($e['timestamp'])->gte($weekStart))->count(),
            'this_month' => $sorted->filter(fn ($e) => Carbon::parse($e['timestamp'])->gte($monthStart))->count(),
            'open_gaps' => $sorted->filter($isGap)->count(),
        ];

        // Flat, client-side-filterable feed — the redesigned page facets by
        // view/category/search/staff with live counts. Capped for payload size.
        $events = $sorted->take(800)->values();

        $clients = Client::query()
            ->when($siteFilter, fn ($q) => $q->where('site_id', $siteFilter))
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name'])
            ->map(fn ($c) => ['id' => $c->id, 'name' => trim($c->first_name.' '.$c->last_name)]);

        $staff = $sorted->pluck('performed_by')->filter()->unique()->sort()->values()
            ->map(fn ($name, $i) => ['id' => $i, 'name' => $name]);

        $activeSite = $siteFilter ? Site::find($siteFilter) : null;

        return inertia('emar/AuditLog', [
            'events' => $events,
            'stats' => $stats,
            'clients' => $clients,
            'staff' => $staff,
            'sites' => Site::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'active_site' => $activeSite ? ['id' => $activeSite->id, 'name' => $activeSite->name] : null,
            'site_brand_colour' => $activeSite?->brand_colour,
            'user_first_name' => $user ? (explode(' ', trim((string) $user->name))[0] ?: null) : null,
            'filters' => [
                'client_id' => $clientId,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'event_types' => $eventTypes,
            ],
        ]);
    }

    private function withinDateRange($timestamp, ?string $dateFrom, ?string $dateTo): bool
    {
        if (! $timestamp) {
            return false;
        }

        $ts = $timestamp instanceof Carbon ? $timestamp : Carbon::parse($timestamp);

        if ($dateFrom && $ts->lt(Carbon::parse($dateFrom)->startOfDay())) {
            return false;
        }
        if ($dateTo && $ts->gt(Carbon::parse($dateTo)->endOfDay())) {
            return false;
        }

        return true;
    }

    /**
     * Field-by-field diff between two medication order versions, for the audit
     * drawer's before→after section. Only changed fields are returned.
     *
     * @return array<int, array{field: string, from: string, to: string}>
     */
    private function diffVersions(MedicationOrderVersion $from, MedicationOrderVersion $to): array
    {
        $labels = [
            'name' => 'Name',
            'dosage' => 'Dose',
            'frequency' => 'Frequency',
            'route' => 'Route',
            'instructions' => 'Instructions',
            'is_prn' => 'PRN (as-needed)',
            'dose_times' => 'Dose times',
        ];

        $changes = [];
        foreach ($labels as $field => $label) {
            $a = $this->formatVersionField($from->$field);
            $b = $this->formatVersionField($to->$field);
            if ($a !== $b) {
                $changes[] = ['field' => $label, 'from' => $a, 'to' => $b];
            }
        }

        return $changes;
    }

    private function formatVersionField($value): string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if (is_array($value)) {
            return implode(', ', $value);
        }

        return trim((string) ($value ?? ''));
    }
}
