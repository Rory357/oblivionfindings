<?php

// Independent synthetic workbook probe; no Laravel bootstrap or database writes.
require 'C:/Users/steph/.codex/worktrees/pkg02b-final-audit/oblivionfindings/vendor/autoload.php';

$results = [];
foreach ([[40, 40], [20, 20], [29, 31, 61]] as $case => $durations) {
    $trips = [];
    foreach ($durations as $index => $seconds) {
        $trips[] = [
            'reference' => 'SYNTHETIC-'.($index + 1), 'date_iso' => '2026-09-26',
            'start_time' => '10:00', 'end_time' => '10:01', 'driver_name' => 'Synthetic driver',
            'driver_status' => 'Confirmed', 'from' => 'Synthetic A', 'to' => 'Synthetic B',
            'distance_km' => 0.1, 'minutes' => (int) round($seconds / 60), 'duration_seconds' => $seconds,
            'max_speed_kph' => null, 'coverage_pct' => 100, 'driving_events' => 0,
            'score' => null, 'score_label' => 'Not scored', 'source_note' => 'Synthetic review evidence only',
        ];
    }
    $data = [
        'brand' => ['name' => 'Synthetic review', 'colour' => '#7c3aed', 'logo' => null],
        'vehicle_line' => 'Synthetic vehicle', 'range_label' => '26 Sep 2026', 'timezone' => 'Pacific/Auckland',
        'filters_line' => 'Synthetic', 'scope_note' => 'Synthetic', 'generated_label' => 'Review probe',
        'trips' => $trips, 'totals' => ['distance_km' => count($durations) / 10,
            'minutes' => (int) round(array_sum($durations) / 60), 'duration_seconds' => array_sum($durations)],
        'include_events' => false, 'include_routes' => false, 'notes' => [],
    ];
    $file = __DIR__.'/final-01-case-'.$case.'.xlsx';
    file_put_contents($file, (new App\Services\Fleet\VehicleTripWorkbook)->bytes($data));
    $zip = new ZipArchive;
    $zip->open($file);
    $xml = simplexml_load_string($zip->getFromName('xl/worksheets/sheet1.xml'));
    $zip->close();
    $xml->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $cells = $xml->xpath('//s:c[starts-with(@r,"J") and not(@t)]');
    $total = array_pop($cells);
    $rows = array_map(fn ($cell) => (float) $cell->v, $cells);
    $results[] = [
        'file' => basename($file), 'input_trip_seconds' => $durations,
        'row_minutes' => $rows, 'total_formula' => (string) $total->f,
        'cached_total_minutes' => (float) $total->v, 'independent_sum_minutes' => array_sum($rows),
        'recorded_total_seconds' => array_sum($durations), 'database_bootstrapped' => false,
    ];
    if (abs(array_sum($rows) * 60 - array_sum($durations)) > 0.00000001
        || abs((float) $total->v * 60 - array_sum($durations)) > 0.00000001) {
        throw new RuntimeException('Duration precision failed.');
    }
}
file_put_contents(__DIR__.'/final-01-probe.json', json_encode($results, JSON_PRETTY_PRINT).PHP_EOL);
echo json_encode($results, JSON_PRETTY_PRINT).PHP_EOL;
