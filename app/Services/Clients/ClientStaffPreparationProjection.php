<?php

namespace App\Services\Clients;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOnboardingChecklist;
use App\Models\Client;
use Illuminate\Support\Collection;

class ClientStaffPreparationProjection
{
    /**
     * Project canonical HR onboarding readiness for workers assigned to a client.
     * No HR task content or client-profile-owned copy is returned.
     *
     * @return array{summary: array<string, int>, workers: array<int, array<string, mixed>>}
     */
    public function forClient(Client $client): array
    {
        $workers = $client->supportWorkers()
            ->select('users.id', 'users.name')
            ->orderBy('users.name')
            ->get();

        if ($workers->isEmpty()) {
            return [
                'summary' => [
                    'assigned' => 0,
                    'prepared' => 0,
                    'in_progress' => 0,
                    'needs_attention' => 0,
                ],
                'workers' => [],
            ];
        }

        $today = now()->toDateString();
        $profiles = HrEmployeeProfile::query()
            ->active()
            ->when(
                $client->site_id !== null,
                fn ($query) => $query->atSite((int) $client->site_id),
                fn ($query) => $query->whereRaw('1 = 0'),
            )
            ->whereIn('user_id', $workers->pluck('id'))
            ->where(function ($query) use ($today): void {
                $query->whereNull('start_date')
                    ->orWhereDate('start_date', '<=', $today);
            })
            ->where(function ($query) use ($today): void {
                $query->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', $today);
            })
            ->get(['id', 'user_id', 'position_title', 'position_role'])
            ->keyBy('user_id');

        $checklists = $this->latestChecklists($profiles);
        $today = now()->startOfDay();

        $rows = $workers->map(function ($worker) use ($profiles, $checklists, $today): array {
            $profile = $profiles->get($worker->id);
            $checklist = $profile ? $checklists->get($profile->id) : null;
            $total = (int) ($checklist?->tasks_count ?? 0);
            $completed = (int) ($checklist?->completed_tasks_count ?? 0);
            $status = $profile
                ? ($checklist?->status ?? 'not_started')
                : 'not_linked';
            $isOverdue = $checklist
                && ! in_array($status, ['completed', 'cancelled', 'archived'], true)
                && $checklist->due_date
                && $checklist->due_date->copy()->startOfDay()->lt($today);

            return [
                'user_id' => (int) $worker->id,
                'name' => $worker->name,
                'role' => $profile?->position_title ?? $profile?->position_role,
                'employee_profile_id' => $profile?->id,
                'checklist_id' => $checklist?->id,
                'status' => $status,
                'tasks_total' => $total,
                'tasks_completed' => $completed,
                'progress_percentage' => $total > 0
                    ? (int) round(($completed / $total) * 100)
                    : 0,
                'due_date' => $checklist?->due_date?->toDateString(),
                'is_overdue' => (bool) $isOverdue,
            ];
        })->values();

        return [
            'summary' => [
                'assigned' => $rows->count(),
                'prepared' => $rows->where('status', 'completed')->count(),
                'in_progress' => $rows->whereIn('status', ['pending', 'in_progress'])->count(),
                'needs_attention' => $rows->filter(fn (array $row) => $row['is_overdue']
                    || in_array($row['status'], ['not_linked', 'not_started', 'cancelled', 'archived'], true)
                )->count(),
            ],
            'workers' => $rows->all(),
        ];
    }

    /** @return Collection<int, HrOnboardingChecklist> */
    private function latestChecklists(Collection $profiles): Collection
    {
        if ($profiles->isEmpty()) {
            return collect();
        }

        return HrOnboardingChecklist::query()
            ->whereIn('employee_profile_id', $profiles->pluck('id'))
            ->withCount([
                'tasks',
                'tasks as completed_tasks_count' => fn ($query) => $query->where('status', 'completed'),
            ])
            ->orderByDesc('created_at')
            ->get()
            ->unique('employee_profile_id')
            ->keyBy('employee_profile_id');
    }
}
