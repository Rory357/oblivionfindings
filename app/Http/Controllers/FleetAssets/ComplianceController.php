<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class ComplianceController extends Controller
{
    public function index(Request $request)
    {
        $hasFleetFields = Schema::hasColumn('assets', 'home_site_id');

        $eagerLoads = ['homeSite'];

        $query = Asset::vehicles();
        if ($hasFleetFields) {
            $query->with($eagerLoads);
        }

        // Search filter
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('asset_tag', 'like', "%{$search}%");
                if (Schema::hasColumn('assets', 'registration_number')) {
                    $q->orWhere('registration_number', 'like', "%{$search}%");
                }
            });
        }

        $vehicles = $query->orderBy('name')->get();

        $now = now();

        $vehiclesData = $vehicles->map(function ($v) use ($hasFleetFields, $now) {
            $regoExpiry = $hasFleetFields ? $v->registration_expires_at : null;
            $wofExpiry = $hasFleetFields ? $v->wof_expires_at : null;
            $cofExpiry = $hasFleetFields ? $v->cof_expires_at : null;
            $insuranceExpiry = $hasFleetFields && Schema::hasColumn('assets', 'insurance_expires_at') ? $v->insurance_expires_at : null;

            $status = 'ok';
            $worstDays = PHP_INT_MAX;

            foreach ([$regoExpiry, $wofExpiry, $cofExpiry, $insuranceExpiry] as $date) {
                if ($date) {
                    $days = $now->diffInDays($date, false);
                    if ($days < $worstDays) {
                        $worstDays = $days;
                    }
                }
            }

            if ($worstDays < 0) {
                $status = 'expired';
            } elseif ($worstDays <= 30) {
                $status = 'critical';
            } elseif ($worstDays <= 60) {
                $status = 'warning';
            }

            return [
                'id' => $v->id,
                'name' => $v->name,
                'asset_tag' => $v->asset_tag,
                'registration_number' => $hasFleetFields ? $v->registration_number : null,
                'registration_expires_at' => $regoExpiry ? $regoExpiry->toDateString() : null,
                'wof_expires_at' => $wofExpiry ? $wofExpiry->toDateString() : null,
                'cof_expires_at' => $cofExpiry ? $cofExpiry->toDateString() : null,
                'insurance_expires_at' => $insuranceExpiry ? $insuranceExpiry->toDateString() : null,
                'home_site' => $hasFleetFields && $v->homeSite ? [
                    'id' => $v->homeSite->id,
                    'name' => $v->homeSite->name,
                ] : null,
                'status' => $status,
                'worst_days' => $worstDays === PHP_INT_MAX ? null : (int) $worstDays,
            ];
        });

        // Status filter
        if ($request->filled('status') && $request->input('status') !== 'all') {
            $filterStatus = $request->input('status');
            $vehiclesData = $vehiclesData->filter(fn ($v) => $v['status'] === $filterStatus)->values();
        }

        // Summary counts
        $allVehicles = $vehicles->count();
        $expiredWof = $vehicles->filter(fn ($v) => $hasFleetFields && $v->wof_expires_at && $v->wof_expires_at->isPast())->count();
        $expiredRego = $vehicles->filter(fn ($v) => $hasFleetFields && $v->registration_expires_at && $v->registration_expires_at->isPast())->count();
        $expiring30 = $vehicles->filter(function ($v) use ($hasFleetFields, $now) {
            if (!$hasFleetFields) return false;
            foreach (['registration_expires_at', 'wof_expires_at', 'cof_expires_at'] as $field) {
                if ($v->$field && !$v->$field->isPast() && $now->diffInDays($v->$field, false) <= 30) {
                    return true;
                }
            }
            return false;
        })->count();
        $expiring60 = $vehicles->filter(function ($v) use ($hasFleetFields, $now) {
            if (!$hasFleetFields) return false;
            foreach (['registration_expires_at', 'wof_expires_at', 'cof_expires_at'] as $field) {
                if ($v->$field && !$v->$field->isPast() && $now->diffInDays($v->$field, false) <= 60 && $now->diffInDays($v->$field, false) > 30) {
                    return true;
                }
            }
            return false;
        })->count();

        // Hero — per-document due-in-30 counts + vehicles already expired. Computed
        // from the loaded collection (the page already fetches every vehicle).
        $dueWithin30 = function (string $field) use ($vehicles, $hasFleetFields, $now) {
            if (!$hasFleetFields) return 0;
            return $vehicles->filter(function ($v) use ($field, $now) {
                $date = $v->$field;
                return $date && !$date->isPast() && $now->diffInDays($date, false) <= 30;
            })->count();
        };
        $hasInsuranceColumn = $hasFleetFields && Schema::hasColumn('assets', 'insurance_expires_at');
        $expiredNow = $hasFleetFields
            ? $vehicles->filter(function ($v) use ($hasInsuranceColumn) {
                foreach (['registration_expires_at', 'wof_expires_at', 'cof_expires_at'] as $field) {
                    if ($v->$field && $v->$field->isPast()) return true;
                }
                return $hasInsuranceColumn && $v->insurance_expires_at && $v->insurance_expires_at->isPast();
            })->count()
            : 0;

        return Inertia::render('fleet-assets/compliance/index', [
            'vehicles' => $vehiclesData->values(),
            'hero' => [
                'wof_due_30' => $dueWithin30('wof_expires_at'),
                'rego_due_30' => $dueWithin30('registration_expires_at'),
                'cof_due_30' => $dueWithin30('cof_expires_at'),
                'expired_now' => $expiredNow,
            ],
            'summary' => [
                'total' => $allVehicles,
                'expired_wof' => $expiredWof,
                'expired_rego' => $expiredRego,
                'expiring_30' => $expiring30,
                'expiring_60' => $expiring60,
            ],
            'filters' => $request->only(['status', 'search']),
        ]);
    }
}
