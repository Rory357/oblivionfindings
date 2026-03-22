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

    public function getVacancies(int $tenantId): Collection
    {
        return HrPosition::forTenant($tenantId)
            ->active()
            ->whereColumn('current_headcount', '<', 'headcount_budget')
            ->get();
    }

    public function syncHeadcount(int $positionId): void
    {
        $position = HrPosition::findOrFail($positionId);
        $count = HrEmployeeProfile::where('position_id', $positionId)
            ->where('is_active', true)
            ->count();
        $position->update(['current_headcount' => $count]);
    }

    public function syncAllHeadcounts(int $tenantId): void
    {
        $counts = HrEmployeeProfile::where('is_active', true)
            ->whereNotNull('position_id')
            ->groupBy('position_id')
            ->select('position_id', DB::raw('COUNT(*) as count'))
            ->pluck('count', 'position_id');

        HrPosition::forTenant($tenantId)->each(function (HrPosition $position) use ($counts) {
            $position->update([
                'current_headcount' => $counts->get($position->id, 0),
            ]);
        });
    }

    public function getDepartments(int $tenantId): array
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

    public function getPositionHierarchy(int $tenantId): array
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
