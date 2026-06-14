<?php

namespace App\Http\Controllers\Emar;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationDestruction;
use App\Models\MedicationError;
use App\Models\MedicationOrderVersion;
use App\Models\MedicationPrescriberOrder;
use App\Models\MedicationReview;
use App\Models\Site;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request)
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
                ->with(['client:id,first_name,last_name'])
                ->select('id', 'client_id', 'name', 'dosage', 'created_at', 'ceased_at', 'ceased_reason');

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
                            'performed_by' => null,
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
                            'performed_by' => null,
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
                ->with(['client:id,first_name,last_name', 'medication:id,name,dosage', 'administeredBy:id,name'])
                ->select('id', 'client_id', 'client_medication_id', 'administered_by', 'administered_at', 'status', 'reason', 'dose_given', 'notes');

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
                $medName = $admin->medication->name ?? 'Unknown medication';
                $performedBy = $admin->administeredBy->name ?? null;

                $descMap = [
                    'dose_administered' => "{$medName} {$admin->dose_given} administered to {$clientName}",
                    'dose_refused' => "{$medName} refused by {$clientName}",
                    'dose_missed' => "{$medName} dose missed for {$clientName}",
                ];

                $events->push([
                    'id' => 'admin_'.$admin->id,
                    'event_type' => $eventType,
                    'timestamp' => ($admin->administered_at ?? $admin->scheduled_for ?? $admin->created_at ?? now())->toIso8601String(),
                    'description' => $descMap[$eventType],
                    'performed_by' => $performedBy,
                    'client_id' => $admin->client_id,
                    'client_name' => $clientName,
                    'details' => [
                        'medication' => $medName,
                        'dose' => $admin->dose_given,
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
                ->select('id', 'client_id', 'client_medication_id', 'version_number', 'name', 'dosage', 'change_reason', 'changed_by', 'created_at');

            if ($clientId) {
                $versionQuery->where('client_id', $clientId);
            }
            if ($dateFrom) {
                $versionQuery->where('created_at', '>=', Carbon::parse($dateFrom)->startOfDay());
            }
            if ($dateTo) {
                $versionQuery->where('created_at', '<=', Carbon::parse($dateTo)->endOfDay());
            }

            foreach ($versionQuery->get() as $ver) {
                $clientName = $ver->client ? trim($ver->client->first_name.' '.$ver->client->last_name) : 'Unknown';
                $performedBy = $ver->changedBy->name ?? null;

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
                    ],
                ]);
            }
        }

        // 7. ClientControlledDrugEntry — controlled-drug movements (with witness)
        if (empty($eventTypes) || array_intersect(['cd_given', 'cd_balance_check'], $eventTypes)) {
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

            foreach ($cdQuery->get() as $entry) {
                $eventType = $entry->entry_type === 'balance_check' ? 'cd_balance_check' : 'cd_given';
                if (! empty($eventTypes) && ! in_array($eventType, $eventTypes)) {
                    continue;
                }

                $clientName = $entry->client ? trim($entry->client->first_name.' '.$entry->client->last_name) : 'Unknown';
                $medName = $entry->medication->name ?? 'Controlled drug';

                $events->push([
                    'id' => 'cd_'.$entry->id,
                    'event_type' => $eventType,
                    'timestamp' => ($entry->recorded_at ?? $entry->created_at ?? now())->toIso8601String(),
                    'description' => $eventType === 'cd_balance_check'
                        ? "CD balance check — {$medName} for {$clientName}"
                        : "Controlled drug {$medName} ({$entry->quantity} {$entry->unit}) — {$clientName}",
                    'performed_by' => $entry->recordedBy->name ?? null,
                    'witness' => $entry->witnessedBy->name ?? null,
                    'witness_required' => true,
                    'client_id' => $entry->client_id,
                    'client_name' => $clientName,
                    'details' => [
                        'medication' => $medName,
                        'entry_type' => $entry->entry_type,
                        'quantity' => $entry->quantity,
                        'unit' => $entry->unit,
                        'balance_after' => $entry->on_hand_after,
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

        // ── Enrich every event with category / source / site / flags so the
        //    redesigned page can facet, surface compliance gaps and render the
        //    read-only detail drawer. (See docs/emar-redesign/audit-plan.md.)
        $clientSite = Client::query()->pluck('site_id', 'id');
        $siteNames = Site::query()->pluck('name', 'id');
        $categoryOf = [
            'dose_administered' => 'doses', 'dose_refused' => 'doses', 'dose_missed' => 'doses',
            'cd_given' => 'controlled', 'cd_balance_check' => 'controlled',
            'medication_started' => 'clinical', 'medication_ceased' => 'clinical', 'medication_changed' => 'clinical',
            'prescriber_order' => 'clinical', 'review_completed' => 'clinical',
            'destruction' => 'stock', 'medication_error' => 'errors',
        ];
        $sourceOf = [
            'dose_administered' => 'MAR', 'dose_refused' => 'MAR', 'dose_missed' => 'MAR',
            'cd_given' => 'CD', 'cd_balance_check' => 'CD',
            'medication_started' => 'MAR', 'medication_ceased' => 'MAR', 'medication_changed' => 'MAR',
            'prescriber_order' => 'Orders', 'review_completed' => 'Clinical',
            'destruction' => 'Stock', 'medication_error' => 'Errors',
        ];

        $events = $events->map(function (array $e) use ($categoryOf, $sourceOf, $clientSite, $siteNames) {
            $type = $e['event_type'];
            $siteId = $e['client_id'] ? ($clientSite[$e['client_id']] ?? null) : null;

            $flags = [];
            if (($e['witness_required'] ?? false) && empty($e['witness'])) {
                $flags[] = 'missing_witness';
            }
            if ($type === 'dose_missed') {
                $flags[] = 'omission';
            }
            if (in_array($type, ['medication_started', 'medication_ceased'], true) && empty($e['performed_by'])) {
                $flags[] = 'no_actor';
            }
            if (in_array($type, ['dose_refused', 'dose_missed'], true) && empty($e['details']['reason'] ?? null)) {
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

        // Stats
        $now = Carbon::now();
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
}
