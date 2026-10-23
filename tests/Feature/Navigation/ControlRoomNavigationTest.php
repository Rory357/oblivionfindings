<?php

it('builds the Control Room sidebar from the six approved destinations', function () {
    $sidebar = file_get_contents(resource_path('js/components/app-sidebar.tsx'));
    $navigation = file_get_contents(resource_path('js/lib/control-room-navigation.ts'));

    // 11 Oct 2026: twelve Control Room menu items became six destinations;
    // each destination's views are tabs on its header rail.
    expect($sidebar)
        ->toContain('controlRoomDestinations(can)')
        ->toContain('controlRoomPrimaryLinkActive(')
        ->toContain('filterVisibleSidebarGroups')
        ->not->toContain("title: 'Command Centre'")
        ->not->toContain("title: 'Desk'")
        ->not->toContain("title: 'Real-time Stats'");

    expect($navigation)
        ->toContain("title: 'Live response'")
        ->toContain("title: 'Monitoring'")
        ->toContain("title: 'Communications'")
        ->toContain("title: 'Duty handover'")
        ->toContain("title: 'Reports'")
        ->toContain("title: 'Settings'")
        ->toContain("label: 'My work'")
        ->toContain("label: 'Safety handovers'");
});
