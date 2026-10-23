<?php

namespace Tests\Feature\ControlRoom;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ControlRoomShellConsistencyTest extends TestCase
{
    public function test_live_response_views_share_the_standard_header_and_view_tabs(): void
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

        // 23 Oct 2026 (Stephan): Control Room pages use the standard app
        // header, with each destination's views as tabs on its bottom edge.
        $frame = file_get_contents(resource_path('js/components/control-room/control-room-page.tsx'));
        $this->assertStringContainsString('<PageHeader', $frame);
        $this->assertStringContainsString('ControlRoomHubRail', $frame);
        $this->assertStringContainsString("{ title: 'Home', href: '/dashboard' }", $frame);

        foreach (['index.tsx', 'alerts/index.tsx', 'escalations.tsx', 'incidents.tsx', 'my-tasks.tsx', 'show.tsx'] as $file) {
            $source = file_get_contents(resource_path('js/pages/control-room/'.$file));
            $this->assertStringContainsString('<ControlRoomPage', $source, $file);
            $this->assertStringNotContainsString('CommandCentrePage', $source, $file);
            $this->assertStringNotContainsString('<PageHero', $source, $file);
            $this->assertStringNotContainsString('AlertWorkspaceDialog', $source, $file);
        }

        foreach (['index.tsx', 'alerts/index.tsx', 'escalations.tsx', 'incidents.tsx', 'my-tasks.tsx'] as $file) {
            $source = file_get_contents(resource_path('js/pages/control-room/'.$file));
            $this->assertStringContainsString('<AlertPreview', $source, $file);
            $this->assertStringContainsString('useAlertSelection', $source, $file);
        }
    }

    public function test_every_other_control_room_page_uses_the_same_frame(): void
    {
        // 24 Oct 2026: Monitoring, Communications, Duty handover, Reports and
        // Settings moved off the old compact command-centre shell onto the
        // same ControlRoomPage frame as Live response.
        foreach ([
            'map.tsx',
            'devices/index.tsx',
            'devices/show.tsx',
            'broadcast.tsx',
            'broadcast-show.tsx',
            'messaging.tsx',
            'shifts.tsx',
            'shifts/handover.tsx',
            'reports.tsx',
            'stats.tsx',
            'sla/breaches.tsx',
            'sla/index.tsx',
            'playbooks/index.tsx',
            'playbooks/show.tsx',
            'settings.tsx',
        ] as $file) {
            $source = file_get_contents(resource_path('js/pages/control-room/'.$file));
            $this->assertStringContainsString('<ControlRoomPage', $source, $file);
            $this->assertStringNotContainsString('CommandCentrePage', $source, $file);
            $this->assertStringNotContainsString('<PageHero', $source, $file);
            $this->assertStringNotContainsString('WorkspaceStrip', $source, $file);
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
            $this->assertStringContainsString('<ResponseWorklist', $source, $file);
        }

        foreach (['incidents.tsx', 'shifts.tsx'] as $file) {
            $source = file_get_contents(resource_path('js/pages/control-room/'.$file));
            $this->assertStringContainsString('ControlRoomRowActions', $source, $file);
        }

        $escalations = file_get_contents(resource_path('js/pages/control-room/escalations.tsx'));
        // The queue choice lives in the header (no strip above the list).
        $this->assertStringContainsString('label="All queues"', $escalations);
        $this->assertStringNotContainsString('EscalationQueueFilters', $escalations);
        $this->assertStringContainsString('worklist.links', $escalations);
        $this->assertStringNotContainsString('QueueColumn', $escalations);
        $this->assertStringNotContainsString('grid-cols-3', $escalations);
    }
}
