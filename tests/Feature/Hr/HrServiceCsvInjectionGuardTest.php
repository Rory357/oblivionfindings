<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\EmployeeImportExportService;
use App\Domain\Hr\Services\ReportBuilderService;
use App\Models\Site;
use App\Models\User;

/**
 * @return array<int, array<int, string|null>>
 */
function e1ParseCsv(string $csv): array
{
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $csv);
    rewind($stream);

    $rows = [];
    while (($row = fgetcsv($stream)) !== false) {
        $rows[] = $row;
    }

    fclose($stream);

    return $rows;
}

test('employee service exports neutralize formula-leading profile text', function () {
    $site = Site::factory()->create();
    $user = User::factory()->create([
        'name' => '+SUM(1,1)',
        'email' => 'csv-guard@example.test',
        'approved_at' => now(),
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'employee_number' => '=cmd',
        'position_title' => '-1+2',
        'department' => '@HYPERLINK',
        'primary_site_id' => $site->id,
        'hours_per_week' => 37.5,
        'is_active' => true,
    ]);

    $rows = e1ParseCsv(app(EmployeeImportExportService::class)->exportToCsv($user));

    expect($rows)->toHaveCount(2)
        ->and($rows[1][0])->toBe("'=cmd")
        ->and($rows[1][1])->toBe("'+SUM(1,1)")
        ->and($rows[1][3])->toBe("'-1+2")
        ->and($rows[1][5])->toBe("'@HYPERLINK")
        ->and($rows[1][9])->toBe('37.50')
        ->and($rows[1][10])->toBe('1');
});

test('report builder service neutralizes every dangerous prefix but preserves numerics', function () {
    $fields = [
        'equals',
        'plus',
        'minus_formula',
        'at',
        'tab',
        'carriage_return',
        'negative_numeric',
        'positive_numeric',
    ];
    $rows = e1ParseCsv(app(ReportBuilderService::class)->exportToCsv([[
        'equals' => '=cmd',
        'plus' => '+SUM(1,1)',
        'minus_formula' => '-1+2',
        'at' => '@HYPERLINK',
        'tab' => "\tpayload",
        'carriage_return' => "\rpayload",
        'negative_numeric' => '-42.50',
        'positive_numeric' => '123',
    ]], $fields));

    expect($rows)->toHaveCount(2)
        ->and($rows[1])->toBe([
            "'=cmd",
            "'+SUM(1,1)",
            "'-1+2",
            "'@HYPERLINK",
            "'\tpayload",
            "'\rpayload",
            '-42.50',
            '123',
        ]);
});
