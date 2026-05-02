# Reports Permission Keys

`operations.reports.view` is the canonical permission for the Operations Reports hub under `/operations/reports`, including the Shift Operations report and secondary operations report pages.

`reports.viewAny` remains a legacy global reporting bypass for super-user/reporting-admin flows. Controllers that accept both keys should treat `reports.viewAny` as a broader bypass, not as the primary grant for new operations reporting UI. New operations report navigation, tests, and user-facing affordances should prefer `operations.reports.view`.

The legacy `/reports/shifts` URL is kept only as a compatibility redirect to `/operations/reports/shifts`; it should not grow a separate controller or React page again.
