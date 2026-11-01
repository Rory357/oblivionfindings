<?php

namespace App\Domain\SecurityDevices\Support;

/**
 * The S&D rebuild's new permission keys (plan v10, "New permissions and their grant migrations").
 *
 * Each key is given to exactly the roles, and personal overrides, that hold its `from` key, both by
 * the grant migration on deploy and by SecurityDevicesPermissionsSeeder on a fresh install.
 */
final class SecurityDevicesRebuildPermissions
{
    private const SD = ['group' => 'security_devices', 'module' => 'Security & Devices'];

    /** @var array<string, array{from: string, description: string, group: string, module: string}> */
    public const KEYS = [
        'securityDevices.access.lockdown' => ['from' => 'securityDevices.accessControl.manage', 'description' => 'Lock down or evacuate a Site', ...self::SD],
        'attendance.doorClockIns.review' => ['from' => 'timesheets.approve', 'description' => 'See and sort out clock-in gaps from door badges', 'group' => 'timesheets', 'module' => 'Operations'],
        'securityDevices.obli.fixes.manage' => ['from' => 'securityDevices.endpoints.manageActionPolicy', 'description' => 'Turn Obli\'s fixes on and approve one-off fixes', ...self::SD],
        'securityDevices.obli.pictures.view' => ['from' => 'securityDevices.remote.managePolicy', 'description' => 'Open Obli\'s screen pictures, with a reason', ...self::SD],
        'securityDevices.alerts.acknowledge' => ['from' => 'securityDevices.events.view', 'description' => 'Acknowledge, assign and snooze device alerts', ...self::SD],
        'securityDevices.automations.manage' => ['from' => 'securityDevices.monitoring.manage', 'description' => 'Manage alert rules, automations, house modes and quiet time', ...self::SD],
        'securityDevices.modes.switch' => ['from' => 'securityDevices.devices.update', 'description' => 'Switch a house mode by hand', ...self::SD],
        'securityDevices.cctv.live.watch' => ['from' => 'securityDevices.cctv.media.view', 'description' => 'Watch a camera live, with a reason', ...self::SD],
        'securityDevices.alarms.arm' => ['from' => 'securityDevices.commands.operate', 'description' => 'Arm an alarm, only where its maker allows it', ...self::SD],
        'securityDevices.alarms.disarm' => ['from' => 'securityDevices.commands.control', 'description' => 'Disarm an alarm, only where its maker allows it', ...self::SD],
        'securityDevices.doors.unlock' => ['from' => 'securityDevices.accessControl.manage', 'description' => 'Unlock a door for 5 seconds or keep it unlocked', ...self::SD],
        'securityDevices.terminal.use' => ['from' => 'securityDevices.commands.admin', 'description' => 'Use a device\'s Terminal tab', ...self::SD],
        'securityDevices.webPage.open' => ['from' => 'securityDevices.commands.admin', 'description' => 'Open a device\'s web page through its hub', ...self::SD],
        'securityDevices.hubs.approve' => ['from' => 'securityDevices.integrations.manage', 'description' => 'Approve, replace and remove hubs', ...self::SD],
        'securityDevices.savedLogins.manage' => ['from' => 'securityDevices.integrations.manage', 'description' => 'Add, replace and remove saved logins', ...self::SD],
        'securityDevices.people.link' => ['from' => 'securityDevices.devices.assign', 'description' => 'Link a device to a person (consent checked)', ...self::SD],
        'securityDevices.trackers.command' => ['from' => 'securityDevices.commands.control', 'description' => 'Send a command to a care tracker', ...self::SD],
    ];
}
