<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Models\FinMatchRule;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MatchRuleController extends Controller
{
    /**
     * List all match rules.
     */
    public function index(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $rules = FinMatchRule::forOrganization($orgId)
            ->with('createdBy:id,name')
            ->byPriority()
            ->get()
            ->map(fn(FinMatchRule $rule) => [
                'id' => $rule->id,
                'name' => $rule->name,
                'priority' => $rule->priority,
                'rule_type' => $rule->rule_type,
                'conditions' => $rule->conditions,
                'auto_confirm_threshold' => (float) $rule->auto_confirm_threshold,
                'is_active' => $rule->is_active,
                'match_count' => $rule->match_count,
                'created_by_name' => $rule->createdBy?->name,
                'created_at' => $rule->created_at->format('Y-m-d'),
            ]);

        return Inertia::render('finance/match-rules/Index', [
            'rules' => $rules,
        ]);
    }

    /**
     * Create a new match rule.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'priority' => ['integer', 'min:0'],
            'rule_type' => ['required', 'in:exact_amount,reference_match,vendor_pattern,recurring_pattern,amount_tolerance'],
            'conditions' => ['nullable', 'array'],
            'auto_confirm_threshold' => ['numeric', 'min:0', 'max:100'],
            'is_active' => ['boolean'],
        ]);

        $orgId = $request->user()->organization_id;

        FinMatchRule::create([
            'organization_id' => $orgId,
            'name' => $validated['name'],
            'priority' => $validated['priority'] ?? 0,
            'rule_type' => $validated['rule_type'],
            'conditions' => $validated['conditions'] ?? [],
            'auto_confirm_threshold' => $validated['auto_confirm_threshold'] ?? 95.00,
            'is_active' => $validated['is_active'] ?? true,
            'created_by' => $request->user()->id,
        ]);

        return redirect()->back()
            ->with('success', 'Match rule created.');
    }

    /**
     * Update a match rule.
     */
    public function update(Request $request, FinMatchRule $rule)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'priority' => ['integer', 'min:0'],
            'rule_type' => ['required', 'in:exact_amount,reference_match,vendor_pattern,recurring_pattern,amount_tolerance'],
            'conditions' => ['nullable', 'array'],
            'auto_confirm_threshold' => ['numeric', 'min:0', 'max:100'],
            'is_active' => ['boolean'],
        ]);

        $rule->update([
            'name' => $validated['name'],
            'priority' => $validated['priority'] ?? $rule->priority,
            'rule_type' => $validated['rule_type'],
            'conditions' => $validated['conditions'] ?? $rule->conditions,
            'auto_confirm_threshold' => $validated['auto_confirm_threshold'] ?? $rule->auto_confirm_threshold,
            'is_active' => $validated['is_active'] ?? $rule->is_active,
        ]);

        return redirect()->back()
            ->with('success', 'Match rule updated.');
    }

    /**
     * Delete a match rule (soft delete).
     */
    public function destroy(FinMatchRule $rule)
    {
        $rule->delete();

        return redirect()->back()
            ->with('success', 'Match rule deleted.');
    }
}
