<?php

namespace App\Http\Controllers\Operations;

use App\Domain\Hr\Services\HrEligibilityRuleSettings;
use App\Domain\Hr\Services\HrFatiguePolicySettings;
use App\Domain\Rostering\RosteringFeatureFlags;
use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Operations\WorkforcePreferences;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class WorkforceSettingsController extends Controller
{
    public function __construct(private RosteringFeatureFlags $features, private WorkforcePreferences $preferences, private HrFatiguePolicySettings $policies, private HrEligibilityRuleSettings $eligibilityRules) {}

    public function index(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor?->canDo('rostering.viewAny'), 403);
        $rules = $this->policies->snapshot();
        $eligibilityRules = $this->eligibilityRules->snapshot();
        $canEditRules = $actor->isApproved() && $actor->canDo('hr.settings.manage');

        return inertia('operations/workforce-settings', [
            'preferences' => $this->preferences->for($actor),
            'ownerLinks' => $this->ownerLinks($actor),
            'staffingRules' => [...Arr::except($rules, ['version']), 'can_edit' => $canEditRules,
                'can_view_history' => $canEditRules, 'scope' => 'organisation',
                'urls' => ['update' => $canEditRules ? route('operations.workforce.settings.staffing-rules.update') : null,
                    'history' => $canEditRules ? route('operations.workforce.settings.history') : null]],
            'eligibilityRules' => [...Arr::except($eligibilityRules, ['version']), 'can_edit' => $canEditRules,
                'can_view_history' => $canEditRules, 'scope' => 'organisation',
                'urls' => ['update' => $canEditRules ? route('operations.workforce.settings.eligibility-rules.update') : null,
                    'history' => $canEditRules ? route('operations.workforce.settings.history', ['action' => 'eligibility_rules']) : null]],
            'workforceSettings' => [
                'worker_timezone' => (string) config('app.worker_timezone', 'Pacific/Auckland'),
                'week_starts_on' => 'Monday',
                'fatigue' => $rules['values'],
                'features' => ['publish' => $this->features->publishEnabled(), 'auto_schedule' => $this->features->autoScheduleEnabled()],
                'policies' => [
                    ['key' => 'access', 'label' => 'House access', 'description' => 'Roster access follows each record’s permissions, site scope and worker ownership.'],
                    ['key' => 'eligibility', 'label' => 'Assignment checks', 'description' => 'Assignment checks include availability, approved leave, overlaps, fatigue, recorded compliance, medication competency and driving requirements. Review the current assignment result before confirming a worker.'],
                    ['key' => 'publication', 'label' => 'Roster publication', 'description' => 'Publication has its own permission and validates the selected period. Lifecycle and timesheet protections still apply.'],
                    ['key' => 'leave', 'label' => 'Leave privacy', 'description' => 'Approved leave affects availability. Availability summaries mask sensitive leave types and reasons unless you are the employee or an authorised HR manager.'],
                    ['key' => 'capacity', 'label' => 'Scheduled hours', 'description' => 'Capacity shows elapsed scheduled hours clipped to the visible period. Paid hours and breaks are managed through timesheets and payroll.'],
                ],
            ],
        ]);
    }

    public function update(Request $request)
    {
        $request->session()->forget('workforce_settings_result');
        $actor = $request->user();
        abort_unless($actor?->isApproved() && $actor->canDo('rostering.viewAny'), 403);
        $data = $request->validate([
            'default_tab' => ['required', 'in:shifts,calendar'],
            'roster_view' => ['required', 'in:grid,list'],
            'expected_revision' => ['required', 'string', 'size:64'],
        ]);
        $rootEntry = $this->isPhysicalRoot();
        $outcome = $this->preferences->save($actor, $data, $data['expected_revision'], $request);

        return $this->committedResult(redirect()->route('operations.workforce.settings')
            ->with('success', 'Your workforce preferences were saved.'), $rootEntry, 'preferences', $outcome);
    }

    public function updateStaffingRules(Request $request)
    {
        $request->session()->forget('workforce_settings_result');
        $actor = $request->user();
        abort_unless($actor?->isApproved() && $actor->canDo('rostering.viewAny') && $actor->canDo('hr.settings.manage'), 403);
        $data = $request->validate(['expected_revision' => ['required', 'string', 'size:64'],
            'reason' => ['required', 'string', 'max:2000'], ...$this->policies->validationRules()]);
        $rootEntry = $this->isPhysicalRoot();
        $outcome = $this->policies->save($actor, $data['values'], $data['expected_revision'], $data['reason'], $request);

        return $this->committedResult(redirect()->route('operations.workforce.settings')->with('success', $outcome['changed']
            ? 'Staffing rules were saved. Existing assignments are being checked again.'
            : 'Staffing rules already match the saved values.'), $rootEntry, 'staffing_rules', $outcome);
    }

    public function updateEligibilityRules(Request $request)
    {
        $request->session()->forget('workforce_settings_result');
        $actor = $request->user();
        abort_unless($actor?->isApproved() && $actor->canDo('rostering.viewAny') && $actor->canDo('hr.settings.manage'), 403);
        $data = $request->validate(['expected_revision' => ['required', 'string', 'size:64'],
            'reason' => ['required', 'string', 'max:2000'], ...$this->eligibilityRules->validationRules()]);
        $rootEntry = $this->isPhysicalRoot();
        $outcome = $this->eligibilityRules->save($actor, $data['values'], $data['expected_revision'], $data['reason'], $request);

        return $this->committedResult(redirect()->route('operations.workforce.settings')->with('success', $outcome['changed']
            ? 'Eligibility rules were saved. Existing assignments are being checked again.'
            : 'Eligibility rules already match the saved values.'), $rootEntry, 'eligibility_rules', $outcome);
    }

    private function isPhysicalRoot(): bool
    {
        return DB::connection()->transactionLevel() === 0 && ! DB::connection()->getPdo()->inTransaction();
    }

    private function committedResult(RedirectResponse $response, bool $rootEntry, string $action, array $outcome): RedirectResponse
    {
        if (! $rootEntry) {
            return $response;
        }
        try {
            if (! $this->isPhysicalRoot()) {
                return $response;
            }
            $receipt = [
                'action' => $action, 'actor_id' => (int) $outcome['actor_id'],
                'expected_revision' => $outcome['expected_revision'], 'prior_revision' => $outcome['prior_revision'],
                'revision' => $outcome['revision'], 'values' => Arr::only($outcome['values'], $action === 'preferences'
                    ? ['default_tab', 'roster_view'] : ($action === 'eligibility_rules' ? HrEligibilityRuleSettings::KEYS : HrFatiguePolicySettings::KEYS)), 'changed' => $outcome['changed'],
            ];
            if (in_array($action, ['staffing_rules', 'eligibility_rules'], true)) {
                $receipt['refresh'] = Arr::only($outcome['refresh'], ['status', 'recheck_id', 'source_version']);
            }

            return $response->with('workforce_settings_result', $receipt);
        } catch (Throwable $exception) {
            try {
                Log::warning('Committed workforce settings result could not be presented', [
                    'action' => $action, 'exception_class' => $exception::class,
                ]);
            } catch (Throwable) {
                // Metadata failure cannot reverse the committed command.
            }

            return $response;
        }
    }

    public function history(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor?->isApproved() && $actor->canDo('rostering.viewAny') && $actor->canDo('hr.settings.manage'), 403);
        $filters = $request->validate(['page' => ['nullable', 'integer', 'min:1'],
            'action' => ['nullable', 'in:staffing_rules,eligibility_rules']]);
        $policyClass = ($filters['action'] ?? 'staffing_rules') === 'eligibility_rules' ? HrEligibilityRuleSettings::class : HrFatiguePolicySettings::class;
        $history = AuditLog::query()->where('action', $policyClass::AUDIT_ACTION)
            ->where('auditable_type', (new AppSetting)->getMorphClass())->where('meta->source_key', $policyClass::KEY)
            ->with('user:id,name')->orderByDesc('id')->paginate(20)->withQueryString()
            ->through(function (AuditLog $row) use ($policyClass): array {
                $before = Arr::only($row->meta['before'] ?? [], $policyClass::KEYS);
                $after = Arr::only($row->meta['after'] ?? [], $policyClass::KEYS);
                $changes = collect($policyClass::KEYS)
                    ->filter(fn ($key) => ($before[$key] ?? null) !== ($after[$key] ?? null))
                    ->map(fn ($key) => ['key' => $key, 'before' => $before[$key] ?? null, 'after' => $after[$key] ?? null])->values()->all();

                return ['id' => $row->id, 'at' => $row->created_at->toISOString(),
                    'actor' => $row->user ? ['id' => $row->user->id, 'name' => $row->user->name] : null,
                    'reason' => (string) ($row->meta['reason'] ?? ''), 'changes' => $changes];
            });

        return response()->json($history)->header('Cache-Control', 'private, no-store');
    }

    private function ownerLinks(User $actor): array
    {
        return collect([
            ['permissions' => ['hr.leave.viewAny'], 'label' => 'HR leave', 'href' => '/hr/leave', 'description' => 'Leave requests, approvals and availability.'],
            ['permissions' => ['hr.training.view', 'training.viewAny'], 'label' => 'HR and safety training', 'href' => '/hr/training', 'description' => 'Training records and requirements used by assignment checks.'],
            ['permissions' => ['sites.viewAny'], 'label' => 'Houses and coverage', 'href' => '/sites', 'description' => 'House profiles, required staffing and coverage windows.'],
            ['permissions' => ['medications.settings.manage', 'medications.witness_pin.reset', 'medications.audit.view', 'medications.orders.manage', 'medications.view', 'medications.alerts.manage_house'], 'label' => 'eMAR settings', 'href' => '/emar/settings', 'description' => 'Medication competency, witness PINs and medication workflow rules.'],
            ['permissions' => ['controlRoom.alerts.manage'], 'label' => 'Control Room settings', 'href' => '/control-room/settings', 'description' => 'Control Room keeps its own alert and monitoring configuration.'],
            ['permissions' => [], 'label' => 'Notifications', 'href' => '/settings/notifications', 'description' => 'Your notification channels and delivery preferences.'],
        ])->filter(fn (array $link) => $link['permissions'] === [] || collect($link['permissions'])->contains(fn (string $permission) => $actor->canDo($permission)))
            ->map(fn (array $link) => Arr::except($link, 'permissions'))->values()->all();
    }
}
