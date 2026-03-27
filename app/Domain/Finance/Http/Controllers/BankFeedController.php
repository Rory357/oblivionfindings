<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Models\FinBankAccount;
use App\Domain\Finance\Models\FinBankFeed;
use App\Domain\Finance\Services\BankFeedProviderFactory;
use App\Domain\Finance\Services\BankFeedService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BankFeedController extends Controller
{
    public function __construct(
        private BankFeedService $service,
    ) {}

    public function index(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $feeds = FinBankFeed::forOrganization($orgId)
            ->with('bankAccount:id,name,bank_name')
            ->with('createdBy:id,name')
            ->withCount('logs')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (FinBankFeed $feed) => [
                'id' => $feed->id,
                'bank_account_id' => $feed->bank_account_id,
                'bank_account_name' => $feed->bankAccount?->name,
                'bank_name' => $feed->bankAccount?->bank_name,
                'provider' => $feed->provider,
                'is_active' => $feed->is_active,
                'last_sync_at' => $feed->last_sync_at?->format('Y-m-d H:i'),
                'last_sync_status' => $feed->last_sync_status,
                'last_error' => $feed->last_error,
                'consent_expires_at' => $feed->consent_expires_at?->format('Y-m-d'),
                'sync_from_date' => $feed->sync_from_date?->format('Y-m-d'),
                'logs_count' => $feed->logs_count,
                'created_by_name' => $feed->createdBy?->name,
                'created_at' => $feed->created_at->format('Y-m-d'),
            ]);

        $bankAccounts = FinBankAccount::forOrganization($orgId)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'bank_name']);

        $existingAccountIds = FinBankFeed::forOrganization($orgId)
            ->pluck('bank_account_id')
            ->toArray();

        return Inertia::render('finance/bank-feeds/Index', [
            'feeds' => $feeds,
            'bankAccounts' => $bankAccounts,
            'existingAccountIds' => $existingAccountIds,
        ]);
    }

    public function store(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $validated = $request->validate([
            'bank_account_id' => ['required', 'exists:fin_bank_accounts,id'],
            'provider' => ['required', 'in:asb,anz,westpac,bnz'],
            'sync_from_date' => ['nullable', 'date'],
        ]);

        $exists = FinBankFeed::where('organization_id', $orgId)
            ->where('bank_account_id', $validated['bank_account_id'])
            ->exists();

        if ($exists) {
            return redirect()->back()
                ->withErrors(['bank_account_id' => 'A bank feed already exists for this account.']);
        }

        FinBankFeed::create([
            'organization_id' => $orgId,
            'bank_account_id' => $validated['bank_account_id'],
            'provider' => $validated['provider'],
            'sync_from_date' => $validated['sync_from_date'] ?? null,
            'is_active' => true,
            'last_sync_status' => 'pending',
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('finance.bank-feeds.index')
            ->with('success', 'Bank feed connection created.');
    }

    public function sync(Request $request, FinBankFeed $feed)
    {
        $orgId = $request->user()->organization_id;

        if ($feed->organization_id !== $orgId) {
            abort(403);
        }

        $log = $this->service->syncFeed($feed);

        $message = $log->status === 'failed'
            ? "Sync failed: {$log->error_message}"
            : "Sync completed. {$log->transactions_imported} imported, {$log->transactions_skipped} skipped.";

        return redirect()->back()
            ->with($log->status === 'failed' ? 'error' : 'success', $message);
    }

    public function syncAll(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $logs = $this->service->syncAllActive($orgId);

        $successful = collect($logs)->where('status', '!=', 'failed')->count();
        $failed = collect($logs)->where('status', 'failed')->count();
        $total = count($logs);

        return redirect()->back()
            ->with('success', "Synced {$total} feed(s): {$successful} successful, {$failed} failed.");
    }

    public function destroy(Request $request, FinBankFeed $feed)
    {
        $orgId = $request->user()->organization_id;

        if ($feed->organization_id !== $orgId) {
            abort(403);
        }

        try {
            $providerFactory = app(BankFeedProviderFactory::class);
            $provider = $providerFactory->make($feed->provider);
            $provider->revokeConsent($feed);
        } catch (\Throwable $e) {
            // Consent revocation is best-effort
        }

        $feed->update(['is_active' => false]);
        $feed->delete();

        return redirect()->route('finance.bank-feeds.index')
            ->with('success', 'Bank feed disconnected.');
    }

    public function logs(Request $request, FinBankFeed $feed)
    {
        $orgId = $request->user()->organization_id;

        if ($feed->organization_id !== $orgId) {
            abort(403);
        }

        $feed->load('bankAccount:id,name,bank_name');

        $logs = $feed->logs()
            ->orderByDesc('synced_at')
            ->paginate(25)
            ->through(fn ($log) => [
                'id' => $log->id,
                'synced_at' => $log->synced_at->format('Y-m-d H:i:s'),
                'status' => $log->status,
                'transactions_fetched' => $log->transactions_fetched,
                'transactions_imported' => $log->transactions_imported,
                'transactions_skipped' => $log->transactions_skipped,
                'error_message' => $log->error_message,
                'duration_ms' => $log->duration_ms,
            ]);

        return Inertia::render('finance/bank-feeds/Logs', [
            'feed' => [
                'id' => $feed->id,
                'provider' => $feed->provider,
                'bank_account_name' => $feed->bankAccount?->name,
                'bank_name' => $feed->bankAccount?->bank_name,
            ],
            'logs' => $logs,
        ]);
    }
}
