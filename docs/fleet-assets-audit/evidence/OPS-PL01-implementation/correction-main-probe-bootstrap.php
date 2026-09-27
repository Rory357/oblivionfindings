<?php
require getcwd().'/vendor/autoload.php';
// Keep the unchanged Main probe's relative 40-minute journey within its selected day.
\Carbon\Carbon::setTestNow('2026-09-27 00:00:00 UTC');
\Carbon\CarbonImmutable::setTestNow('2026-09-27 00:00:00 UTC');
