<?php

namespace Tests\Feature\ControlRoom;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ControlRoomShellConsistencyTest extends TestCase
{
    public function test_primary_control_room_routes_share_the_workspace_strip_contract(): void
    {
        $routes = [
            'control-room.index' => '/control-room',
            'control-room.alerts.index' => '/control-room/alerts',
            'control-room.escalations.index' => '/control-room/escalations',
            'control-room.incidents.index' => '/control-room/incidents',
            'control-room.my-tasks' => '/control-room/my-tasks',
            'control-room.shifts.index' => '/control-room/shifts',
        ];

        foreach ($routes as $name => $uri) {
            $this->assertTrue(Route::has($name), "Missing primary Control Room route {$name}.");
            $this->assertSame($uri, '/'.ltrim(route($name, absolute: false), '/'));
        }

        $compatibilityStrip = file_get_contents(
            resource_path('js/components/control-room/command-centre-tabs.tsx'),
        );
        $this->assertStringContainsString('WorkspaceStrip', $compatibilityStrip);

        foreach ([
            'escalations.tsx' => 'Escalations',
            'my-tasks.tsx' => 'My queue',
            'shifts.tsx' => 'Control Room shifts',
        ] as $file => $title) {
            $source = file_get_contents(resource_path('js/pages/control-room/'.$file));
            $this->assertStringContainsString('CommandCentrePage', $source, $file);
            $this->assertStringContainsString('title="'.$title.'"', $source, $file);
            $this->assertStringNotContainsString('title="Command Centre"', $source, $file);
        }
    }

    public function test_every_specialist_page_uses_the_compact_command_centre_shell(): void
    {
        $pages = [
            'map.tsx' => 'Live map',
            'stats.tsx' => 'Operational analytics',
            'reports.tsx' => 'Reports',
            'messaging.tsx' => 'Messaging',
            'settings.tsx' => 'Settings',
            'devices/index.tsx' => 'Device signals',
            'playbooks/index.tsx' => 'Playbooks',
            'sla/index.tsx' => 'SLA performance',
            'broadcast.tsx' => 'Broadcasts',
        ];

        foreach ($pages as $file => $title) {
            $source = file_get_contents(resource_path('js/pages/control-room/'.$file));
            $this->assertStringContainsString('CommandCentrePage', $source, $file);
            $this->assertStringContainsString('variant="compact"', $source, $file);
            $this->assertStringContainsString('title="'.$title.'"', $source, $file);
        }

        foreach ([
            'devices/show.tsx',
            'playbooks/show.tsx',
            'sla/breaches.tsx',
            'broadcast-show.tsx',
            'show.tsx',
        ] as $file) {
            $source = file_get_contents(resource_path('js/pages/control-room/'.$file));
            $this->assertStringContainsString('CommandCentrePage', $source, $file);
            $this->assertStringContainsString('variant="compact"', $source, $file);
        }
    }
}
