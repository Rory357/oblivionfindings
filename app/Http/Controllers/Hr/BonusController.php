<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrBonusPayment;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Notifications\BonusStatusNotification;
use App\Domain\Hr\Services\CompensationService;
use App\Domain\Hr\Services\HrPerformanceAccessService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class BonusController extends Controller
{
    public function __construct(
        protected CompensationService $compensationService,
        private readonly HrPerformanceAccessService $access,
    ) {}

    /**
     * List bonus payments with filters.
     */
    public function index(Request $request)
    {
        $user = $this->viewer($request);

        $bonuses = $this->access
            ->applyBonusScope(HrBonusPayment::query(), $user)
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

        $employees = $user->canDo('hr.compensation.manage')
            ? $this->access
                ->applyCurrentProfileScope(HrEmployeeProfile::query(), $user)
                ->with('user:id,name,email')
                ->orderBy('user_id')
                ->get(['id', 'user_id', 'position_title', 'department'])
            : [];

        return Inertia::render('hr/compensation/bonuses', [
            'bonuses' => $bonuses,
            'employees' => $employees,
            'filters' => $request->only(['bonus_type', 'status', 'date_from', 'date_to']),
            'stats' => $this->compensationService->heroStatsFor($user),
            'tabCounts' => $this->compensationService->tabCountsFor($user),
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
        $user = $this->manager($request);

        $data = $request->validate([
            'employee_profile_id' => ['required', 'integer'],
            'bonus_type' => ['required', Rule::in(['performance', 'signing', 'retention', 'spot', 'holiday', 'other'])],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
            'currency' => ['sometimes', 'string', 'max:3'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'payment_date' => ['required', 'date'],
        ]);

        DB::transaction(function () use ($data, $user): void {
            $profile = $this->access
                ->applyCurrentProfileScope(HrEmployeeProfile::query(), $user)
                ->lockForUpdate()
                ->findOrFail($data['employee_profile_id']);

            HrBonusPayment::create([
                'employee_profile_id' => $profile->id,
                'bonus_type' => $data['bonus_type'],
                'amount' => $data['amount'],
                'currency' => $data['currency'] ?? 'NZD',
                'reason' => $data['reason'] ?? null,
                'payment_date' => $data['payment_date'],
                'status' => 'pending',
                'created_by' => $user->id,
            ]);
        }, attempts: 1);

        return redirect()->back()->with('success', 'Bonus payment created.');
    }

    /**
     * Approve a bonus payment.
     */
    public function approve(Request $request, HrBonusPayment $bonus)
    {
        $user = $this->manager($request);

        $approved = DB::transaction(function () use ($bonus, $user): ?HrBonusPayment {
            $locked = $this->access
                ->applyBonusScope(HrBonusPayment::query(), $user)
                ->lockForUpdate()
                ->findOrFail($bonus->getKey());
            if ($locked->status !== 'pending') {
                return null;
            }
            $locked->update([
                'status' => 'approved',
                'approved_by' => $user->id,
                'approved_at' => now(),
            ]);

            return $locked->fresh();
        }, attempts: 1);
        if (! $approved) {
            return redirect()->back()->with('error', 'Only pending bonuses can be approved.');
        }

        $this->notifyRecipient($approved, 'approved');

        return redirect()->back()->with('success', 'Bonus payment approved.');
    }

    /**
     * Cancel a mistaken or declined bonus before it is paid. The `cancelled`
     * status existed on the model but nothing could set it — a wrong bonus
     * sat pending/approved forever.
     */
    public function cancel(Request $request, HrBonusPayment $bonus)
    {
        $user = $this->manager($request);

        $result = DB::transaction(function () use ($bonus, $user): ?array {
            $locked = $this->access
                ->applyBonusScope(HrBonusPayment::query(), $user)
                ->lockForUpdate()
                ->findOrFail($bonus->getKey());
            if (! in_array($locked->status, ['pending', 'approved'], true)) {
                return null;
            }
            $wasApproved = $locked->status === 'approved';
            $locked->update(['status' => 'cancelled']);

            return [$locked->fresh(), $wasApproved];
        }, attempts: 1);
        if ($result === null) {
            return redirect()->back()->with('error', 'Only pending or approved (unpaid) bonuses can be cancelled.');
        }
        [$cancelled, $wasApproved] = $result;

        // Only tell the recipient if they had already been told it was
        // approved — cancelling a pending bonus they never knew about is noise.
        if ($wasApproved) {
            $this->notifyRecipient($cancelled, 'cancelled');
        }

        return redirect()->back()->with('success', 'Bonus payment cancelled.');
    }

    private function notifyRecipient(HrBonusPayment $bonus, string $action): void
    {
        $recipient = $bonus->employeeProfile?->user;
        if (! $recipient) {
            return;
        }

        try {
            $recipient->notify(new BonusStatusNotification($bonus, $action));
        } catch (\Throwable $exception) {
            Log::warning('Failed to send bonus status notification', [
                'bonus_id' => $bonus->id,
                'action' => $action,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function viewer(Request $request): User
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compensation.view'), 403);

        return $this->access->currentStaff($user, $user);
    }

    private function manager(Request $request): User
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compensation.manage'), 403);

        return $this->access->currentStaff($user, $user);
    }
}
