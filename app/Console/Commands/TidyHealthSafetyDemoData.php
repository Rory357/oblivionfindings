<?php

namespace App\Console\Commands;

use App\Models\ClientIncident;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Health and safety UI/UX audit, packages B9 and Q8 (31 Oct 2026): tidy the demo server's H&S data.
 *
 *  1. marker   — strip the seeder's "[H&S-DEMO-SEED]" marker from incident, hazard and fleet incident text (the 29 Oct
 *                clean-up stripped it from six other tables only). Incidents with a saved copy are listed, not changed.
 *  2. text     — replace known test text ("xvxcvxcvxcvcxv", "Final-pass flag test.", "Seed draft incident …", "dfghdfgh")
 *                with plain example text. Only an exact match is replaced.
 *  3. owners   — open actions owned by a family member, client or board member move to --action-owner.
 *  4. handover — reviews from an incident stuck on "handover not required" (Q8: the close check asks for an acceptance
 *                that "not required" can't give):
 *                 - incident submitted and linked to the review → "awaiting acceptance", so the normal handover works;
 *                 - incident still a draft and not linked, or the incident is gone → the review is soft-deleted, when
 *                   it has no actions, investigations or risk assessments (otherwise listed for a person).
 *
 * Never run without --dry-run first. Applying needs --manifest (a path outside the app) for every before-value.
 */
class TidyHealthSafetyDemoData extends Command
{
    protected $signature = 'health-safety:tidy-demo-data
        {--dry-run : List what would change without writing anything}
        {--action-owner= : User id that takes over actions owned by family, clients or board members}
        {--manifest= : Where to write the before/after manifest (required when applying; keep it outside the app)}';

    protected $description = 'Tidy the demo H&S data (marker, test text, action owners, stuck handovers); dry-run first.';

    public const MARKER = '[H&S-DEMO-SEED]';

    private const MARKER_COLUMNS = ['client_incidents' => 'description', 'site_hazards' => 'description', 'fleet_incidents' => 'description'];

    /** Not people who carry out safety actions. */
    private const NOT_ACTION_OWNERS = ['next_of_kin', 'client', 'board_member', 'board_chair', 'board_observer', 'board_secretary', 'board_trustee'];

    /** People who may take the moved actions. */
    private const ACTION_OWNER_ROLES = ['health_safety_officer', 'compliance_lead'];

    private const OPEN_ACTION_STATUSES = [HsCorrectiveAction::STATUS_OPEN, HsCorrectiveAction::STATUS_IN_PROGRESS, HsCorrectiveAction::STATUS_COMPLETED];

    /** Exact test text → example text. */
    private const INCIDENT_TEXT = [
        'xvxcvxcvxcvcxv' => 'Resident lost their footing on the front steps while getting into the van and fell onto their side. Grazed knee and sore wrist; first aid given and GP review booked.',
        'Final-pass flag test.' => 'Resident became upset during the evening routine and threw a cup across the lounge. No one was hurt; staff gave them space and used calm redirection.',
    ];

    private const DRAFT_TEXT = 'Seed draft incident (editable until submitted).';

    /** Draft wording by incident type; several per type so a list of drafts doesn't repeat one sentence (picked by id). */
    private const DRAFT_TEXT_BY_TYPE = [
        'fall' => [
            'Resident slipped getting out of the shower; staff helped them up. Checking for injuries before submitting.',
            'Found sitting on the bedroom floor at the 2 am check, says they slid off the bed. No obvious injury; obs to follow.',
            'Tripped on the garden path edge during the afternoon walk. Small graze on the hand; adding details before submitting.',
            'Lost balance turning in the kitchen and sat down hard. Says their hip is sore; waiting on the nurse\'s check.',
        ],
        'behavioural' => ['Resident was distressed after a phone call and shouted at a flatmate. Notes still being added.'],
        'medication_error' => ['Evening dose given 40 minutes late after the shift handover ran over. Checking the chart before submitting.'],
    ];

    private const DRAFT_TEXT_OTHER = 'Started by the support worker on shift; details still being added before it is submitted.';

    private const ACTION_TEXT = [
        'dfghdfgh' => ['title' => 'Check the handrail on the front steps', 'description' => 'Loose bracket noticed after the fall. Tighten or replace it and record the check.'],
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $manifestPath = $this->option('manifest');
        if (! $dry && blank($manifestPath)) {
            $this->error('Applying needs --manifest=<path outside the app>. Run with --dry-run first.');

            return self::FAILURE;
        }
        $owner = $this->actionOwner();
        if ($owner === false) {
            return self::FAILURE;
        }

        $plan = [
            'marker' => $this->markerPlan(),
            'text' => $this->textPlan(),
            'owners' => $this->ownerPlan($owner),
            'handover' => $this->handoverPlan(),
        ];
        $this->report($plan, $owner, $dry);
        if ($dry) {
            return self::SUCCESS;
        }

        DB::transaction(fn () => $this->apply($plan, $owner));
        $manifest = ['database' => DB::connection()->getDatabaseName(), 'applied_at' => now('UTC')->toIso8601String(),
            'action_owner' => $owner?->id, 'plan' => $plan];
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        @chmod($manifestPath, 0600);
        $this->info("Applied. Manifest: {$manifestPath}");

        return self::SUCCESS;
    }

    /** @return User|null|false null when no action owner was given; false when the given one can't take actions */
    private function actionOwner(): User|null|false
    {
        $id = $this->option('action-owner');
        if (blank($id)) {
            return null;
        }
        $user = User::query()->find((int) $id);
        $roles = $user === null ? [] : $this->rolesOf([(int) $user->id])[(int) $user->id] ?? [];
        if ($user === null || $user->approved_at === null || array_intersect($roles, self::ACTION_OWNER_ROLES) === []) {
            $this->error('--action-owner must be an approved health and safety officer or compliance lead.');

            return false;
        }

        return $user;
    }

    private function markerPlan(): array
    {
        $plan = ['change' => [], 'skipped_saved_copy' => []];
        $saved = Schema::hasTable('incident_saved_copies')
            ? DB::table('incident_saved_copies')->distinct()->pluck('incident_id')->map(fn ($id) => (int) $id)->flip()
            : collect();
        foreach (self::MARKER_COLUMNS as $table => $column) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach (DB::table($table)->where($column, 'like', '%'.self::MARKER.'%')->orderBy('id')->get(['id', $column]) as $row) {
                $entry = ['table' => $table, 'id' => (int) $row->id, 'column' => $column, 'before' => $row->{$column},
                    'after' => trim(preg_replace('/\s*'.preg_quote(self::MARKER, '/').'\s*/', ' ', $row->{$column}))];
                if ($table === 'client_incidents' && $saved->has((int) $row->id)) {
                    $plan['skipped_saved_copy'][] = $entry;

                    continue;
                }
                $plan['change'][] = $entry;
            }
        }

        return $plan;
    }

    private function textPlan(): array
    {
        $plan = [];
        foreach (DB::table('client_incidents')->whereIn('description', [...array_keys(self::INCIDENT_TEXT), self::DRAFT_TEXT])
            ->orderBy('id')->get(['id', 'reference_number', 'status', 'type', 'description']) as $row) {
            $after = $row->description === self::DRAFT_TEXT
                ? (isset(self::DRAFT_TEXT_BY_TYPE[$row->type])
                    ? self::DRAFT_TEXT_BY_TYPE[$row->type][(int) $row->id % count(self::DRAFT_TEXT_BY_TYPE[$row->type])]
                    : self::DRAFT_TEXT_OTHER)
                : self::INCIDENT_TEXT[$row->description];
            if ($row->description === self::DRAFT_TEXT && $row->status !== 'draft') {
                continue;
            }
            $plan[] = ['table' => 'client_incidents', 'id' => (int) $row->id, 'ref' => $row->reference_number,
                'before' => ['description' => $row->description], 'after' => ['description' => $after]];
        }
        foreach (DB::table('hs_corrective_actions')->whereIn('title', array_keys(self::ACTION_TEXT))->orderBy('id')
            ->get(['id', 'reference_number', 'title', 'description']) as $row) {
            $after = self::ACTION_TEXT[$row->title];
            $plan[] = ['table' => 'hs_corrective_actions', 'id' => (int) $row->id, 'ref' => $row->reference_number,
                'before' => ['title' => $row->title, 'description' => $row->description],
                'after' => $row->description === $row->title ? $after : ['title' => $after['title']]];
        }

        return $plan;
    }

    private function ownerPlan(?User $owner): array
    {
        $actions = DB::table('hs_corrective_actions')->whereNull('deleted_at')->whereNotNull('assigned_to_user_id')
            ->whereIn('status', self::OPEN_ACTION_STATUSES)->orderBy('id')
            ->get(['id', 'reference_number', 'title', 'assigned_to_user_id']);
        $roles = $this->rolesOf($actions->pluck('assigned_to_user_id')->map(fn ($id) => (int) $id)->unique()->all());
        $names = DB::table('users')->whereIn('id', $actions->pluck('assigned_to_user_id'))->pluck('name', 'id');
        $plan = [];
        foreach ($actions as $action) {
            $userRoles = $roles[(int) $action->assigned_to_user_id] ?? [];
            if (array_intersect($userRoles, self::NOT_ACTION_OWNERS) === []) {
                continue;
            }
            $plan[] = ['id' => (int) $action->id, 'ref' => $action->reference_number, 'title' => $action->title,
                'before' => ['assigned_to_user_id' => (int) $action->assigned_to_user_id],
                'before_owner' => ($names[$action->assigned_to_user_id] ?? '?').' ('.implode('+', $userRoles).')',
                'after' => $owner === null ? null : ['assigned_to_user_id' => (int) $owner->id]];
        }

        return $plan;
    }

    private function handoverPlan(): array
    {
        $plan = ['await' => [], 'delete' => [], 'list' => []];
        $events = HsEvent::query()->where('status', '!=', HsEvent::STATUS_CLOSED)
            ->where('handover_status', HsEvent::HANDOVER_NOT_REQUIRED)
            ->whereIn('source_type', [ClientIncident::class, '\\'.ClientIncident::class])
            ->orderBy('id')->get(['id', 'reference_number', 'status', 'source_id', 'handover_status']);
        foreach ($events as $event) {
            $incident = DB::table('client_incidents')->where('id', $event->source_id)
                ->first(['id', 'reference_number', 'status', 'submitted_at', 'hs_event_id']);
            $linked = $incident !== null && (int) $incident->hs_event_id === (int) $event->id;
            $entry = ['id' => (int) $event->id, 'ref' => $event->reference_number, 'status' => $event->status,
                'incident' => $incident === null ? null : $incident->reference_number.' ('.$incident->status.')',
                'before' => ['handover_status' => $event->handover_status]];
            if ($incident !== null && $linked && $incident->status !== 'draft' && $incident->submitted_at !== null) {
                $plan['await'][] = $entry + ['after' => ['handover_status' => HsEvent::HANDOVER_AWAITING_ACCEPTANCE]];

                continue;
            }
            $orphan = $incident === null || (! $linked && $incident->status === 'draft');
            $children = $this->childCounts((int) $event->id);
            if ($orphan && array_sum($children) === 0) {
                $plan['delete'][] = $entry + ['after' => ['deleted_at' => 'now'], 'reason' => $incident === null ? 'incident gone' : 'draft incident, not linked'];

                continue;
            }
            $plan['list'][] = $entry + ['reason' => $orphan ? 'has '.json_encode(array_filter($children)) : 'incident not submitted or not linked'];
        }

        return $plan;
    }

    /** @return array<string, int> */
    private function childCounts(int $eventId): array
    {
        $counts = [];
        foreach (['hs_corrective_actions', 'hs_investigations', 'hs_risk_assessments'] as $table) {
            $counts[$table] = Schema::hasTable($table) ? DB::table($table)->where('hs_event_id', $eventId)->count() : 0;
        }

        return $counts;
    }

    /** @return array<int, list<string>> */
    private function rolesOf(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $roles = [];
        foreach (DB::table('role_user')->join('roles', 'roles.id', '=', 'role_user.role_id')->whereIn('role_user.user_id', $userIds)
            ->get(['role_user.user_id', 'roles.name']) as $row) {
            $roles[(int) $row->user_id][] = $row->name;
        }
        foreach (DB::table('users')->whereIn('id', $userIds)->whereNotNull('role')->get(['id', 'role']) as $row) {
            $roles[(int) $row->id] = array_values(array_unique([...($roles[(int) $row->id] ?? []), $row->role]));
        }

        return $roles;
    }

    private function apply(array $plan, ?User $owner): void
    {
        foreach ($plan['marker']['change'] as $row) {
            DB::table($row['table'])->where('id', $row['id'])->where($row['column'], $row['before'])->update([$row['column'] => $row['after']]);
        }
        foreach ($plan['text'] as $row) {
            $query = DB::table($row['table'])->where('id', $row['id']);
            foreach ($row['before'] as $column => $value) {
                $query->where($column, $value);
            }
            $query->update($row['after']);
        }
        if ($owner !== null) {
            foreach ($plan['owners'] as $row) {
                DB::table('hs_corrective_actions')->where('id', $row['id'])->where('assigned_to_user_id', $row['before']['assigned_to_user_id'])
                    ->update(['assigned_to_user_id' => $owner->id, 'updated_at' => now()]);
            }
        }
        foreach ($plan['handover']['await'] as $row) {
            DB::table('hs_events')->where('id', $row['id'])->where('handover_status', HsEvent::HANDOVER_NOT_REQUIRED)
                ->update(['handover_status' => HsEvent::HANDOVER_AWAITING_ACCEPTANCE, 'updated_at' => now()]);
        }
        foreach ($plan['handover']['delete'] as $row) {
            DB::table('hs_events')->where('id', $row['id'])->whereNull('deleted_at')->update(['deleted_at' => now()]);
        }
    }

    private function report(array $plan, ?User $owner, bool $dry): void
    {
        $this->line(($dry ? 'DRY RUN — nothing written.' : 'APPLYING.').' Database: '.DB::connection()->getDatabaseName());
        $byTable = collect($plan['marker']['change'])->countBy('table')->map(fn ($n, $t) => "{$t} {$n}")->implode(', ');
        $this->line('1. Marker: '.count($plan['marker']['change']).' rows ('.($byTable ?: 'none').'); skipped (saved copy): '.count($plan['marker']['skipped_saved_copy']));
        foreach (array_slice($plan['marker']['change'], 0, 3) as $row) {
            $this->line("   e.g. {$row['table']} #{$row['id']}: \"{$row['before']}\" → \"{$row['after']}\"");
        }
        $this->line('2. Test text: '.count($plan['text']).' rows');
        foreach ($plan['text'] as $row) {
            $this->line("   {$row['table']} {$row['ref']}: ".json_encode($row['before'], JSON_UNESCAPED_UNICODE).' → '.json_encode($row['after'], JSON_UNESCAPED_UNICODE));
        }
        $this->line('3. Action owners: '.count($plan['owners']).' open actions → '.($owner === null ? 'NOT MOVED (no --action-owner)' : "{$owner->name} (#{$owner->id})"));
        foreach ($plan['owners'] as $row) {
            $this->line("   {$row['ref']} \"{$row['title']}\" — now {$row['before_owner']}");
        }
        $this->line('4. Stuck handovers: '.count($plan['handover']['await']).' → awaiting acceptance; '.count($plan['handover']['delete']).' soft-deleted; '.count($plan['handover']['list']).' listed for a person');
        foreach (['await', 'delete', 'list'] as $group) {
            foreach ($plan['handover'][$group] as $row) {
                $this->line("   [{$group}] {$row['ref']} ({$row['status']}) incident ".($row['incident'] ?? 'gone').(isset($row['reason']) ? " — {$row['reason']}" : ''));
            }
        }
    }
}
