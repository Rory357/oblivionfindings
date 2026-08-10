<?php

namespace App\Http\Controllers\Sites;

use App\Domain\Finance\Services\AccountsPayableService;
use App\Domain\Finance\Services\AccountsReceivableService;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteDamage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class SiteDamageController extends Controller
{
    private const FINANCE_APPLICATION_CONTEXT = 1;

    public function __construct(
        private AccountsPayableService $accountsPayable,
        private AccountsReceivableService $accountsReceivable,
    ) {}

    public function index(Request $request, Site $site)
    {
        $this->authorize('view', $site);
        $this->authorize('viewAny', SiteDamage::class);

        $damages = $site->damages()
            ->with(['reportedBy:id,name', 'assignedTo:id,name'])
            ->orderByDesc('damage_date')
            ->get();

        return Inertia::render('sites/damages/index', [
            'site' => $site,
            'damages' => $damages,
            'canCreate' => $request->user()->canDo('sites.damages.create'),
            'canManage' => $request->user()->canDo('sites.damages.manage'),
        ]);
    }

    public function store(Request $request, Site $site)
    {
        $this->authorize('view', $site);
        $this->authorize('create', SiteDamage::class);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'location_in_site' => ['nullable', 'string', 'max:255'],
            'severity' => ['required', 'string', 'in:minor,moderate,major,critical'],
            'damage_date' => ['required', 'date'],
            'discovered_date' => ['required', 'date'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0'],
            'insurance_claim_ref' => ['nullable', 'string', 'max:255'],
            'insurance_status' => ['nullable', 'string', 'in:not_applicable,pending,submitted,approved,declined'],
            'photos' => ['nullable', 'array'],
        ]);

        $data['reported_by'] = $request->user()->id;
        $data['status'] = 'reported';
        $data['insurance_status'] = $data['insurance_status'] ?? 'not_applicable';

        $site->damages()->create($data);

        return redirect()->back()->with('success', 'Damage report created.');
    }

    public function update(Request $request, Site $site, SiteDamage $damage)
    {
        abort_unless($damage->site_id === $site->id, 404);
        $this->authorize('view', $site);
        $this->authorize('update', $damage);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'string'],
            'location_in_site' => ['nullable', 'string', 'max:255'],
            'severity' => ['sometimes', 'string', 'in:minor,moderate,major,critical'],
            'status' => ['sometimes', 'string', 'in:reported,assessed,repair_scheduled,repair_in_progress,repaired,closed'],
            'assigned_to' => ['nullable', 'exists:users,id'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0'],
            'actual_cost' => ['nullable', 'numeric', 'min:0'],
            'insurance_claim_ref' => ['nullable', 'string', 'max:255'],
            'insurance_status' => ['nullable', 'string', 'in:not_applicable,pending,submitted,approved,declined'],
            'repair_notes' => ['nullable', 'string'],
        ]);

        if (array_key_exists('insurance_status', $data) && $data['insurance_status'] === null) {
            $data['insurance_status'] = 'not_applicable';
        }

        // If marking as repaired, set repaired_at and repaired_by
        if (($data['status'] ?? null) === 'repaired' && ! $damage->repaired_at) {
            $data['repaired_at'] = now();
            $data['repaired_by'] = $request->user()->id;
        }

        $damage->update($data);

        // Capture-at-source: a repaired damage with an actual cost becomes a draft
        // accounts-payable bill for the repair, and an approved insurance claim
        // becomes a draft receivable invoice to the insurer. Both idempotent and
        // non-fatal — never block the operational update. (fresh() can be null if
        // the row was deleted concurrently — skip quietly rather than fatal.)
        if ($fresh = $damage->fresh()) {
            $this->captureRepairBill($site, $fresh);
            $this->captureInsuranceInvoice($site, $fresh);
        }

        return redirect()->back()->with('success', 'Damage report updated.');
    }

    /**
     * Post an approved insurance claim as a DRAFT receivable invoice (billed to
     * the insurer, GL 4230 Insurance Recoveries). Amount = actual cost, falling
     * back to the estimate while the repair is unpriced. Zero-rated: the claim
     * amount is recovered as-is, mirroring the gst-0 repair bill so the recovery
     * offsets the expense 1:1. Idempotent on the SiteDamage source.
     */
    private function captureInsuranceInvoice(Site $site, SiteDamage $damage): void
    {
        $amount = (float) ($damage->actual_cost ?: $damage->estimated_cost);

        if ($damage->insurance_status !== 'approved' || $amount <= 0) {
            return;
        }

        try {
            $claimRef = $damage->insurance_claim_ref;
            $this->accountsReceivable->captureOperationalInvoice(self::FINANCE_APPLICATION_CONTEXT, [
                'source_type' => SiteDamage::class,
                'source_id' => $damage->id,
                'client_name' => $claimRef ? "Insurance — claim {$claimRef}" : 'Insurance claim',
                'funding_body' => 'Insurance',
                'description' => "Insurance recovery — {$damage->title} @ {$site->name}",
                'quantity' => 1,
                'unit_price' => $amount,
                'gst_rate' => 0,
                'revenue_account_code' => config('finance.capture.insurance_revenue_account', '4230'),
                'notes' => "Auto-captured from damage report #{$damage->id}"
                    .($claimRef ? " (claim {$claimRef})" : '').'.',
            ]);
        } catch (\Throwable $e) {
            Log::error("Insurance invoice capture failed for damage #{$damage->id}: {$e->getMessage()}");
        }
    }

    /**
     * Post the repair cost of a repaired damage to accounts payable as a draft
     * bill (against the application's Property Repairs vendor, GL 6420). The finance
     * service is idempotent on the "DAMAGE-{id}" reference, so this can run on
     * every update without duplicating.
     */
    private function captureRepairBill(Site $site, SiteDamage $damage): void
    {
        if ($damage->status !== 'repaired' || (float) $damage->actual_cost <= 0) {
            return;
        }

        try {
            $this->accountsPayable->captureOperationalBill(self::FINANCE_APPLICATION_CONTEXT, [
                'reference' => "DAMAGE-{$damage->id}",
                'vendor_name' => config('finance.capture.damage_repair_vendor', 'Property Repairs'),
                'vendor_type' => 'contractor',
                'description' => "Repair — {$damage->title} @ {$site->name}",
                'amount' => (float) $damage->actual_cost,
                'account_code' => config('finance.capture.damage_repair_account', '6420'),
                // actual_cost is a single figure with no GST breakdown; record it as the
                // expense/payable as-is (bill approval lumps any line GST into the expense).
                'gst_rate' => 0,
                'notes' => "Auto-captured from damage report #{$damage->id}.",
            ]);
        } catch (\Throwable $e) {
            Log::error("Damage repair bill capture failed for damage #{$damage->id}: {$e->getMessage()}");
        }
    }

    public function destroy(Request $request, Site $site, SiteDamage $damage)
    {
        abort_unless($damage->site_id === $site->id, 404);
        $this->authorize('view', $site);
        $this->authorize('delete', $damage);

        $damage->delete();

        return redirect()->back()->with('success', 'Damage report removed.');
    }
}
