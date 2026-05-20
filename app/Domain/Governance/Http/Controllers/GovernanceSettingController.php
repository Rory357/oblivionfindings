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

        return Inertia::render('Governance/Settings/Index', [
            'settings' => $settings,
            'categories' => [
                GovernanceSetting::CATEGORY_SPEND_APPROVAL => 'Spend approval thresholds',
                GovernanceSetting::CATEGORY_ESCALATION => 'Escalation',
                GovernanceSetting::CATEGORY_GENERAL => 'General',
            ],
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
}
