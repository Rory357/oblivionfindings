<?php

declare(strict_types=1);

/**
 * Regenerate the exact no-regression snapshot used by the IT, Security &
 * Devices, and Monitoring single-application architecture test.
 *
 * Usage: php scripts/update-it-security-tenant-debt-baseline.php
 */
$root = str_replace('\\', '/', dirname(__DIR__));
$testPath = $root.'/tests/Architecture/ItSecuritySingleTenantBoundaryTest.php';
$source = file_get_contents($testPath);

if ($source === false) {
    throw new RuntimeException("Unable to read {$testPath}.");
}

$scannerStart = strpos($source, 'function itSecurityTenantDebtSnapshot');
$baselineStart = strpos($source, 'function itSecurityApprovedTenantDebt');

if ($scannerStart === false || $baselineStart === false || $scannerStart >= $baselineStart) {
    throw new RuntimeException('Unable to locate the architecture scanner and approved baseline.');
}

// Load only the pure scanner helpers. The Pest test declarations above them
// and the existing approved baseline are deliberately excluded.
eval(substr($source, $scannerStart, $baselineStart - $scannerStart));

$snapshot = itSecurityTenantDebtSnapshot($root);
$rows = array_map(
    static fn (string $row): string => '        '.var_export($row, true).',',
    $snapshot,
);
$replacement = "function itSecurityApprovedTenantDebt(): array\n{\n    return [\n"
    .implode("\n", $rows)
    ."\n    ];\n}\n";
$updated = substr($source, 0, $baselineStart).$replacement;

if (file_put_contents($testPath, $updated) === false) {
    throw new RuntimeException("Unable to update {$testPath}.");
}

$occurrences = array_sum(array_map(
    static fn (string $row): int => (int) explode('|', $row)[2],
    $snapshot,
));

fwrite(STDOUT, sprintf(
    "Updated tenant debt baseline: %d path-rule entries / %d occurrences.\n",
    count($snapshot),
    $occurrences,
));
