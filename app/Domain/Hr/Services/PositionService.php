<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrPosition;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PositionService
{
    public function createPosition(array $data): HrPosition
    {
        return HrPosition::create($data);
    }

    public function updatePosition(HrPosition $position, array $data): HrPosition
    {
        $position->update($data);
        return $position->fresh();
    }

    public function getVacancies(?int $tenantId): Collection
    {
        return HrPosition::forTenant($tenantId)
            ->active()
            ->whereColumn('current_headcount', '<', 'headcount_budget')
            ->get();
    }

    /**
     * Active positions still needing recruitment action:
     * budget − live active headcount − openings already in open requisitions.
     * Uses live employee counts (not the stored, possibly-stale current_headcount).
     *
     * @return Collection<int, HrPosition>
     */
    public function getUnderstaffed(?int $tenantId): Collection
    {
        return HrPosition::forTenant($tenantId)
            ->active()
            ->withCount(['employees' => fn ($q) => $q->where('is_active', true)])
            ->withSum(['requisitions as open_req_openings' => fn ($q) => $q->whereNotIn('status', ['closed'])], 'openings')
            ->get()
            ->filter(fn (HrPosition $p) => $this->actionableVacancies($p) > 0)
            ->values();
    }

    /** Live actionable vacancies for a position carrying employees_count + open_req_openings. */
    public function actionableVacancies(HrPosition $position): int
    {
        $filled = (int) ($position->employees_count ?? $position->current_headcount);
        $openReq = (int) ($position->open_req_openings ?? 0);

        return max(0, $position->headcount_budget - $filled - $openReq);
    }

    public function syncHeadcount(int $positionId): void
    {
        $position = HrPosition::findOrFail($positionId);
        $count = HrEmployeeProfile::where('position_id', $positionId)
            ->where('is_active', true)
            ->count();
        $position->update(['current_headcount' => $count]);

        // Close the recruitment loop: a freshly-filled seat needs no open req.
        $this->closeFilledRequisitions($position, $count);
    }

    /**
     * Reconcile every position's stored headcount, then close the recruitment
     * loop on any that are now fully staffed. Returns the number of requisitions
     * auto-closed (so the scheduled command can report it).
     */
    public function syncAllHeadcounts(?int $tenantId): int
    {
        $counts = HrEmployeeProfile::where('is_active', true)
            ->whereNotNull('position_id')
            ->groupBy('position_id')
            ->select('position_id', DB::raw('COUNT(*) as count'))
            ->pluck('count', 'position_id');

        $closed = 0;
        HrPosition::forTenant($tenantId)->each(function (HrPosition $position) use ($counts, &$closed) {
            $count = (int) $counts->get($position->id, 0);
            $position->update(['current_headcount' => $count]);
            $closed += $this->closeFilledRequisitions($position, $count);
        });

        return $closed;
    }

    /**
     * Close the recruitment loop: once a position is fully staffed, close any of
     * its still-open requisitions — the seats they advertised are filled. One-way
     * (never reopens on later attrition; a new gap raises a fresh requisition).
     * Returns the number of requisitions closed.
     */
    public function closeFilledRequisitions(HrPosition $position, ?int $filled = null): int
    {
        if ((int) $position->headcount_budget <= 0) {
            return 0;
        }

        $filled ??= HrEmployeeProfile::where('position_id', $position->id)
            ->where('is_active', true)
            ->count();

        if ($filled < (int) $position->headcount_budget) {
            return 0;
        }

        $closed = $position->requisitions()
            ->whereNotIn('status', ['closed'])
            ->update([
                'status' => 'closed',
                'closing_at' => now()->toDateString(),
            ]);

        if ($closed > 0) {
            \Illuminate\Support\Facades\Log::info('hr.requisition.auto_closed', [
                'position_id' => $position->id,
                'position_title' => $position->title,
                'closed' => $closed,
            ]);
        }

        return $closed;
    }

    public function getDepartments(?int $tenantId): array
    {
        return HrPosition::forTenant($tenantId)
            ->active()
            ->whereNotNull('department')
            ->distinct()
            ->pluck('department')
            ->sort()
            ->values()
            ->all();
    }

    public function getPositionHierarchy(?int $tenantId): array
    {
        $positions = HrPosition::forTenant($tenantId)
            ->active()
            ->with('employees:id,position_id,user_id', 'employees.user:id,name')
            ->get();

        $roots = $positions->whereNull('reports_to_position_id');

        return $roots->map(fn ($pos) => $this->buildTree($pos, $positions))->all();
    }

    private function buildTree(HrPosition $position, Collection $all): array
    {
        $children = $all->where('reports_to_position_id', $position->id);

        return [
            'id' => $position->id,
            'title' => $position->title,
            'code' => $position->code,
            'department' => $position->department,
            'headcount_budget' => $position->headcount_budget,
            'current_headcount' => $position->current_headcount,
            'employees' => $position->employees->map(fn ($e) => [
                'id' => $e->id,
                'name' => $e->user?->name ?? 'Unknown',
            ])->all(),
            'children' => $children->map(fn ($child) => $this->buildTree($child, $all))->values()->all(),
        ];
    }
}
