<?php

// Pure computation only: no application/database bootstrap or persistent state.
require dirname(__DIR__, 3).'/vendor/autoload.php';

use App\Domain\Referrals\Services\ReferralWorkingTime;
use Carbon\CarbonImmutable;

$calendar = ['timezone' => 'Pacific/Auckland', 'weekdays' => [1, 2, 3, 4, 5], 'opening_time' => '09:00', 'closing_time' => '17:00', 'holidays' => ['2026-01-01', '2026-01-02', '2026-10-26']];
// Independent day-by-day oracle using native timestamps, for unambiguous office boundaries.
$reference = function ($from, $to, $calendar) {
    $zone = new DateTimeZone($calendar['timezone']);
    $day = DateTimeImmutable::createFromInterface($from)->setTimezone($zone)->setTime(0, 0);
    $start = (float) $from->format('U.u');
    $end = (float) $to->format('U.u');
    $seconds = 0.0;
    while ($day->getTimestamp() < $end) {
        if (in_array((int) $day->format('N'), $calendar['weekdays'], true) && ! in_array($day->format('Y-m-d'), $calendar['holidays'], true)) {
            $open = new DateTimeImmutable($day->format('Y-m-d').' '.$calendar['opening_time'], $zone);
            $close = new DateTimeImmutable($day->format('Y-m-d').' '.$calendar['closing_time'], $zone);
            $seconds += max(0, min($end, $close->getTimestamp()) - max($start, $open->getTimestamp()));
        }
        $day = $day->modify('+1 day');
    }

    return $seconds;
};
$samples = [];
for ($i = 0; $i < 180; $i++) {
    $config = [...$calendar, 'timezone' => $i % 2 ? 'Pacific/Auckland' : 'Pacific/Chatham', 'weekdays' => $i % 3 ? [1, 2, 3, 4, 5] : [1, 3, 6, 7], 'opening_time' => $i % 4 ? '09:00' : '01:00', 'closing_time' => $i % 4 ? '17:00' : '04:00'];
    $start = CarbonImmutable::parse('2025-01-01T00:00:00Z')->addDays(($i * 37) % 620)->addMinutes(($i * 113) % 1440);
    $end = $start->addDays(($i * 71) % 730)->addMinutes(($i * 43) % 1440);
    $samples[] = [$start, $end, $config];
}
$started = hrtime(true);
$expected = [];
foreach ($samples as [$from,$to,$config]) {
    $expected[] = $reference($from, $to, $config);
}
$referenceMs = (hrtime(true) - $started) / 1e6;
$started = hrtime(true);
foreach ($samples as $index => [$from,$to,$config]) {
    $actual = ReferralWorkingTime::seconds($from, $to, $config);
    if ($actual === null || abs($actual - $expected[$index]) > 0.000001) {
        throw new RuntimeException('Calendar oracle mismatch at '.$index.': '.json_encode([$actual, $expected[$index]]));
    }
}
$optimizedMs = (hrtime(true) - $started) / 1e6;
$from = CarbonImmutable::parse('2020-01-01T00:00:00Z');
$to = CarbonImmutable::parse('2026-10-18T03:00:00Z');
$started = hrtime(true);
$checksum = 0;
for ($i = 0; $i < 10000; $i++) {
    $checksum += ReferralWorkingTime::seconds($from->addMinutes($i % 1440), $to, $calendar);
}
echo json_encode(['passed' => 180, 'reference_ms' => round($referenceMs, 2), 'optimized_ms' => round($optimizedMs, 2), 'six_year_intervals' => 10000, 'ten_thousand_ms' => round((hrtime(true) - $started) / 1e6, 2), 'checksum' => $checksum]).PHP_EOL;
