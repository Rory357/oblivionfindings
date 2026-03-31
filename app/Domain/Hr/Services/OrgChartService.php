<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use Illuminate\Support\Collection;

class OrgChartService
{
    public function getHierarchy(?int $tenantId): array
    {
        $employees = HrEmployeeProfile::forTenant($tenantId)
            ->active()
            ->with('user:id,name,email', 'position:id,title,code')
            ->get();

        $roots = $employees->whereNull('manager_user_id');

        return $roots->map(fn ($emp) => $this->buildNode($emp, $employees))->values()->all();
    }

    public function getDirectReports(int $userId): Collection
    {
        return HrEmployeeProfile::where('manager_user_id', $userId)
            ->active()
            ->with('user:id,name,email')
            ->get();
    }

    public function getReportingChain(int $userId): array
    {
        $chain = [];
        $profile = HrEmployeeProfile::where('user_id', $userId)->first();

        while ($profile && $profile->manager_user_id) {
            $manager = HrEmployeeProfile::where('user_id', $profile->manager_user_id)
                ->with('user:id,name,email')
                ->first();
            if (!$manager) break;
            $chain[] = [
                'id' => $manager->id,
                'name' => $manager->user?->name ?? 'Unknown',
                'position' => $manager->position_title,
            ];
            $profile = $manager;
        }

        return $chain;
    }

    public function updateManager(HrEmployeeProfile $profile, ?int $managerUserId): void
    {
        $profile->update(['manager_user_id' => $managerUserId]);
    }

    private function buildNode(HrEmployeeProfile $employee, Collection $all): array
    {
        $reports = $all->where('manager_user_id', $employee->user_id);

        return [
            'id' => $employee->id,
            'user_id' => $employee->user_id,
            'name' => $employee->user?->name ?? 'Unknown',
            'email' => $employee->user?->email,
            'position_title' => $employee->position_title,
            'department' => $employee->departmentRelation?->name ?? $employee->department,
            'profile_photo_path' => $employee->profile_photo_path ?? null,
            'children' => $reports->map(fn ($r) => $this->buildNode($r, $all))->values()->all(),
        ];
    }
}
