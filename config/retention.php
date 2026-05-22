<?php

/*
|--------------------------------------------------------------------------
| Retention policy defaults
|--------------------------------------------------------------------------
|
| Baseline retention windows used by `oblivion:prune-retention` when no
| matching `data_retention_policies` row exists. The Settings > Data &
| Privacy UI (DataSettingsController) writes those rows; the prune command
| reads them first and falls back to these defaults.
|
| Environment variables RETENTION_AUDIT_LOG_YEARS and
| RETENTION_TIMELINE_EVENT_YEARS override the config defaults in
| environments where a stored policy is not desired.
|
*/

return [
    'audit_log_years' => (int) env('RETENTION_AUDIT_LOG_YEARS', 2),

    'timeline_event_years' => (int) env('RETENTION_TIMELINE_EVENT_YEARS', 5),
];
