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
                'items' => [
                    self::item('Problems & known errors', '/it/problems', 'book-open-check'),
                    self::item('Changes', '/it/changes', 'calendar-clock'),
                    self::item('Major incidents', '/it/major-incidents', 'siren'),
                    self::item('Monitoring', '/security-devices/monitoring', 'activity'),
                ],
            ];
        }
        if ($canManage) {
            $groups[] = [
                'label' => 'Setup',
                'items' => [
                    self::item('Teams, queues & services', '/it/setup', 'settings-2'),
                    self::item('SLA policies', '/it?tab=sla', 'timer'),
                    self::item('Integrations & API', '/security-devices/integrations', 'plug'),
                ],
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
