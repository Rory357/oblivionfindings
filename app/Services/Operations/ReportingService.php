<?php

namespace App\Services\Operations;

use App\Models\BillingEntry;
use App\Models\Shift;
use App\Models\StaffCredential;
use App\Models\Timesheet;
use App\Models\User;

class ReportingService
{
    public function shiftAnalytics(int $orgId, string $dateFrom, string $dateTo, array $filters): array
    {
        $query = Shift::query()
            ->whereHas('client', fn ($q) => $q->where('organization_id', $orgId))
            ->whereBetween('starts_at', [$dateFrom, $dateTo . ' 23:59:59'])
            ->when(!empty($filters['client_id']), fn ($q) => $q->where('client_id', $filters['client_id']))
            ->when(!empty($filters['staff_id']), fn ($q) => $q->where('user_id', $filters['staff_id']));

        $total = (clone $query)->count();
        $completed = (clone $query)->where('status', 'completed')->count();
        $cancelled = (clone $query)->where('status', 'cancelled')->count();
        $noShow = (clone $query)->where('status', 'no_show')->count();

        return [
            'total_shifts' => $total,
            'completed' => $completed,
            'cancelled' => $cancelled,
            'no_show' => $noShow,
            'completion_rate' => $total > 0 ? round(($completed / $total) * 100, 1) : 0,
            'cancellation_rate' => $total > 0 ? round(($cancelled / $total) * 100, 1) : 0,
            'by_status' => (clone $query)
                ->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status'),
            'by_day_of_week' => (clone $query)
                ->selectRaw('DAYOFWEEK(starts_at) as dow, COUNT(*) as count')
                ->groupBy('dow')
                ->pluck('count', 'dow'),
            'by_staff' => (clone $query)
                ->selectRaw('user_id, COUNT(*) as shift_count')
                ->groupBy('user_id')
                ->with('staff:id,name')
                ->limit(20)
                ->get(),
        ];
    }

    public function complianceReport(int $orgId, array $filters): array
    {
        $staffQuery = User::where('organization_id', $orgId)->staff();

        $totalStaff = (clone $staffQuery)->count();

        $credentials = StaffCredential::whereIn('user_id', (clone $staffQuery)->select('id'))
            ->get();

        $expired = $credentials->filter(fn ($c) => $c->expires_at && $c->expires_at->isPast());
        $expiringSoon = $credentials->filter(fn ($c) => $c->expires_at && $c->expires_at->isBetween(now(), now()->addDays(30)));
        $valid = $credentials->filter(fn ($c) => !$c->expires_at || $c->expires_at->isFuture());

        $byType = $credentials->groupBy('type')->map(function ($group) {
            return [
                'total' => $group->count(),
                'expired' => $group->filter(fn ($c) => $c->expires_at && $c->expires_at->isPast())->count(),
                'expiring_soon' => $group->filter(fn ($c) => $c->expires_at && $c->expires_at->isBetween(now(), now()->addDays(30)))->count(),
                'valid' => $group->filter(fn ($c) => !$c->expires_at || $c->expires_at->isFuture())->count(),
            ];
        });

        return [
            'total_staff' => $totalStaff,
            'total_credentials' => $credentials->count(),
            'expired_count' => $expired->count(),
            'expiring_soon_count' => $expiringSoon->count(),
            'valid_count' => $valid->count(),
            'compliance_rate' => $credentials->count() > 0
                ? round(($valid->count() / $credentials->count()) * 100, 1)
                : 100,
            'by_type' => $byType,
            'expired_details' => $expired->take(50)->map(fn ($c) => [
                'user_id' => $c->user_id,
                'type' => $c->type,
                'expires_at' => $c->expires_at->toDateString(),
                'days_overdue' => $c->expires_at->diffInDays(now()),
            ])->values(),
        ];
    }
}
