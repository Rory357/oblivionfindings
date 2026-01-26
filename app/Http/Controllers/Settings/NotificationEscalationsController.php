<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\NotificationEscalationRule;
use Illuminate\Http\Request;

class NotificationEscalationsController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('settings.access.manage'), 403);

        $groups = (array) config('notification_events.groups', []);
        $allKeys = collect($groups)->flatten()->unique()->values();

        $existing = NotificationEscalationRule::query()
            ->whereIn('event_key', $allKeys)
            ->get()
            ->keyBy('event_key');

        // Build a full set so UI doesn't depend on missing rows.
        $rules = [];
        foreach ($allKeys as $key) {
            $r = $existing->get($key);
            $rules[$key] = [
                'enabled' => $r ? (bool) $r->enabled : false,
                'require_ack' => $r ? (bool) $r->require_ack : false,
                'must_ack_before_close' => $r ? (bool) $r->must_ack_before_close : false,
                'force_delivery' => $r ? (bool) $r->force_delivery : false,
                'remind_after_minutes' => $r ? (int) $r->remind_after_minutes : 60,
                'repeat_every_minutes' => $r ? (int) $r->repeat_every_minutes : 60,
                'max_reminders' => $r ? (int) $r->max_reminders : 3,
                'escalate_to_role_groups' => $r ? ((array) ($r->escalate_to_role_groups ?? [])) : [],
                'tiers' => $r ? ((array) ($r->tiers ?? [])) : [],
            ];
        }

        return inertia('settings/notification-escalations', [
            'groups' => $groups,
            'rules' => $rules,
            'availableRoleGroups' => [
                'managers' => 'Managers (all)',
                'managers_core' => 'Managers (core)',
                'coordinators' => 'Coordinators',
                'approvers' => 'Timesheet approvers',
                'auditors' => 'Auditors',
            ],
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('settings.access.manage'), 403);

        $data = $request->validate([
            'rules' => ['required', 'array'],
        ]);

        $rules = (array) $data['rules'];
        foreach ($rules as $eventKey => $rule) {
            if (!is_array($rule)) continue;

            $tiersIn = (array) ($rule['tiers'] ?? []);
            $tiers = [];
            foreach ($tiersIn as $t) {
                if (!is_array($t)) continue;
                $from = (int) ($t['from_reminder'] ?? 0);
                $groups = array_values(array_unique(array_filter((array) ($t['role_groups'] ?? []))));
                if ($from <= 0 || empty($groups)) continue;
                $tiers[] = ['from_reminder' => $from, 'role_groups' => $groups];
            }

            NotificationEscalationRule::updateOrCreate([
                'event_key' => (string) $eventKey,
            ], [
                'enabled' => (bool) ($rule['enabled'] ?? false),
                'require_ack' => (bool) ($rule['require_ack'] ?? false),
                'must_ack_before_close' => ((bool) ($rule['must_ack_before_close'] ?? false)) && ((bool) ($rule['require_ack'] ?? false)),
                'force_delivery' => (bool) ($rule['force_delivery'] ?? false),
                'remind_after_minutes' => max(1, (int) ($rule['remind_after_minutes'] ?? 60)),
                'repeat_every_minutes' => max(1, (int) ($rule['repeat_every_minutes'] ?? 60)),
                'max_reminders' => max(0, (int) ($rule['max_reminders'] ?? 3)),
                'escalate_to_role_groups' => array_values(array_unique(array_filter((array) ($rule['escalate_to_role_groups'] ?? [])))),
                'tiers' => array_values($tiers),
            ]);
        }

        return redirect()->back()->with('success', 'Escalation rules updated.');
    }
}
