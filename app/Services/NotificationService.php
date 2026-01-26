<?php

namespace App\Services;

use App\Models\Client;
use App\Models\RoleNotificationPreference;
use App\Models\NotificationEscalationRule;
use App\Models\Timesheet;
use App\Models\UserNotificationPreference;
use App\Models\User;
use App\Notifications\AppEventNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class NotificationService
{
    protected static array $escalationRuleCache = [];
    /**
     * Roles that should generally be kept in-the-loop for operational changes.
     * Portal roles (client/next_of_kin) are intentionally excluded from these
     * database notifications to avoid accidentally leaking internal operations.
     */
    public const MANAGER_ROLES = [
        'admin',
        'provider_manager',
        'coordinator',
        'hr',
        'finance',
        'auditor',
    ];

    // A tighter subset used for operational workflow routing.
    public const MANAGERS_CORE_ROLES = [
        'admin',
        'provider_manager',
    ];

    public const COORDINATOR_ROLES = [
        'coordinator',
    ];

    public const AUDITOR_ROLES = [
        'auditor',
    ];

    /**
     * Send a simple, consistent "CRUD event" notification.
     */
    public function notifyCrud(
        ?User $actor,
        string $action,
        string $entityLabel,
        ?Model $entity,
        ?Client $client = null,
        array $extra = []
    ): void {
        $eventKey = $extra['event_key'] ?? $this->deriveEventKey($action, $entityLabel);
        $extra['event_key'] = $eventKey;

        // Escalation / acknowledgement settings (admin-configurable)
        $rule = $this->escalationRuleFor($eventKey);
        if ($rule && $rule->enabled) {
            $extra['data'] = array_merge((array) ($extra['data'] ?? []), [
                'ack_required' => (bool) $rule->require_ack,
                'must_ack_before_close' => (bool) $rule->must_ack_before_close,
                'escalation' => [
                    'enabled' => true,
                    'require_ack' => (bool) $rule->require_ack,
                    'remind_after_minutes' => (int) $rule->remind_after_minutes,
                    'repeat_every_minutes' => (int) $rule->repeat_every_minutes,
                    'max_reminders' => (int) $rule->max_reminders,
                    'escalate_to_role_groups' => (array) ($rule->escalate_to_role_groups ?? []),
                    'tiers' => (array) ($rule->tiers ?? []),
                    'force_delivery' => (bool) $rule->force_delivery,
                ],
            ]);
        }

        $extra = $this->applyRoutingDefaults($extra);

        $recipients = $this->resolveRecipients($actor, $entity, $client, $extra);

        // If force_delivery is enabled for this event, bypass user/role preferences.
        if (!$rule || !$rule->enabled || !$rule->force_delivery) {
            $recipients = $this->applyPreferences($recipients, $eventKey);
        }

        if ($recipients->isEmpty()) {
            return;
        }

        $context = $extra['context'] ?? $this->buildContext($entity, $client, $extra);

        $payload = array_merge([
            'kind' => 'crud',
            'action' => $action,
            'entity' => $entityLabel,
            'entity_id' => $entity?->getKey(),
            'client_id' => $client?->id ?? ($entity?->client_id ?? null),
            'event_key' => $eventKey,
            'title' => $extra['title'] ?? $this->defaultTitle($action, $entityLabel, $entity, $client),
            'body' => $extra['body'] ?? null,
            'url' => $extra['url'] ?? $this->defaultUrl($entity, $client),
            'context' => $context,
            'actor' => $actor ? [
                'id' => $actor->id,
                'name' => $actor->name,
            ] : null,
        ], $extra['data'] ?? []);

        $notification = new AppEventNotification($payload);

        $recipients->each(fn(User $u) => $u->notify($notification));
    }

    protected function escalationRuleFor(string $eventKey): ?NotificationEscalationRule
    {
        if (array_key_exists($eventKey, self::$escalationRuleCache)) {
            return self::$escalationRuleCache[$eventKey];
        }

        $rule = NotificationEscalationRule::query()->where('event_key', $eventKey)->first();
        self::$escalationRuleCache[$eventKey] = $rule;

        return $rule;
    }

    /**
     * Determine who should receive a notification for this event.
     */
    protected function resolveRecipients(?User $actor, ?Model $entity, ?Client $client, array $extra): Collection
    {
        $ids = collect();

        // Optional routing: role groups (more precise than the legacy include_managers flag)
        $roleGroups = (array) ($extra['_target_role_groups'] ?? []);
        foreach ($roleGroups as $group) {
            $ids = $ids->merge($this->resolveRoleGroupUserIds((string) $group));
        }

        $includeManagers = array_key_exists('include_managers', $extra) ? (bool) $extra['include_managers'] : true;
        $includeAssignedWorkers = array_key_exists('include_assigned_workers', $extra) ? (bool) $extra['include_assigned_workers'] : true;
        $includeEntityUser = array_key_exists('include_entity_user', $extra) ? (bool) $extra['include_entity_user'] : true;

        // 1) Managers (roles)
        if ($includeManagers) {
            $managerIds = User::query()
                ->whereHas('roles', fn($q) => $q->whereIn('name', self::MANAGER_ROLES))
                ->pluck('id');
            $ids = $ids->merge($managerIds);
        }

        // 2) If event is client-related, include assigned support workers (optional)
        $clientModel = $client;
        if (!$clientModel && $entity && property_exists($entity, 'client_id')) {
            if (!empty($entity->client_id)) {
                $clientModel = Client::query()->find($entity->client_id);
            }
        }

        if ($includeAssignedWorkers && $clientModel) {
            $ids = $ids->merge($clientModel->supportWorkers()->pluck('users.id'));
        }

        // 3) If entity has a user_id (staff-specific), notify that staff member (optional)
        if ($includeEntityUser && $entity && isset($entity->user_id) && !empty($entity->user_id)) {
            $ids = $ids->merge([$entity->user_id]);
        }

        // 4) Explicit targets (optional)
        if (!empty($extra['target_user_ids']) && is_array($extra['target_user_ids'])) {
            $ids = $ids->merge($extra['target_user_ids']);
        }

        // 5) Remove actor
        if ($actor) {
            $ids = $ids->reject(fn($id) => (int) $id === (int) $actor->id);
        }

        $ids = $ids->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return User::query()->whereIn('id', $ids)->get();
    }


    protected function defaultTitle(string $action, string $entityLabel, ?Model $entity, ?Client $client): string
    {
        $who = $client ? " for {$client->first_name} {$client->last_name}" : '';

        $label = $entityLabel;
        $verb = match ($action) {
            'created' => 'created',
            'updated' => 'updated',
            'deleted' => 'deleted',
            default => $action,
        };

        return ucfirst($label) . " {$verb}{$who}.";
    }

    protected function defaultUrl(?Model $entity, ?Client $client): ?string
    {
        // Keep it simple - point to the most relevant view.
        if ($entity instanceof Client) {
            return url("/clients/{$entity->id}");
        }

        // Many client sub-resources live under the client.
        $cid = $client?->id ?? ($entity?->client_id ?? null);
        if ($cid) {
            // Prefer timeline - it's a good universal landing page.
            return url("/clients/{$cid}/timeline");
        }

        // Staff entities
        if ($entity && isset($entity->user_id) && !empty($entity->user_id)) {
            return url("/staff/{$entity->user_id}");
        }

        return null;
    }

    protected function deriveEventKey(string $action, string $entityLabel): string
    {
        $entity = Str::slug($entityLabel);
        $entity = str_replace('-', '_', $entity);
        $action = Str::slug($action);
        $action = str_replace('-', '_', $action);

        // Common normalization
        if ($entity === 'incident') {
            $entity = 'incidents';
        }
        if ($entity === 'timesheet') {
            $entity = 'timesheets';
        }
        if ($entity === 'follow_up' || $entity === 'followups') {
            $entity = 'followups';
        }

        return $entity . '.' . $action;
    }

    protected function applyRoutingDefaults(array $extra): array
    {
        $eventKey = $extra['event_key'] ?? null;
        if (!$eventKey) return $extra;

        $routes = (array) config('notification_routing.routes', []);
        $rule = $routes[$eventKey] ?? null;
        if (!$rule) return $extra;

        // Expand target groups into flags + explicit user ids.
        $groups = (array) ($rule['target_groups'] ?? []);

        // Preserve explicit overrides if provided.
        $extra['include_managers'] = array_key_exists('include_managers', $extra)
            ? $extra['include_managers']
            : ($rule['include_managers'] ?? true);

        $extra['include_assigned_workers'] = array_key_exists('include_assigned_workers', $extra)
            ? $extra['include_assigned_workers']
            : ($rule['include_assigned_workers'] ?? true);

        $extra['include_entity_user'] = array_key_exists('include_entity_user', $extra)
            ? $extra['include_entity_user']
            : ($rule['include_entity_user'] ?? true);

        $targetIds = collect($extra['target_user_ids'] ?? []);
        $client = $extra['client'] ?? null;

        // Group routing (role-based)
        $extra['_target_role_groups'] = $groups;

        if (!$targetIds->isEmpty()) {
            $extra['target_user_ids'] = $targetIds->unique()->values()->all();
        }

        return $extra;
    }

    public function resolveRoleGroupUserIds(string $group): Collection
    {
        return match ($group) {
            'managers' => User::query()
                ->whereHas('roles', fn($q) => $q->whereIn('name', self::MANAGER_ROLES))
                ->pluck('id'),
            'managers_core' => User::query()
                ->whereHas('roles', fn($q) => $q->whereIn('name', self::MANAGERS_CORE_ROLES))
                ->pluck('id'),
            'coordinators' => User::query()
                ->whereHas('roles', fn($q) => $q->whereIn('name', self::COORDINATOR_ROLES))
                ->pluck('id'),
            'auditors' => User::query()
                ->whereHas('roles', fn($q) => $q->whereIn('name', self::AUDITOR_ROLES))
                ->pluck('id'),
            'approvers' => User::query()
                ->where(function ($q) {
                    $q->whereHas('roles', fn($rq) => $rq->whereIn('name', self::MANAGERS_CORE_ROLES))
                      ->orWhereHas('roles.permissions', fn($pq) => $pq->where('key', 'timesheets.approve'));
                })
                ->pluck('id'),
            default => collect(),
        };
    }

    public function applyPreferences(Collection $recipients, string $eventKey): Collection
    {
        if ($recipients->isEmpty()) return $recipients;

        $userIds = $recipients->pluck('id')->values();
        $userPrefs = UserNotificationPreference::query()
            ->whereIn('user_id', $userIds)
            ->where('key', $eventKey)
            ->get(['user_id', 'enabled'])
            ->keyBy('user_id');

        // Load roles + role prefs only if needed.
        $recipients->loadMissing('roles:id,name');
        $roleIds = $recipients->flatMap(fn(User $u) => $u->roles->pluck('id'))->unique()->values();
        $rolePrefs = $roleIds->isEmpty() ? collect() : RoleNotificationPreference::query()
            ->whereIn('role_id', $roleIds)
            ->where('key', $eventKey)
            ->get(['role_id', 'enabled']);

        return $recipients->filter(function (User $u) use ($eventKey, $userPrefs, $rolePrefs) {
            // 1) user override wins
            if ($userPrefs->has($u->id)) {
                return (bool) $userPrefs->get($u->id)->enabled;
            }

            // 2) role defaults (if any set)
            $roles = $u->roles ?? collect();
            if ($roles->isEmpty()) {
                // Legacy users.role fallback
                if (!empty($u->role)) {
                    $legacyRole = \App\Models\Role::query()->where('name', $u->role)->first();
                    if ($legacyRole) {
                        $pref = $rolePrefs->firstWhere('role_id', $legacyRole->id);
                        if ($pref) return (bool) $pref->enabled;
                    }
                }
                return true;
            }

            $roleSubset = $rolePrefs->whereIn('role_id', $roles->pluck('id'));
            if ($roleSubset->isEmpty()) {
                return true;
            }

            // If any role enables, treat as enabled. Otherwise (only disables), disabled.
            if ($roleSubset->contains(fn($p) => (bool) $p->enabled === true)) {
                return true;
            }

            return false;
        })->values();
    }

    // Backwards-compatible alias for internal calls.
    protected function filterRecipientsByPreferences(Collection $recipients, string $eventKey): Collection
    {
        return $this->applyPreferences($recipients, $eventKey);
    }

    protected function buildContext(?Model $entity, ?Client $client, array $extra): array
    {
        $ctx = [];

        if ($client) {
            $ctx['Client'] = trim($client->first_name . ' ' . $client->last_name);
        }

        if (!empty($extra['severity'])) {
            $ctx['Severity'] = (string) $extra['severity'];
        }

        if ($entity instanceof Timesheet) {
            $entity->loadMissing(['user:id,name', 'shift.client:id,first_name,last_name,site_id', 'shift.site:id,name']);
            $ctx['Staff'] = $entity->user?->name;
            if ($entity->shift) {
                $ctx['Shift'] = optional($entity->shift->start_time)->format('Y-m-d H:i')
                    . ' → ' . optional($entity->shift->end_time)->format('Y-m-d H:i');
                $ctx['Site'] = $entity->shift->site?->name;
                if (!$client && $entity->shift->client) {
                    $ctx['Client'] = trim($entity->shift->client->first_name . ' ' . $entity->shift->client->last_name);
                }
            }
            $ctx['Status'] = $entity->status;
            if ($entity->submitted_at) $ctx['Submitted'] = $entity->submitted_at->format('Y-m-d H:i');
            if ($entity->approved_at) $ctx['Decision'] = $entity->approved_at->format('Y-m-d H:i');
        }

        // Generic entity info
        if ($entity && isset($entity->status) && !isset($ctx['Status'])) {
            $ctx['Status'] = (string) $entity->status;
        }

        if ($entity && method_exists($entity, 'getKey')) {
            $ctx['Reference'] = class_basename($entity) . ' #' . $entity->getKey();
        }

        // Remove null/empty
        return collect($ctx)->filter(fn($v) => !is_null($v) && $v !== '')
            ->map(fn($v) => is_string($v) ? $v : (string) $v)
            ->all();
    }
}
