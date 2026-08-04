<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrPublicHoliday;
use App\Domain\Hr\Services\LeaveService;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PublicHolidayController extends Controller
{
    public function __construct(private readonly LeaveService $leaveService) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.leave.viewAny'), 403);

        $year = (int) $request->query('year', now()->year);

        $holidays = HrPublicHoliday::query()
            ->where('year', $year)
            ->orderBy('date')
            ->orderByDesc('is_national')
            ->get()
            ->map(fn (HrPublicHoliday $holiday) => $this->serializeHoliday($holiday))
            ->values();

        $canManage = $user->canDo('hr.leave.manage');
        $canApprove = $user->canDo('hr.leave.approve') || $canManage;

        return Inertia::render('hr/leave/holidays', [
            'holidays' => $holidays,
            'year' => $year,
            'hero' => $this->leaveService->hubHeroData($user, $canApprove),
            'can' => [
                'manage' => $canManage,
                'approve' => $canApprove,
                'create' => $canManage,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.leave.manage'), 403);

        $data = $this->validatedPayload($request);
        $date = Carbon::parse($data['date']);
        $region = $this->normaliseRegion($data['region'] ?? null, (bool) ($data['is_national'] ?? false));

        $this->assertUniqueIdentity($date->toDateString(), $region);
        try {
            HrPublicHoliday::query()->create([
                'name' => $data['name'],
                'date' => $date->toDateString(),
                'region' => $region,
                'is_national' => (bool) ($data['is_national'] ?? false),
                'year' => $date->year,
            ]);
        } catch (QueryException $exception) {
            $this->throwDuplicateIdentity($exception);
        }

        return redirect()->route('hr.leave.holidays.index', ['year' => $date->year]);
    }

    public function update(Request $request, HrPublicHoliday $holiday): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.leave.manage'), 403);

        $data = $this->validatedPayload($request);
        $date = Carbon::parse($data['date']);
        $region = $this->normaliseRegion($data['region'] ?? null, (bool) ($data['is_national'] ?? false));

        $this->assertUniqueIdentity($date->toDateString(), $region, (int) $holiday->id);
        try {
            $holiday->update([
                'name' => $data['name'],
                'date' => $date->toDateString(),
                'region' => $region,
                'is_national' => (bool) ($data['is_national'] ?? false),
                'year' => $date->year,
            ]);
        } catch (QueryException $exception) {
            $this->throwDuplicateIdentity($exception);
        }

        return redirect()->route('hr.leave.holidays.index', ['year' => $date->year]);
    }

    public function destroy(Request $request, HrPublicHoliday $holiday): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.leave.manage'), 403);

        // Leave hours are engine-calculated AROUND holidays — deleting one
        // that overlaps existing requests silently invalidates those hour
        // counts. Block with an explanation rather than corrupt history.
        $overlapping = HrLeaveRequest::query()
            ->whereIn('status', ['pending', 'approved'])
            ->whereDate('starts_at', '<=', $holiday->date)
            ->whereDate('ends_at', '>=', $holiday->date)
            ->count();

        if ($overlapping > 0) {
            return redirect()
                ->route('hr.leave.holidays.index', ['year' => (int) $holiday->year])
                ->with('error', "Cannot delete \u{201C}{$holiday->name}\u{201D} — {$overlapping} live leave request(s) span this date and their hours were calculated with it. Decline or cancel those requests first, or edit the holiday instead.");
        }

        $year = (int) $holiday->year;
        $holiday->delete();

        return redirect()->route('hr.leave.holidays.index', ['year' => $year]);
    }

    /**
     * @return array{name: string, date: string, region: string|null, is_national: bool}
     */
    private function validatedPayload(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'region' => ['nullable', 'string', 'max:100'],
            'is_national' => ['sometimes', 'boolean'],
        ]);
    }

    private function normaliseRegion(?string $region, bool $isNational): string
    {
        if ($isNational) {
            return 'national';
        }

        $normalised = strtolower(trim((string) $region));

        return $normalised !== '' ? $normalised : 'regional';
    }

    private function assertUniqueIdentity(string $date, string $region, ?int $exceptId = null): void
    {
        $exists = HrPublicHoliday::query()
            ->whereDate('date', $date)
            ->where('region', $region)
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'date' => 'A public holiday already exists for this date and region.',
            ]);
        }
    }

    private function throwDuplicateIdentity(QueryException $exception): never
    {
        $message = $exception->getMessage();

        if (str_contains($message, 'hr_public_holidays_date_region_uq')
            || str_contains($message, 'hr_public_holidays.date, hr_public_holidays.region')) {
            throw ValidationException::withMessages([
                'date' => 'A public holiday already exists for this date and region.',
            ]);
        }

        throw $exception;
    }

    /**
     * @return array{id: int, name: string, date: string, region: string|null, is_national: bool, year: int}
     */
    private function serializeHoliday(HrPublicHoliday $holiday): array
    {
        return [
            'id' => (int) $holiday->id,
            'name' => $holiday->name,
            'date' => $holiday->date?->toDateString(),
            'region' => $holiday->region,
            'is_national' => (bool) $holiday->is_national,
            'year' => (int) $holiday->year,
        ];
    }
}
