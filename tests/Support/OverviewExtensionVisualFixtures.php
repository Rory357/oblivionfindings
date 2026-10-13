<?php

namespace Tests\Support;

use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkPlanningRecord;
use App\Services\Overview\OverviewAccess;
use App\Services\Overview\OverviewData;
use App\Services\Overview\OverviewPlanDefinitions;
use Illuminate\Support\Facades\DB;

final class OverviewExtensionVisualFixtures
{
    public static function seed(): void
    {
        if (! app()->environment('testing') || ! preg_match('/\Aoblivion_overview_proof_[0-9]+\z/', (string) config('database.connections.mysql.database'))) {
            throw new \RuntimeException('Extension fixtures require the isolated preview database.');
        }
        if (WorkPlanningRecord::exists()) {
            throw new \RuntimeException('Planning examples are already present.');
        }
        $user = User::where('email', 'overview@demo.test')->sole();
        $permissions = ['it.manage', 'hr.employees.viewAny', 'hr.employees.manage', 'hr.wellbeing.view', 'hr.performance.manage', 'hr.payroll.view', 'hr.assets.view', 'hr.assets.viewAllSites', 'governance.actions.manage', 'roadmap.view', 'roadmap.decisions.view', 'care_plans.viewAny', 'checklists.view', 'checklists.schedule'];
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        }
        $user = $user->fresh();
        $site = Site::where('name', 'Harbour House')->sole();
        $titles = [
            'worker-readiness' => 'Prepare for Monday’s Harbour shift', 'worker-reminders' => 'Check my learning booking',
            'lead-break-cover' => 'Agree cover for the afternoon breaks', 'lead-supplies' => 'Restock the shared equipment cupboard', 'lead-relief' => 'Prepare the weekend relief handover',
            'manager-visits' => 'Harbour House service visit', 'manager-capacity' => 'Review the summer service plan', 'manager-resources' => 'Arrange two shared training tablets', 'manager-continuity' => 'Run the communications fallback exercise',
            'hr-position-descriptions' => 'Review the senior support role', 'it-patching' => 'Review tablet update readiness', 'it-backups' => 'Test restoration of the shared documents', 'it-certificates' => 'Renew the staff portal certificate', 'it-licences' => 'Review shared productivity licences', 'it-security-reviews' => 'Review site access arrangements',
            'governance-induction' => 'Complete the board workspace orientation', 'governance-engagement' => 'Plan the community listening session', 'governance-external-review' => 'Prepare the review evidence register',
        ];
        foreach (OverviewPlanDefinitions::all() as $kind => $definition) {
            foreach (['in_progress', 'completed'] as $index => $status) {
                $details = array_fill_keys(array_keys($definition['fields']), 'Fictional example for interface review.');
                foreach ($definition['numbers'] ?? [] as $number) {
                    $details[$number] = $number === 'entitled' ? 20 : 16;
                }
                if ($kind === 'it-licences') {
                    $details['product'] = $index ? 'Service tools' : 'Shared productivity suite';
                }
                $record = WorkPlanningRecord::create(['kind' => $kind, 'title' => $titles[$kind].($index ? ' · previous review' : ''),
                    'site_id' => $definition['scope'] === 'site' ? $site->id : null, 'owner_id' => $user->id, 'created_by' => $user->id,
                    'due_date' => today()->addDays($index ? -3 : 2), 'status' => $status, 'progress' => $index ? 100 : 45, 'details' => $details,
                    'completion_note' => $index ? 'Fictional completed example with an outcome reference.' : null,
                    'completed_at' => $index ? now()->subDays(3) : null]);
                DB::table('work_planning_revisions')->insert(['record_id' => $record->id, 'actor_id' => $user->id, 'version' => 1, 'snapshot' => json_encode($record->toArray()), 'created_at' => now()]);
            }
        }
        $results = [];
        foreach (OverviewAccess::ROLES as $role) {
            $blocks = app(OverviewData::class)->build($user, $role, 'month', 'all')['blocks'];
            $results[$role] = array_map(fn ($block) => ['state' => $block['state'], 'total' => $block['total'], 'href' => $block['href'] ?? null], $blocks);
        }
        file_put_contents(storage_path('app/overview-proof/extension-capabilities.json'), json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
