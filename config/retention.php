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
    // 5 years: what Settings > Data shows as the default (readiness audit B6/F7, 6 Nov 2026). H&S records' history is
    // kept at least 10 years whatever this says (AuditLog::HEALTH_SAFETY_MIN_RETENTION_YEARS).
    'audit_log_years' => (int) env('RETENTION_AUDIT_LOG_YEARS', 5),

    'timeline_event_years' => (int) env('RETENTION_TIMELINE_EVENT_YEARS', 5),
];
