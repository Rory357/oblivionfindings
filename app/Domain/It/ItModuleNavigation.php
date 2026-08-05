<?php

namespace App\Domain\It;

use App\Models\User;

final class ItModuleNavigation
{
    /** @return array<int, array{label: string, items: array<int, array{label: string, href: string, icon: string}>}> */
    public static function forUser(User $user): array
    {
        $canView = $user->canDo('it.view');
        $canManage = $user->canDo('it.manage');
        $canRequest = $user->canDo('it.request');
        $canOpenSecurityDevices = $user->canDo('securityDevices.viewAny');
        $canOpenMonitoring = $canOpenSecurityDevices
            && $user->canDo('securityDevices.events.view');
        $canOpenIntegrations = $canOpenSecurityDevices
            && $user->canDo('securityDevices.integrations.view');
        $canEditSla = $canManage && $user->hasRole('admin');

        if (! $canView && ! $canRequest) {
            return [];
        }

        $serviceDesk = array_values(array_filter([
            $canView ? self::item('Overview', '/it', 'layout-dashboard') : null,
            $canView ? self::item('Tickets & queues', '/it?tab=tickets', 'inbox') : null,
            $canRequest ? self::item('My requests', '/it?tab=my-tickets', 'circle-user-round') : null,
            $canRequest ? self::item('Help Centre', '/it?tab=knowledge', 'life-buoy') : null,
        ]));

        $groups = [['label' => 'Service Desk', 'items' => $serviceDesk]];
        if ($canView) {
            $groups[] = [
                'label' => 'Service Delivery',
                'items' => [
                    self::item('Service catalogue', '/it?tab=catalog', 'book-open'),
                    self::item('Provisioning', '/it?tab=provisioning', 'package-check'),
                    self::item('Knowledge', '/it?tab=knowledge', 'library'),
                    self::item('Reports', '/it?tab=reports', 'chart-no-axes-column'),
                ],
            ];
            $groups[] = [
                'label' => 'Operations',
                'items' => array_values(array_filter([
                    self::item('Problems & known errors', '/it/problems', 'book-open-check'),
                    self::item('Changes', '/it/changes', 'calendar-clock'),
                    self::item('Major incidents', '/it/major-incidents', 'siren'),
                    $canOpenMonitoring
                        ? self::item('Monitoring', '/security-devices/monitoring', 'activity')
                        : null,
                ])),
            ];
        }
        if ($canManage) {
            $groups[] = [
                'label' => 'Setup',
                'items' => array_values(array_filter([
                    self::item('Teams, queues & services', '/it/setup', 'settings-2'),
                    $canEditSla
                        ? self::item('SLA policies', '/it?tab=tickets&action=sla', 'timer')
                        : null,
                    $canOpenIntegrations
                        ? self::item('Integrations & API', '/security-devices/integrations', 'plug')
                        : null,
                ])),
            ];
        }

        return $groups;
    }

    /** @return array{label: string, href: string, icon: string} */
    private static function item(string $label, string $href, string $icon): array
    {
        return compact('label', 'href', 'icon');
    }
}
