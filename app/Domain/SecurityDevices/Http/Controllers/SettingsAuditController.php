<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\SecurityDevices\Models\DeviceGroup;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

class SettingsAuditController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        abort_unless(
            $user->canDo('securityDevices.groups.manage')
                || $user->canDo('securityDevices.reports.view'),
            403,
        );

        $tenantId = (int) ($user->organization_id ?: 1);
        $areas = collect([
            $user->canDo('securityDevices.groups.manage') ? [
                'title' => 'Device groups',
                'description' => 'Organise devices using manual membership and reviewed automatic rules.',
                'href' => '/security-devices/device-groups',
            ] : null,
            $user->canDo('securityDevices.integrations.view') ? [
                'title' => 'Integrations',
                'description' => 'Manage provider connections, site mappings, sync, and imported-device exceptions.',
                'href' => '/security-devices/integrations',
            ] : null,
            $user->canDo('securityDevices.reports.view') ? [
                'title' => 'Reports & exports',
                'description' => 'Review device, event, and maintenance reports with permission-gated exports.',
                'href' => '/security-devices/reports',
            ] : null,
        ])->filter()->values();

        return Inertia::render('security-devices/settings', [
            'summary' => [
                'device_groups' => DeviceGroup::query()->where('tenant_id', $tenantId)->count(),
                'audit_entries' => AuditLog::query()->forOrganization($tenantId)->count(),
            ],
            'areas' => $areas,
        ]);
    }
}
