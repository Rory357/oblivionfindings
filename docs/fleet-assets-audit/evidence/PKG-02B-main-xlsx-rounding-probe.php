<?php

// Main review probe: pure workbook generation, synthetic data, no application bootstrap or database.
require 'C:/Users/steph/.codex/worktrees/pkg02b-final-audit/oblivionfindings/vendor/autoload.php';

$trips = [];
foreach ([40, 40] as $index => $seconds) {
    $trips[] = [
        'reference' => 'SYNTHETIC-'.($index + 1), 'date_iso' => '2026-09-26',
        'start_time' => '10:00', 'end_time' => '10:01', 'driver_name' => 'Synthetic driver',
        'driver_status' => 'Confirmed', 'from' => 'Synthetic A', 'to' => 'Synthetic B',
        'distance_km' => 0.1, 'minutes' => (int) round($seconds / 60), 'max_speed_kph' => null,
        'coverage_pct' => 100, 'driving_events' => 0, 'score' => null, 'score_label' => 'Not scored',
        'source_note' => 'Synthetic review evidence only',
    ];
}
$data = [
    'brand' => ['name' => 'Synthetic review', 'colour' => '#7c3aed', 'logo' => null],
    'vehicle_line' => 'Synthetic vehicle', 'range_label' => '26 Sep 2026', 'timezone' => 'Pacific/Auckland',
    'filters_line' => 'Synthetic', 'scope_note' => 'Synthetic', 'generated_label' => 'Review probe',
    'trips' => $trips, 'totals' => ['distance_km' => 0.2, 'minutes' => (int) round(80 / 60)],
    'include_events' => false, 'include_routes' => false, 'notes' => [],
];
$bytes = (new App\Services\Fleet\VehicleTripWorkbook)->bytes($data);
$path = tempnam(sys_get_temp_dir(), 'main-pkg02b-rounding-');
try {
    file_put_contents($path, $bytes);
    $zip = new ZipArchive;
    $zip->open($path);
    $xml = simplexml_load_string($zip->getFromName('xl/worksheets/sheet1.xml'));
    $zip->close();
    $xml->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $first = $xml->xpath('//s:c[@r="J8"]')[0];
    $second = $xml->xpath('//s:c[@r="J9"]')[0];
    $total = $xml->xpath('//s:c[@r="J10"]')[0];
    echo json_encode([
        'input_trip_seconds' => [40, 40],
        'row_minutes' => [(int) $first->v, (int) $second->v],
        'total_formula' => (string) $total->f,
        'cached_total_minutes' => (int) $total->v,
        'formula_recalculation_minutes' => (int) $first->v + (int) $second->v,
        'database_bootstrapped' => false,
    ], JSON_PRETTY_PRINT).PHP_EOL;
} finally {
    if (is_file($path)) {
        unlink($path);
    }
}
