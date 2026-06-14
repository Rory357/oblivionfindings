<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrBonusPayment;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class BonusController extends Controller
{
    use ResolvesHrTenant;

    /**
     * List bonus payments with filters.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compensation.view'), 403);

        $bonuses = HrBonusPayment::query()
            ->with([
                'employeeProfile.user:id,name,email',
                'approver:id,name',
                'creator:id,name',
            ])
            ->when($request->input('bonus_type'), fn ($q, $v) => $q->where('bonus_type', $v))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('date_from'), fn ($q, $v) => $q->where('payment_date', '>=', $v))
            ->when($request->input('date_to'), fn ($q, $v) => $q->where('payment_date', '<=', $v))
            ->orderByDesc('payment_date')
            ->paginate(20)
            ->withQueryString();

        $bonuses->through(fn ($bonus) => [
            'id' => $bonus->id,
            'employee_name' => $bonus->employeeProfile?->user?->name ?? 'Unknown',
            'employee_profile_id' => $bonus->employee_profile_id,
            'bonus_type' => $bonus->bonus_type,
            'amount' => $bonus->amount,
            'currency' => $bonus->currency,
            'reason' => $bonus->reason,
            'payment_date' => $bonus->payment_date?->toDateString(),
            'status' => $bonus->status,
            'approved_by' => $bonus->approver?->name,
            'approved_at' => $bonus->approved_at?->toDateTimeString(),
            'created_by' => $bonus->creator?->name,
            'created_at' => $bonus->created_at?->toDateTimeString(),
        ]);

        $employees = HrEmployeeProfile::where('is_active', true)
            ->with('user:id,name,email')
            ->orderBy('user_id')
            ->get(['id', 'user_id', 'position_title', 'department']);

        return Inertia::render('hr/compensation/bonuses', [
            'bonuses' => $bonuses,
            'employees' => $employees,
            'filters' => $request->only(['bonus_type', 'status', 'date_from', 'date_to']),
            'can' => [
                'manage' => $user->canDo('hr.compensation.manage'),
            ],
        ]);
    }

    /**
     * Store a new bonus payment.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compensation.manage'), 403);

        $data = $request->validate([
            'employee_profile_id' => ['required', 'integer', 'exists:hr_employee_profiles,id'],
            'bonus_type' => ['required', Rule::in(['performance', 'signing', 'retention', 'spot', 'holiday', 'other'])],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
            'currency' => ['sometimes', 'string', 'max:3'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'payment_date' => ['required', 'date'],
        ]);

        HrBonusPayment::create([
            'tenant_id' => $this->resolveHrTenantIdForUser($user),
            'employee_profile_id' => $data['employee_profile_id'],
            'bonus_type' => $data['bonus_type'],
            'amount' => $data['amount'],
            'currency' => $data['currency'] ?? 'NZD',
            'reason' => $data['reason'] ?? null,
            'payment_date' => $data['payment_date'],
            'status' => 'pending',
            'created_by' => $user->id,
        ]);

        return redirect()->back()->with('success', 'Bonus payment created.');
    }

    /**
     * Approve a bonus payment.
     */
    public function approve(Request $request, HrBonusPayment $bonus)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compensation.manage'), 403);

        if ($bonus->status !== 'pending') {
            return redirect()->back()->with('error', 'Only pending bonuses can be approved.');
        }

        $bonus->update([
            'status' => 'approved',
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Bonus payment approved.');
    }
}
