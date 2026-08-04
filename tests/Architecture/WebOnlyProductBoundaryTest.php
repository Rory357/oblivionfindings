<?php

it('keeps the product positioned as one desktop web application', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $home = file_get_contents($root.'/resources/js/pages/home.tsx');
    $myDay = file_get_contents($root.'/resources/js/pages/my-day/index.tsx');
    $myDayActions = file_get_contents($root.'/app/Http/Controllers/MyDayActionsController.php');

    expect($home)
        ->toContain('Secure web access for staff and managers')
        ->not->toContain('Mobile-friendly for staff on the move')
        ->and($myDay)
        ->toContain('desktop web application')
        ->toContain('no native application surface is part of')
        ->not->toContain('native iOS/Android apps')
        ->and($myDayActions)
        ->not->toContain('mobile app calling without allocations');
});
