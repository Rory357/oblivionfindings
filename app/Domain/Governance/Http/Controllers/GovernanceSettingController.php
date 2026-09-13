<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Models\GovernanceSetting;
use App\Domain\Governance\Services\GovernanceAuditService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GovernanceSettingController extends Controller
{
    /**
     * Setting definitions — describes every editable governance configuration
     * value. Used to render the UI and validate input on update().
     *
     * @var array<int, array{key: string, label: string, category: string, type: 'number'|'text'|'json', default: mixed, description: string}>
     */
    private array $definitions;

    public function __construct()
    {
        $this->definitions = [
            // ── Spend Approval thresholds ──
            [
                'key' => 'spend_approval.threshold.capex',
                'label' => 'Capex threshold',
                'category' => GovernanceSetting::CATEGORY_SPEND_APPROVAL,
                'type' => 'number',
                'default' => 5000,
                'description' => 'Capital expenditure above this requires a Resolution sign-off',
            ],
            [
                'key' => 'spend_approval.threshold.opex',
                'label' => 'Opex threshold',
                'category' => GovernanceSetting::CATEGORY_SPEND_APPROVAL,
                'type' => 'number',
                'default' => 10000,
                'description' => 'Single-bill / supplier opex above this requires sign-off',
            ],
            [
                'key' => 'spend_approval.threshold.supplier_contract',
                'label' => 'Supplier contract threshold',
                'category' => GovernanceSetting::CATEGORY_SPEND_APPROVAL,
                'type' => 'number',
                'default' => 10000,
                'description' => 'Multi-year / annualised supplier contract above this requires sign-off',
            ],
            [
                'key' => 'spend_approval.threshold.donor_restricted',
                'label' => 'Donor-restricted spend threshold',
                'category' => GovernanceSetting::CATEGORY_SPEND_APPROVAL,
                'type' => 'number',
                'default' => 25000,
                'description' => 'Spend against restricted donor funds above this requires sign-off',
            ],

            // ── Compliance escalation ──
            [
                'key' => 'compliance.escalation.max_level',
                'label' => 'Max compliance escalation level',
                'category' => GovernanceSetting::CATEGORY_ESCALATION,
                'type' => 'number',
                'default' => 3,
                'description' => 'Compliance reminders escalate this many times before stopping',
            ],
            [
                'key' => 'compliance.escalation.final_notify_user_id',
                'label' => 'Final escalation recipient',
                'category' => GovernanceSetting::CATEGORY_ESCALATION,
                'type' => 'number',
                'default' => null,
                'description' => 'User ID receiving notifications at the max escalation level (CEO / Chair)',
            ],

            // ── Site budget variance alert ──
            [
                'key' => 'budget.variance_alert.percent',
                'label' => 'Site variance alert %',
                'category' => GovernanceSetting::CATEGORY_GENERAL,
                'type' => 'number',
                'default' => 10,
                'description' => 'Sites exceeding monthly budget by this % auto-create a compliance reminder',
            ],
        ];
    }

    public function index(): Response
    {
        $settings = collect($this->definitions)->map(function (array $def) {
            return [
                ...$def,
                'value' => GovernanceSetting::get($def['key'], $def['default']),
            ];
        });

        $profileService = app(\App\Domain\Governance\Services\GovernanceVotingProfileService::class);
        $activeProfile = $profileService->getActiveProfile('board');
        $candidateProfile = $activeProfile ?? $profileService->getOrCreateCandidateDefault('board');

        $boardMembers = \App\Domain\Governance\Models\BoardMember::with('user')
            ->get()
            ->map(function ($m) {
                return [
                    'id' => $m->id,
                    'name' => $m->user?->name ?? 'Unknown',
                    'email' => $m->user?->email,
                    'role' => $m->board_role,
                    'has_voting_seat' => $m->has_voting_seat,
                    'can_vote' => $m->canVote(),
                    'is_active' => (bool) $m->is_active,
                    'term_start' => $m->term_start?->toDateString(),
                    'term_end' => $m->term_end?->toDateString(),
                ];
            });

        $eligibleCount = $boardMembers->where('can_vote', true)->count();
        $quorumRequired = $profileService->calculateQuorumRequired($eligibleCount, $candidateProfile);

        return Inertia::render('Governance/Settings/Index', [
            'settings' => $settings,
            'categories' => [
                GovernanceSetting::CATEGORY_SPEND_APPROVAL => 'Spend approval thresholds',
                GovernanceSetting::CATEGORY_ESCALATION => 'Escalation',
                GovernanceSetting::CATEGORY_GENERAL => 'General',
            ],
            'rulesProfile' => [
                'profile' => $candidateProfile,
                'isConfirmed' => $candidateProfile->isConfirmed(),
                'statusLabel' => $candidateProfile->isConfirmed()
                    ? 'Rules confirmed by governing authority'
                    : 'Rules not confirmed — live voting unavailable',
                'eligibleVoterCount' => $eligibleCount,
                'quorumRequired' => $quorumRequired,
                'quorumFormula' => 'floor(N/2)+1',
                'members' => $boardMembers,
            ],
            'canManage' => auth()->user()?->hasPermissionTo('governance.settings.manage') ?? false,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $payload = $request->input('settings', []);
        if (! is_array($payload)) {
            $payload = [];
        }

        $allowedKeys = collect($this->definitions)->pluck('key')->toArray();
        $changes = 0;

        foreach ($payload as $key => $value) {
            if (! in_array($key, $allowedKeys, true)) {
                continue;
            }

            $def = collect($this->definitions)->firstWhere('key', $key);
            $category = $def['category'] ?? GovernanceSetting::CATEGORY_GENERAL;
            $description = $def['description'] ?? null;
            $type = $def['type'] ?? 'string';

            if ($type === 'number' && $value !== null && $value !== '') {
                if (! is_numeric($value)) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        "settings.{$key}" => "The setting '{$def['label']}' must be a valid number.",
                    ]);
                }
                $value = is_float($value + 0) ? (float) $value : (int) $value;
            } elseif ($type === 'boolean' && $value !== null) {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            }

            GovernanceSetting::set($key, $value, $category, $description);
            $changes++;
        }

        if ($changes > 0) {
            GovernanceAuditService::log('settings.updated', 'GovernanceSetting', 0, [
                'changed_keys' => array_keys($payload),
                'change_count' => $changes,
            ]);
        }

        return back()->with('success', "Updated {$changes} setting(s).");
    }

    public function updateRules(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'legal_form' => 'required|string|max:100',
            'governing_document_reference' => 'nullable|string|max:255',
            'governing_document_version' => 'nullable|string|max:50',
            'quorum_mode' => 'required|string|in:majority_floor_plus_one,percentage,fixed_count',
            'quorum_formula' => 'required|string|max:100',
            'ordinary_threshold_formula' => 'required|string|max:255',
            'unanimous_denominator_formula' => 'required|string|max:255',
            'written_voting_permitted' => 'boolean',
            'written_unanimity_required' => 'boolean',
            'recusal_policy' => 'required|string|max:255',
        ]);

        $profileService = app(\App\Domain\Governance\Services\GovernanceVotingProfileService::class);
        $profile = $profileService->getOrCreateCandidateDefault('board');

        if ($profile->is_active || $profile->approved_at) {
            $profile = \App\Domain\Governance\Models\GovernanceVotingProfile::create([
                ...$profile->toArray(),
                ...$validated,
                'id' => null,
                'is_active' => false,
                'approved_at' => null,
                'approved_by_user_id' => null,
                'approved_by_resolution_id' => null,
                'effective_from' => null,
                'effective_to' => null,
                'created_by' => auth()->id(),
            ]);
        } else {
            $profile->update($validated);
        }

        GovernanceAuditService::log('governance_rules.updated', 'GovernanceVotingProfile', $profile->id, [
            'updated_by' => auth()->id(),
            'fields' => array_keys($validated),
        ]);

        return back()->with('success', 'Governance voting rules updated.');
    }

    public function activateRules(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'governing_document_reference' => 'required|string|min:3',
            'governing_document_version' => 'nullable|string|max:50',
            'approved_by_resolution_id' => 'nullable|exists:resolutions,id',
        ]);

        $profileService = app(\App\Domain\Governance\Services\GovernanceVotingProfileService::class);
        $profile = $profileService->getOrCreateCandidateDefault('board');

        try {
            $resolution = !empty($validated['approved_by_resolution_id'])
                ? \App\Domain\Governance\Models\Resolution::find($validated['approved_by_resolution_id'])
                : null;

            $profileService->activateProfile(
                $profile,
                auth()->user(),
                $resolution,
                $validated['governing_document_reference'],
                $validated['governing_document_version'] ?? null
            );

            GovernanceAuditService::log('governance_rules.activated', 'GovernanceVotingProfile', $profile->id, [
                'activated_by' => auth()->id(),
                'document_reference' => $validated['governing_document_reference'],
            ]);

            return back()->with('success', 'Governance voting profile successfully activated.');
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
