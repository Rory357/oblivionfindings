<?php

test('people index uses portable outer aggregate filtering and a stable identity tie break', function () {
    $source = file_get_contents(dirname(__DIR__, 3).'/app/Http/Controllers/Hr/EmployeeProfileController.php');

    expect($source)
        ->not->toContain("->havingRaw('headcount_budget - employees_count - COALESCE(open_req_openings, 0) > 0')")
        ->toContain("->fromSub(\$understaffedQuery, 'understaffed_positions')\n            ->whereRaw('headcount_budget - employees_count - COALESCE(open_req_openings, 0) > 0')")
        ->toContain("->orderBy('users.name')\n            ->orderBy('users.id')");
});
