<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinFundingStream;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FundingStreamController extends Controller
{
    public function index(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $fundingStreams = FinFundingStream::forOrganization($orgId)
            ->with('defaultRevenueAccount:id,code,name')
            ->orderBy('code')
            ->get()
            ->map(fn ($fs) => [
                'id' => $fs->id,
                'code' => $fs->code,
                'name' => $fs->name,
                'funder_type' => $fs->funder_type,
                'contact_name' => $fs->contact_name,
                'contact_email' => $fs->contact_email,
                'default_revenue_account_id' => $fs->default_revenue_account_id,
                'default_revenue_account' => $fs->defaultRevenueAccount ? [
                    'id' => $fs->defaultRevenueAccount->id,
                    'code' => $fs->defaultRevenueAccount->code,
                    'name' => $fs->defaultRevenueAccount->name,
                ] : null,
                'is_active' => $fs->is_active,
            ]);

        $revenueAccounts = FinAccount::forOrganization($orgId)
            ->active()
            ->ofType('revenue')
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        return Inertia::render('finance/funding-streams/Index', [
            'fundingStreams' => $fundingStreams,
            'revenueAccounts' => $revenueAccounts,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:20',
            'name' => 'required|string|max:255',
            'funder_type' => 'nullable|string|max:50',
            'contact_name' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'default_revenue_account_id' => 'nullable|exists:fin_accounts,id',
            'is_active' => 'boolean',
        ]);

        $orgId = $request->user()->organization_id;

        // Check code uniqueness per org
        $exists = FinFundingStream::forOrganization($orgId)
            ->where('code', $validated['code'])
            ->exists();

        if ($exists) {
            return back()->withErrors(['code' => 'A funding stream with this code already exists.']);
        }

        FinFundingStream::create(array_merge($validated, [
            'organization_id' => $orgId,
            'created_by' => $request->user()->id,
        ]));

        return redirect()->route('finance.funding-streams.index')
            ->with('success', 'Funding stream created successfully.');
    }

    public function update(Request $request, FinFundingStream $fundingStream)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:20',
            'name' => 'required|string|max:255',
            'funder_type' => 'nullable|string|max:50',
            'contact_name' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'default_revenue_account_id' => 'nullable|exists:fin_accounts,id',
            'is_active' => 'boolean',
        ]);

        // Check code uniqueness per org (excluding self)
        $exists = FinFundingStream::forOrganization($fundingStream->organization_id)
            ->where('code', $validated['code'])
            ->where('id', '!=', $fundingStream->id)
            ->exists();

        if ($exists) {
            return back()->withErrors(['code' => 'A funding stream with this code already exists.']);
        }

        $fundingStream->update($validated);

        return redirect()->route('finance.funding-streams.index')
            ->with('success', 'Funding stream updated successfully.');
    }

    public function destroy(Request $request, FinFundingStream $fundingStream)
    {
        $fundingStream->delete();

        return redirect()->route('finance.funding-streams.index')
            ->with('success', 'Funding stream deleted successfully.');
    }
}
