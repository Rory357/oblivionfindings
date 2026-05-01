# Control Room Service Notes

The Control Room module requires MySQL in production and CI.

Several hot paths intentionally use MySQL-specific SQL fragments:

- `JSON_UNQUOTE(JSON_EXTRACT(...))` in `SignalProcessingService` for signal correlation.
- `FIELD(severity, ...)` in alert ordering for operational priority.
- `FIELD(id, ...)` in automation ordering.

Do not run Control Room production-readiness checks on SQLite and treat any
SQLite-only pass as incomplete coverage for signal-correlation behavior.
