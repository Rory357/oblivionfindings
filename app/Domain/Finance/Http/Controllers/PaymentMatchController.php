<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Models\FinBankTransaction;
use App\Domain\Finance\Models\FinPaymentMatch;
use App\Domain\Finance\Services\PaymentMatchingService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PaymentMatchController extends Controller
{
    public function __construct(
        private PaymentMatchingService $service,
    ) {}

    /**
     * List payment matches with filters.
     */
    public function index(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $matches = $this->service->scopeMatchesForActor(
            FinPaymentMatch::forOrganization($orgId),
            $request->user(),
        )
            ->with([
                'bankTransaction:id,bank_account_id,transaction_date,description,reference,amount',
                'bankTransaction.bankAccount:id,name',
                'confirmedBy:id,name',
            ])
            ->when($request->status, function ($q, $status) {
                if ($status === 'all_confirmed') {
                    $q->whereIn('status', ['confirmed', 'auto_confirmed']);
                } else {
                    $q->where('status', $status);
                }
            })
            ->when($request->min_confidence, function ($q, $min) {
                $q->where('confidence_score', '>=', (float) $min);
            })
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        $matches->getCollection()->transform(function (FinPaymentMatch $match) {
            $matchable = $match->matchable;
            $matchableData = null;

            if ($matchable) {
                $matchableData = [
                    'id' => $matchable->id,
                    'type' => class_basename($matchable),
                    'number' => $matchable->bill_number ?? $matchable->invoice_number ?? '-',
                    'amount_due' => (float) ($matchable->total_amount ?? 0) - (float) ($matchable->amount_paid ?? 0),
                    'total_amount' => (float) ($matchable->total_amount ?? 0),
                    'vendor_name' => $matchable->vendor?->name ?? null,
                    'due_date' => $matchable->due_date?->format('Y-m-d') ?? null,
                ];
            }

            return [
                'id' => $match->id,
                'bank_transaction' => $match->bankTransaction ? [
                    'id' => $match->bankTransaction->id,
                    'transaction_date' => $match->bankTransaction->transaction_date->format('Y-m-d'),
                    'description' => $match->bankTransaction->description,
                    'reference' => $match->bankTransaction->reference,
                    'amount' => (float) $match->bankTransaction->amount,
                    'bank_account_name' => $match->bankTransaction->bankAccount?->name,
                ] : null,
                'matchable' => $matchableData,
                'confidence_score' => (float) $match->confidence_score,
                'match_reasons' => $match->match_reasons ?? [],
                'status' => $match->status,
                'confirmed_by_name' => $match->confirmedBy?->name,
                'confirmed_at' => $match->confirmed_at?->format('Y-m-d H:i'),
                'created_at' => $match->created_at->format('Y-m-d H:i'),
            ];
        });

        return Inertia::render('finance/payment-matching/Index', [
            'matches' => $matches,
            'filters' => [
                'status' => $request->status ?? '',
                'min_confidence' => $request->min_confidence ?? '',
            ],
        ]);
    }

    /**
     * Run matching for a specific bank transaction.
     */
    public function suggest(Request $request, FinBankTransaction $transaction)
    {
        $orgId = $request->user()->organization_id;
        $created = $this->service->suggestForTransaction($orgId, $transaction, $request->user());

        return redirect()->back()
            ->with('success', "{$created} potential match(es) found for transaction.");
    }

    /**
     * Run matching for all unmatched transactions.
     */
    public function matchAll(Request $request)
    {
        $orgId = $request->user()->organization_id;
        $results = $this->service->matchUnmatchedTransactions($orgId, $request->user());

        $message = "Matching complete: {$results['matched']} match(es) found";
        if ($results['auto_confirmed'] > 0) {
            $message .= ", {$results['auto_confirmed']} auto-confirmed";
        }
        if ($results['suggested'] > 0) {
            $message .= ", {$results['suggested']} awaiting review";
        }

        return redirect()->back()->with('success', $message.'.');
    }

    /**
     * Confirm a suggested match.
     */
    public function confirm(Request $request, FinPaymentMatch $match)
    {
        try {
            $this->service->confirmMatch($match, $request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['match' => $e->getMessage()]);
        }

        return redirect()->back()
            ->with('success', 'Payment match confirmed.');
    }

    /**
     * Reject a suggested match.
     */
    public function reject(Request $request, FinPaymentMatch $match)
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->service->rejectMatch($match, $request->user(), $data['reason'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['match' => $e->getMessage()]);
        }

        return redirect()->back()
            ->with('success', 'Payment match rejected.');
    }
}
