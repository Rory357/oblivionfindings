<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinVendor;
use App\Domain\Finance\Http\Requests\StoreVendorRequest;
use App\Domain\Finance\Http\Requests\UpdateVendorRequest;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class VendorController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', FinVendor::class);

        $orgId = $request->user()->organization_id;

        $vendors = FinVendor::query()
            ->forOrganization($orgId)
            ->withCount('bills')
            ->when($request->search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($request->vendor_type, function ($query, $type) {
                $query->where('vendor_type', $type);
            })
            ->when($request->has('is_active') && $request->is_active !== '', function ($query) use ($request) {
                $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
            })
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('finance/vendors/Index', [
            'vendors' => $vendors,
            'filters' => [
                'search' => $request->search ?? '',
                'vendor_type' => $request->vendor_type ?? '',
                'is_active' => $request->is_active ?? '',
            ],
        ]);
    }

    public function create(Request $request)
    {
        $this->authorize('create', FinVendor::class);

        $orgId = $request->user()->organization_id;

        $expenseAccounts = FinAccount::query()
            ->forOrganization($orgId)
            ->ofType('expense')
            ->active()
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        return Inertia::render('finance/vendors/Create', [
            'expenseAccounts' => $expenseAccounts,
        ]);
    }

    public function store(StoreVendorRequest $request)
    {
        $validated = $request->validated();

        $vendor = FinVendor::create([
            'organization_id' => $request->user()->organization_id,
            'name' => $validated['name'],
            'trading_name' => $validated['trading_name'] ?? null,
            'vendor_type' => $validated['vendor_type'],
            'gst_number' => $validated['gst_number'] ?? null,
            'bank_account_number' => $validated['bank_account_number'] ?? null,
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'address_line_1' => $validated['address_line_1'] ?? null,
            'address_line_2' => $validated['address_line_2'] ?? null,
            'city' => $validated['city'] ?? null,
            'region' => $validated['region'] ?? null,
            'postal_code' => $validated['postal_code'] ?? null,
            'payment_terms_days' => $validated['payment_terms_days'] ?? null,
            'default_expense_account_id' => $validated['default_expense_account_id'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'is_active' => true,
            'created_by' => $request->user()->id,
        ]);

        if (! empty($validated['contacts'])) {
            foreach ($validated['contacts'] as $contact) {
                $vendor->contacts()->create([
                    'name' => $contact['name'],
                    'role' => $contact['role'] ?? null,
                    'email' => $contact['email'] ?? null,
                    'phone' => $contact['phone'] ?? null,
                    'is_primary' => $contact['is_primary'] ?? false,
                ]);
            }
        }

        return redirect()->route('finance.vendors.show', $vendor)
            ->with('success', 'Vendor created successfully.');
    }

    public function show(Request $request, FinVendor $vendor)
    {
        $this->authorize('view', $vendor);

        $vendor->load('contacts');

        $bills = $vendor->bills()
            ->select('id', 'bill_number', 'bill_date', 'due_date', 'total_amount', 'amount_paid', 'status')
            ->orderByDesc('bill_date')
            ->limit(10)
            ->get();

        $purchaseOrders = $vendor->purchaseOrders()
            ->select('id', 'po_number', 'order_date', 'total_amount', 'status')
            ->orderByDesc('order_date')
            ->limit(10)
            ->get();

        $totalOutstanding = $vendor->bills()
            ->whereNotIn('status', ['paid', 'cancelled'])
            ->selectRaw('COALESCE(SUM(total_amount - amount_paid), 0) as total')
            ->value('total');

        $startOfYear = Carbon::now()->startOfYear();
        $totalPaidYtd = $vendor->bills()
            ->where('status', 'paid')
            ->where('bill_date', '>=', $startOfYear)
            ->selectRaw('COALESCE(SUM(amount_paid), 0) as total')
            ->value('total');

        return Inertia::render('finance/vendors/Show', [
            'vendor' => $vendor,
            'bills' => $bills,
            'purchaseOrders' => $purchaseOrders,
            'totalOutstanding' => (float) $totalOutstanding,
            'totalPaidYtd' => (float) $totalPaidYtd,
        ]);
    }

    public function edit(Request $request, FinVendor $vendor)
    {
        $this->authorize('update', $vendor);

        $vendor->load('contacts');

        $orgId = $request->user()->organization_id;

        $expenseAccounts = FinAccount::query()
            ->forOrganization($orgId)
            ->ofType('expense')
            ->active()
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        return Inertia::render('finance/vendors/Edit', [
            'vendor' => $vendor,
            'expenseAccounts' => $expenseAccounts,
        ]);
    }

    public function update(UpdateVendorRequest $request, FinVendor $vendor)
    {
        $validated = $request->validated();

        $vendor->update([
            'name' => $validated['name'],
            'trading_name' => $validated['trading_name'] ?? null,
            'vendor_type' => $validated['vendor_type'],
            'gst_number' => $validated['gst_number'] ?? null,
            'bank_account_number' => $validated['bank_account_number'] ?? null,
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'address_line_1' => $validated['address_line_1'] ?? null,
            'address_line_2' => $validated['address_line_2'] ?? null,
            'city' => $validated['city'] ?? null,
            'region' => $validated['region'] ?? null,
            'postal_code' => $validated['postal_code'] ?? null,
            'payment_terms_days' => $validated['payment_terms_days'] ?? null,
            'default_expense_account_id' => $validated['default_expense_account_id'] ?? null,
            'is_active' => $validated['is_active'] ?? $vendor->is_active,
            'notes' => $validated['notes'] ?? null,
        ]);

        // Sync contacts: delete removed, update existing, create new
        $contacts = $validated['contacts'] ?? [];
        $incomingIds = collect($contacts)->pluck('id')->filter()->all();

        // Delete contacts not in the incoming list
        $vendor->contacts()->whereNotIn('id', $incomingIds)->delete();

        foreach ($contacts as $contactData) {
            if (! empty($contactData['id'])) {
                // Update existing
                $vendor->contacts()->where('id', $contactData['id'])->update([
                    'name' => $contactData['name'],
                    'role' => $contactData['role'] ?? null,
                    'email' => $contactData['email'] ?? null,
                    'phone' => $contactData['phone'] ?? null,
                    'is_primary' => $contactData['is_primary'] ?? false,
                ]);
            } else {
                // Create new
                $vendor->contacts()->create([
                    'name' => $contactData['name'],
                    'role' => $contactData['role'] ?? null,
                    'email' => $contactData['email'] ?? null,
                    'phone' => $contactData['phone'] ?? null,
                    'is_primary' => $contactData['is_primary'] ?? false,
                ]);
            }
        }

        return redirect()->route('finance.vendors.show', $vendor)
            ->with('success', 'Vendor updated successfully.');
    }
}
