<?php

namespace App\Domain\It;

use App\Models\User;
use App\Services\Sites\SiteCredentialAccess;

final class ItModuleNavigation
{
    /** @return array<int, array{label: string, items: array<int, array{label: string, href: string, icon: string}>}> */
    public static function forUser(User $user): array
    {
        $canView = $user->canDo('it.view');
        $canManage = $user->canDo('it.manage');
        $canRequest = $user->canDo('it.request');
        $canKnowledge = app(Services\ItKbAccessService::class)->hasKnowledgeCapability($user);
        $canOpenSecurityDevices = $user->canDo('securityDevices.viewAny');
        $canOpenMonitoring = $canOpenSecurityDevices
            && $user->canDo('securityDevices.events.view');
        $canOpenIntegrations = $canOpenSecurityDevices
            && $user->canDo('securityDevices.integrations.view');
        $canEditSla = $canManage && $user->hasRole('admin');
        $registers = self::registerCapabilities($user);

        if (! $canView && ! $canRequest && ! $canKnowledge && ! in_array(true, $registers, true)) {
            return [];
        }

        $serviceDesk = array_values(array_filter([
            $canView ? self::item('Overview', '/it', 'layout-dashboard') : null,
            $canView ? self::item('Tickets & queues', '/it?tab=tickets', 'inbox') : null,
            $canRequest ? self::item('My requests', '/it?tab=my-tickets', 'circle-user-round') : null,
            ! $canView && ($canKnowledge || $canRequest) ? self::item($canKnowledge ? 'Knowledge & Documentation' : 'Guides', '/it/knowledge', 'library') : null,
        ]));

        $groups = [['label' => 'Service Desk', 'items' => $serviceDesk]];
        if ($canView) {
            $groups[] = [
                'label' => 'Service Delivery',
                'items' => array_values(array_filter([
                    $canRequest
                        ? self::item('Service catalogue', '/it?tab=catalog', 'book-open')
                        : null,
                    self::item('Provisioning', '/it/provisioning', 'package-check'),
                    self::item('Work planning', '/it/work', 'calendar-clock'),
                    self::item('Knowledge & Documentation', '/it/knowledge', 'library'),
                    self::item('Reports', '/it/reports', 'chart-no-axes-column'),
                ])),
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

        $groups[] = [
            'label' => 'Vendors & access',
            'items' => array_values(array_filter([
                $registers['vendors'] || $registers['credentials'] || $registers['contracts']
                    ? self::item('Vendors & Credentials', $registers['vendors'] ? '/vendors?tab=vendors' : ($registers['credentials'] ? '/vendors?tab=credentials' : '/vendors'), 'package')
                    : null,
            ])),
        ];

        return array_values(array_filter($groups, fn (array $group) => $group['items'] !== []));
    }

    /** Metadata discovery only; reveal, copy and commercial rights remain record-specific. */
    public static function registerCapabilities(User $user): array
    {
        return [
            'vendors' => $user->isApproved() && $user->canDo('vendors.view'),
            'contracts' => app(\App\Services\Sites\VendorCommercialAccess::class)->capable($user),
            'credentials' => $user->isApproved() && ($user->canDo('credentials.view')
                || app(SiteCredentialAccess::class)->query($user, 'view')->exists()),
        ];
    }

    /** @return array{label: string, href: string, icon: string} */
    private static function item(string $label, string $href, string $icon): array
    {
        return compact('label', 'href', 'icon');
    }
}
