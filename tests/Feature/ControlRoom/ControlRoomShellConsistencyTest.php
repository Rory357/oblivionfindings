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

        $deskHero = file_get_contents(
            resource_path('js/components/control-room/dashboard/control-room-hero.tsx'),
        );
        $this->assertStringContainsString('ControlRoomWorkspaceHero', $deskHero);
        $this->assertStringContainsString('title="Desk"', $deskHero);

        foreach ([
            'alerts/index.tsx' => 'Active alerts',
            'escalations.tsx' => 'Escalations',
            'incidents.tsx' => 'Safety handovers',
            'my-tasks.tsx' => 'My queue',
            'shifts.tsx' => 'Shifts',
        ] as $file => $title) {
            $source = file_get_contents(resource_path('js/pages/control-room/'.$file));
            $this->assertStringContainsString('CommandCentrePage', $source, $file);
            $this->assertStringContainsString('title="'.$title.'"', $source, $file);
            $this->assertStringNotContainsString('title="Command Centre"', $source, $file);
            if ($file !== 'alerts/index.tsx') {
                $this->assertStringNotContainsString('<PageHero', $source, $file);
            }
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
            'devices/index.tsx' => 'Devices',
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

    public function test_primary_worklists_share_record_actions_and_escalations_use_one_bounded_list(): void
    {
        foreach ([
            'alerts/index.tsx',
            'escalations.tsx',
            'index.tsx',
            'my-tasks.tsx',
        ] as $file) {
            $source = file_get_contents(resource_path('js/pages/control-room/'.$file));
            $this->assertStringContainsString('buildControlRoomAlertRowActions', $source, $file);
        }

        foreach (['incidents.tsx', 'shifts.tsx'] as $file) {
            $source = file_get_contents(resource_path('js/pages/control-room/'.$file));
            $this->assertStringContainsString('ControlRoomRowActions', $source, $file);
        }

        $escalations = file_get_contents(resource_path('js/pages/control-room/escalations.tsx'));
        $this->assertStringContainsString('<AlertWorklist', $escalations);
        $this->assertStringContainsString('<EscalationQueueFilters', $escalations);
        $this->assertStringContainsString('worklist.links', $escalations);
        $this->assertStringNotContainsString('QueueColumn', $escalations);
        $this->assertStringNotContainsString('grid-cols-3', $escalations);

        $queueFilters = file_get_contents(
            resource_path('js/components/control-room/escalation-queue-filters.tsx'),
        );
        $this->assertStringContainsString('capacity_explanation', $queueFilters);
        $this->assertStringContainsString('overflow-x-auto', $queueFilters);
    }
}
