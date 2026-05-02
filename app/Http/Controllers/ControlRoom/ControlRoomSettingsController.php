<?php

namespace App\Http\Controllers\ControlRoom;

use App\Http\Controllers\Controller;
use App\Jobs\DispatchFleetSignalOutbox;
use App\Models\ControlRoom\ConfigOption;
use App\Models\ControlRoom\MaintenanceWindow;
use App\Models\ControlRoom\Playbook;
use App\Models\ControlRoom\SignalRule;
use App\Models\ControlRoom\SignalSource;
use App\Models\ControlRoom\SignalType;
use App\Models\ControlRoom\TriageQueue;
use App\Models\FleetSignalOutbox;
use App\Models\Site;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ControlRoomSettingsController extends Controller
{
    /**
     * Display the tabbed settings page.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);

        $activeTab = $request->get('tab', 'rules');

        // Signal rules with relations
        $signalRules = SignalRule::with(['signalType:id,code,name', 'signalSource:id,name,slug', 'playbook:id,name'])
            ->orderBy('priority')
            ->get()
            ->map(fn (SignalRule $rule) => [
                'id' => $rule->id,
                'name' => $rule->name,
                'signal_type_id' => $rule->signal_type_id,
                'signal_type_code' => $rule->signal_type_code,
                'signal_type_name' => $rule->signalType?->name,
                'signal_source_id' => $rule->signal_source_id,
                'signal_source_name' => $rule->signalSource?->name,
                'priority' => $rule->priority,
                'conditions' => $rule->conditions,
                'output_severity' => $rule->output_severity,
                'output_escalation_level' => $rule->output_escalation_level,
                'output_tier' => $rule->output_tier,
                'dedup_window_minutes' => $rule->dedup_window_minutes,
                'deduplicate' => $rule->deduplicate,
                'suppress_in_maintenance' => $rule->suppress_in_maintenance,
                'notify_roles' => $rule->notify_roles ?? [],
                'notify_users' => $rule->notify_users ?? [],
                'playbook_id' => $rule->playbook_id,
                'playbook_name' => $rule->playbook?->name,
                'is_active' => $rule->is_active,
            ]);

        // Signal types for dropdowns
        $signalTypes = SignalType::orderBy('category')->orderBy('name')
            ->get()
            ->map(fn (SignalType $type) => [
                'id' => $type->id,
                'code' => $type->code,
                'name' => $type->name,
                'category' => $type->category,
                'default_severity' => $type->default_severity,
            ]);

        // Signal sources with health status
        $signalSources = SignalSource::orderBy('name')
            ->get()
            ->map(fn (SignalSource $source) => [
                'id' => $source->id,
                'name' => $source->name,
                'slug' => $source->slug,
                'vendor' => $source->vendor,
                'status' => $source->status,
                'capabilities' => $source->capabilities ?? [],
                'last_heartbeat_at' => $source->last_heartbeat_at?->toISOString(),
                'last_signal_at' => $source->last_signal_at?->toISOString(),
                'signal_count_24h' => $source->signal_count_24h ?? 0,
                'is_healthy' => $source->isHealthy(),
            ]);

        // Triage queues with alert counts
        $triageQueues = TriageQueue::with('escalateToQueue:id,name')
            ->orderBy('tier')
            ->orderBy('name')
            ->get()
            ->map(fn (TriageQueue $queue) => [
                'id' => $queue->id,
                'name' => $queue->name,
                'code' => $queue->code,
                'tier' => $queue->tier,
                'description' => $queue->description,
                'handle_severities' => $queue->handle_severities ?? [],
                'handle_sources' => $queue->handle_sources ?? [],
                'handle_alert_types' => $queue->handle_alert_types ?? [],
                'assigned_roles' => $queue->assigned_roles ?? [],
                'assigned_users' => $queue->assigned_users ?? [],
                'auto_escalate_after_minutes' => $queue->auto_escalate_after_minutes,
                'escalate_to_queue_id' => $queue->escalate_to_queue_id,
                'escalate_to_queue_name' => $queue->escalateToQueue?->name,
                'is_active' => $queue->is_active,
                'open_alert_count' => $queue->getOpenAlertCount(),
            ]);

        // Maintenance windows (active + scheduled, plus recent completed)
        $maintenanceWindows = MaintenanceWindow::with(['signalSource:id,name', 'createdBy:id,name'])
            ->whereIn('status', ['scheduled', 'active'])
            ->orWhere(function ($q) {
                $q->whereIn('status', ['completed', 'cancelled'])
                    ->where('ends_at', '>=', now()->subDays(7));
            })
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 WHEN status = 'scheduled' THEN 1 ELSE 2 END")
            ->orderBy('starts_at')
            ->get()
            ->map(fn (MaintenanceWindow $window) => [
                'id' => $window->id,
                'name' => $window->name,
                'description' => $window->description,
                'signal_source_id' => $window->signal_source_id,
                'signal_source_name' => $window->signalSource?->name,
                'site_id' => $window->site_id,
                'starts_at' => $window->starts_at?->toISOString(),
                'ends_at' => $window->ends_at?->toISOString(),
                'status' => $window->status,
                'created_by_name' => $window->createdBy?->name,
            ]);

        // Playbooks for dropdowns
        $playbooks = Playbook::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        // Sites for dropdowns
        $sites = Site::orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Site $site) => [
                'id' => $site->id,
                'name' => $site->name,
            ]);

        // Config options grouped
        $configOptions = ConfigOption::orderBy('group')->orderBy('sort_order')->get()
            ->groupBy('group')
            ->map(fn ($items) => $items->map(fn ($o) => [
                'id' => $o->id,
                'group' => $o->group,
                'value' => $o->value,
                'label' => $o->label,
                'color' => $o->color,
                'description' => $o->description,
                'sort_order' => $o->sort_order,
                'is_active' => $o->is_active,
            ])->values());

        $signalOutbox = FleetSignalOutbox::query()
            ->with('signal:id,asset_id,device_id,signal_type,severity_hint,occurred_at')
            ->whereIn('status', ['failed', 'dead_letter'])
            ->latest('last_attempt_at')
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (FleetSignalOutbox $outbox) => [
                'id' => $outbox->id,
                'status' => $outbox->status,
                'attempts' => $outbox->attempts,
                'last_attempt_at' => $outbox->last_attempt_at?->toISOString(),
                'last_error' => $outbox->last_error,
                'created_at' => $outbox->created_at?->toISOString(),
                'updated_at' => $outbox->updated_at?->toISOString(),
                'can_retry' => ! $outbox->last_attempt_at || $outbox->last_attempt_at->lte(now()->subMinutes(5)),
                'signal' => $outbox->signal ? [
                    'id' => $outbox->signal->id,
                    'asset_id' => $outbox->signal->asset_id,
                    'device_id' => $outbox->signal->device_id,
                    'signal_type' => $outbox->signal->signal_type,
                    'severity_hint' => $outbox->signal->severity_hint,
                    'occurred_at' => $outbox->signal->occurred_at?->toISOString(),
                ] : null,
            ]);

        return Inertia::render('control-room/settings', [
            'activeTab' => $activeTab,
            'signalRules' => $signalRules,
            'signalTypes' => $signalTypes,
            'signalSources' => $signalSources,
            'triageQueues' => $triageQueues,
            'maintenanceWindows' => $maintenanceWindows,
            'playbooks' => $playbooks,
            'sites' => $sites,
            'configOptions' => $configOptions,
            'signalOutbox' => $signalOutbox,
        ]);
    }

    public function retrySignalOutbox(Request $request, FleetSignalOutbox $outbox)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        abort_unless(in_array($outbox->status, ['failed', 'dead_letter'], true), 422, 'Only failed signal deliveries can be retried.');
        abort_if(
            $outbox->last_attempt_at && $outbox->last_attempt_at->gt(now()->subMinutes(5)),
            429,
            'This signal delivery was retried recently. Please wait before retrying it again.',
        );

        $previousStatus = $outbox->status;
        $outbox->update([
            'status' => 'pending',
            'last_error' => null,
        ]);

        DispatchFleetSignalOutbox::dispatch($outbox->id);

        AuditLogger::log('controlRoom.signalOutbox.retry', $outbox, [
            'outbox_id' => $outbox->id,
            'fleet_signal_id' => $outbox->fleet_signal_id,
            'previous_status' => $previousStatus,
            'retried_by' => $user->id,
        ]);

        return redirect()
            ->route('control-room.settings.index', ['tab' => 'signal-outbox'])
            ->with('success', 'Signal outbox retry queued.');
    }

    /**
     * Create a new signal rule.
     */
    public function storeSignalRule(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'signal_type_id' => 'nullable|exists:control_room_signal_types,id',
            'signal_type_code' => 'nullable|string|max:100',
            'signal_source_id' => 'nullable|exists:control_room_signal_sources,id',
            'priority' => 'required|integer|min:0|max:1000',
            'conditions' => 'nullable|array',
            'output_severity' => 'nullable|string|in:low,medium,high,critical',
            'output_escalation_level' => 'nullable|integer|min:0|max:10',
            'output_tier' => 'nullable|integer|min:1|max:5',
            'dedup_window_minutes' => 'nullable|integer|min:0|max:10080',
            'deduplicate' => 'boolean',
            'suppress_in_maintenance' => 'boolean',
            'notify_roles' => 'nullable|array',
            'notify_users' => 'nullable|array',
            'playbook_id' => 'nullable|exists:control_room_playbooks,id',
            'is_active' => 'boolean',
        ]);

        SignalRule::create($validated);

        return redirect()->route('control-room.settings.index', ['tab' => 'rules'])
            ->with('success', 'Signal rule created.');
    }

    /**
     * Update an existing signal rule.
     */
    public function updateSignalRule(Request $request, SignalRule $rule)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'signal_type_id' => 'nullable|exists:control_room_signal_types,id',
            'signal_type_code' => 'nullable|string|max:100',
            'signal_source_id' => 'nullable|exists:control_room_signal_sources,id',
            'priority' => 'required|integer|min:0|max:1000',
            'conditions' => 'nullable|array',
            'output_severity' => 'nullable|string|in:low,medium,high,critical',
            'output_escalation_level' => 'nullable|integer|min:0|max:10',
            'output_tier' => 'nullable|integer|min:1|max:5',
            'dedup_window_minutes' => 'nullable|integer|min:0|max:10080',
            'deduplicate' => 'boolean',
            'suppress_in_maintenance' => 'boolean',
            'notify_roles' => 'nullable|array',
            'notify_users' => 'nullable|array',
            'playbook_id' => 'nullable|exists:control_room_playbooks,id',
            'is_active' => 'boolean',
        ]);

        $rule->update($validated);

        return redirect()->route('control-room.settings.index', ['tab' => 'rules'])
            ->with('success', 'Signal rule updated.');
    }

    /**
     * Delete a signal rule.
     */
    public function deleteSignalRule(Request $request, SignalRule $rule)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);

        $rule->delete();

        return redirect()->route('control-room.settings.index', ['tab' => 'rules'])
            ->with('success', 'Signal rule deleted.');
    }

    /**
     * Create a new triage queue.
     */
    public function storeQueue(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:control_room_triage_queues,code',
            'tier' => 'required|integer|min:1|max:5',
            'description' => 'nullable|string|max:500',
            'handle_severities' => 'nullable|array',
            'handle_severities.*' => 'string|in:low,medium,high,critical',
            'handle_sources' => 'nullable|array',
            'handle_sources.*' => 'string|max:100',
            'handle_alert_types' => 'nullable|array',
            'handle_alert_types.*' => 'string|max:100',
            'assigned_roles' => 'nullable|array',
            'assigned_roles.*' => 'string|max:100',
            'assigned_users' => 'nullable|array',
            'assigned_users.*' => 'integer',
            'auto_escalate_after_minutes' => 'nullable|integer|min:1|max:10080',
            'escalate_to_queue_id' => 'nullable|exists:control_room_triage_queues,id',
            'is_active' => 'boolean',
        ]);

        TriageQueue::create($validated);

        return redirect()->route('control-room.settings.index', ['tab' => 'queues'])
            ->with('success', 'Triage queue created.');
    }

    /**
     * Update an existing triage queue.
     */
    public function updateQueue(Request $request, TriageQueue $queue)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:control_room_triage_queues,code,' . $queue->id,
            'tier' => 'required|integer|min:1|max:5',
            'description' => 'nullable|string|max:500',
            'handle_severities' => 'nullable|array',
            'handle_severities.*' => 'string|in:low,medium,high,critical',
            'handle_sources' => 'nullable|array',
            'handle_sources.*' => 'string|max:100',
            'handle_alert_types' => 'nullable|array',
            'handle_alert_types.*' => 'string|max:100',
            'assigned_roles' => 'nullable|array',
            'assigned_roles.*' => 'string|max:100',
            'assigned_users' => 'nullable|array',
            'assigned_users.*' => 'integer',
            'auto_escalate_after_minutes' => 'nullable|integer|min:1|max:10080',
            'escalate_to_queue_id' => 'nullable|exists:control_room_triage_queues,id',
            'is_active' => 'boolean',
        ]);

        $queue->update($validated);

        return redirect()->route('control-room.settings.index', ['tab' => 'queues'])
            ->with('success', 'Triage queue updated.');
    }

    /**
     * Create a new maintenance window.
     */
    public function storeMaintenanceWindow(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'signal_source_id' => 'nullable|exists:control_room_signal_sources,id',
            'site_id' => 'nullable|exists:sites,id',
            'starts_at' => 'required|date|after_or_equal:now',
            'ends_at' => 'required|date|after:starts_at',
        ]);

        $validated['status'] = 'scheduled';
        $validated['created_by_user_id'] = $user->id;

        MaintenanceWindow::create($validated);

        return redirect()->route('control-room.settings.index', ['tab' => 'maintenance'])
            ->with('success', 'Maintenance window scheduled.');
    }

    /**
     * Update an existing maintenance window.
     */
    public function updateMaintenanceWindow(Request $request, MaintenanceWindow $window)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);

        abort_if(in_array($window->status, ['completed', 'cancelled']), 422, 'Cannot edit a completed or cancelled window.');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'signal_source_id' => 'nullable|exists:control_room_signal_sources,id',
            'site_id' => 'nullable|exists:sites,id',
            'starts_at' => 'required|date',
            'ends_at' => 'required|date|after:starts_at',
        ]);

        $window->update($validated);

        return redirect()->route('control-room.settings.index', ['tab' => 'maintenance'])
            ->with('success', 'Maintenance window updated.');
    }

    /**
     * Cancel a maintenance window.
     */
    public function cancelMaintenanceWindow(Request $request, MaintenanceWindow $window)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);

        abort_unless(in_array($window->status, ['scheduled', 'active']), 422, 'Only scheduled or active windows can be cancelled.');

        $window->update(['status' => 'cancelled']);

        return redirect()->route('control-room.settings.index', ['tab' => 'maintenance'])
            ->with('success', 'Maintenance window cancelled.');
    }

    // ── Config Options (Ticket Settings) ────────────────────────────────

    /**
     * Create a new config option.
     */
    public function storeConfigOption(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);

        $validated = $request->validate([
            'group' => 'required|string|max:50',
            'value' => 'required|string|max:100',
            'label' => 'required|string|max:200',
            'color' => 'nullable|string|max:20',
            'description' => 'nullable|string|max:500',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $validated['is_active'] = true;
        $validated['sort_order'] = $validated['sort_order'] ?? (ConfigOption::where('group', $validated['group'])->max('sort_order') + 1);

        ConfigOption::create($validated);

        AuditLogger::log('controlRoom.settings.configOption.create', null, $validated);

        return redirect()->route('control-room.settings.index', ['tab' => 'ticket-options'])
            ->with('success', 'Option created.');
    }

    /**
     * Update a config option.
     */
    public function updateConfigOption(Request $request, ConfigOption $option)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);

        $validated = $request->validate([
            'label' => 'sometimes|string|max:200',
            'color' => 'nullable|string|max:20',
            'description' => 'nullable|string|max:500',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'sometimes|boolean',
        ]);

        $option->update($validated);

        AuditLogger::log('controlRoom.settings.configOption.update', null, ['option_id' => $option->id]);

        return redirect()->route('control-room.settings.index', ['tab' => 'ticket-options'])
            ->with('success', 'Option updated.');
    }

    /**
     * Delete a config option.
     */
    public function deleteConfigOption(Request $request, ConfigOption $option)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);

        AuditLogger::log('controlRoom.settings.configOption.delete', null, [
            'group' => $option->group,
            'value' => $option->value,
        ]);

        $option->delete();

        return redirect()->route('control-room.settings.index', ['tab' => 'ticket-options'])
            ->with('success', 'Option deleted.');
    }
}
