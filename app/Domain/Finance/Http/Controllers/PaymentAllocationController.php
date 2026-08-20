<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Models\FinPaymentAllocation;
use App\Domain\Finance\Services\PaymentSettlementSiteScope;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PaymentAllocationController extends Controller
{
    public function __construct(
        private readonly PaymentSettlementSiteScope $siteScope,
    ) {}

    public function index(Request $request)
    {
        $orgId = $request->user()->organization_id;
        $permittedTypes = collect([
            $request->user()->canDo('finance.ap.view') ? 'payable' : null,
            $request->user()->canDo('finance.ar.view') ? 'receivable' : null,
        ])->filter()->values()->all();

        $query = $this->siteScope->applyAllocationScope(
            FinPaymentAllocation::forOrganization($orgId)->whereIn('type', $permittedTypes),
            $request->user(),
        );

        $legacyReviewQuery = (clone $query)->requiresLegacyReview();
        $legacyReviewCount = (clone $legacyReviewQuery)->count();
        $legacyReview = [
            'state' => $legacyReviewCount > 0 ? 'review_required' : 'clear',
            'count' => $legacyReviewCount,
            'total_amount' => (float) (clone $legacyReviewQuery)->sum('amount'),
            'correction_policy' => 'journal_backed_correction_only',
        ];

        $query->with('allocatable')->orderByDesc('payment_date');

        if ($request->filled('type')) {
            $query->whereIn('type', array_intersect(
                [$request->input('type')],
                $permittedTypes,
            ));
        }

        $allocations = $query->paginate(20)->through(fn (FinPaymentAllocation $alloc) => [
            'id' => $alloc->id,
            'type' => $alloc->type,
            'payment_date' => $alloc->payment_date->toDateString(),
            'amount' => (float) $alloc->amount,
            'allocatable_type' => class_basename($alloc->allocatable_type ?? ''),
            'allocatable_id' => $alloc->allocatable_id,
            'notes' => $alloc->notes,
            'created_at' => $alloc->created_at->toDateTimeString(),
            'review_state' => $alloc->requiresLegacyReview()
                ? 'review_required'
                : 'traceable',
        ]);

        return Inertia::render('finance/payment-allocations/Index', [
            'allocations' => $allocations,
            'filters' => [
                'type' => $request->input('type', ''),
            ],
            'legacyReview' => $legacyReview,
        ]);
    }
}
