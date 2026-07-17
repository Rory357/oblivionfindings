<?php

it('uses the same plain-language desk and queue vocabulary in the Control Room sidebar', function () {
    $source = file_get_contents(resource_path('js/components/app-sidebar.tsx'));

    expect($source)
        ->toContain("title: 'Desk'")
        ->toContain("title: 'My queue'")
        ->toContain("title: 'Shifts'")
        ->toContain('filterVisibleSidebarGroups')
        ->not->toContain("title: 'Command Centre'");
});
