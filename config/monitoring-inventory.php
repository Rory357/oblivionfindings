<?php

return [
    // Profiles are application code, not user-authored commands. The runtime
    // independently validates every executable, argument, CIM class, and field.
    'profiles' => [
        'linux.basic' => [
            'platform' => 'linux',
            'operations' => [
                ['uname', '-sr'],
                ['uptime', '-s'],
                ['df', '-P', '-B1'],
                ['systemctl', 'list-units', '--type=service', '--state=failed', '--no-legend'],
            ],
        ],
        'windows.basic' => [
            'platform' => 'windows',
            'operations' => [
                ['class' => 'Win32_OperatingSystem', 'properties' => ['Caption', 'Version', 'LastBootUpTime']],
                ['class' => 'Win32_LogicalDisk', 'properties' => ['Size', 'FreeSpace']],
                ['class' => 'Win32_Service', 'properties' => ['State', 'StartMode']],
            ],
        ],
    ],
];
