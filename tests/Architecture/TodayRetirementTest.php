<?php

it('keeps the retired Today dashboard implementation out of the application', function (): void {
    $root = dirname(__DIR__, 2);

    // /today is a compatibility redirect. Reintroducing its independent
    // controller or Inertia page would recreate the duplicate worker home.
    $this->assertFileDoesNotExist($root.'/app/Http/Controllers/TodayDashboardController.php');
    $this->assertFileDoesNotExist($root.'/resources/js/pages/dashboard/today.tsx');

    // Meds today is a separate, supported clinical workflow.
    $this->assertFileExists($root.'/resources/js/pages/meds/today/index.tsx');
});
