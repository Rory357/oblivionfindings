<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrPublicHoliday;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PublicHolidayController extends Controller
{
    use ResolvesHrTenant;

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.leave.viewAny'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);
        $year = (int) $request->query('year', now()->year);

        $holidays = HrPublicHoliday::query()
            ->where(function ($query) use ($tenantId) {
                $query->whereNull('tenant_id')
                    ->orWhere('tenant_id', $tenantId);
            })
            ->where('year', $year)
            ->orderBy('date')
            ->orderByDesc('is_national')
            ->get()
            ->map(fn (HrPublicHoliday $holiday) => $this->serializeHoliday($holiday))
            ->values();

        return Inertia::render('hr/leave/holidays', [
            'holidays' => $holidays,
            'year' => $year,
            'can' => [
                'manage' => $user->canDo('hr.leave.manage'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.leave.manage'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);
        $data = $this->validatedPayload($request);
        $date = Carbon::parse($data['date']);

        HrPublicHoliday::query()->create([
            'tenant_id' => $tenantId,
            'name' => $data['name'],
            'date' => $date->toDateString(),
            'region' => $this->normaliseRegion($data['region'] ?? null, (bool) ($data['is_national'] ?? false)),
            'is_national' => (bool) ($data['is_national'] ?? false),
            'year' => $date->year,
        ]);

        return redirect()->route('hr.leave.holidays.index', ['year' => $date->year]);
    }

    public function update(Request $request, HrPublicHoliday $holiday): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.leave.manage'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);
        if ($holiday->tenant_id !== null) {
            $this->assertHrTenantAccess($tenantId, (int) $holiday->tenant_id);
        }

        $data = $this->validatedPayload($request);
        $date = Carbon::parse($data['date']);

        $holiday->update([
            'name' => $data['name'],
            'date' => $date->toDateString(),
            'region' => $this->normaliseRegion($data['region'] ?? null, (bool) ($data['is_national'] ?? false)),
            'is_national' => (bool) ($data['is_national'] ?? false),
            'year' => $date->year,
        ]);

        return redirect()->route('hr.leave.holidays.index', ['year' => $date->year]);
    }

    public function destroy(Request $request, HrPublicHoliday $holiday): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.leave.manage'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);
        if ($holiday->tenant_id !== null) {
            $this->assertHrTenantAccess($tenantId, (int) $holiday->tenant_id);
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
