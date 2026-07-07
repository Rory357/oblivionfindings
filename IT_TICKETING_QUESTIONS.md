# IT Ticketing — deferred decisions & questions for Chane

Questions the loop could not settle from the prompt alone, each with the
decision taken (so shipping never blocked) and what to change if you disagree.

## 1. `it.request` and the external portal personas (decided — staff only)

§B/§P.9 say grant `it.request` to "all roles", but the mission line is
"everyone on **staff**". The `client` and `next_of_kin` roles are external
portal personas (they get a dedicated portal sidebar and a four-permission
grant set) — giving them the internal IT helpdesk would leak staff tooling
into the family portal.

**Decision:** granted to every role EXCEPT `client` and `next_of_kin`, in both
`RbacSeeder` and migration `2026_07_07_100001_grant_it_request_permission`
(dynamic over the roles table, so niche/exec roles are covered). A Pest test
pins the exclusion. If you DO want portal users raising IT tickets, delete the
two names from the exclusion lists in those files and drop the
"external portal personas hold no it.request grant" test.

## 2. SLA clocks run 24/7 — no business-hours calendar (v1, per §G)

The SLA engine (stamping, `it:check-sla`, the admin target editor) counts
every minute of every day. A "normal · 1440 min" ticket raised Friday 17:00
is due Saturday 17:00 — weekends and NZ public holidays count against the
target. §G explicitly scopes v1 this way and marks business-hours calendars
as a stretch decision.

**Recommendation if/when wanted:** add `business_hours` (start/end,
days-of-week, holiday list) to `it_sla_policies` — schema change, so it needs
a §P-style sign-off — and teach `stampSlaDueDates()` + `it:check-sla` +
`resolveTicket()`'s met-check to count only working minutes. Until then the
mitigation is generous targets (the editor at Tickets → SLA targets, admin
only, makes them per-tenant editable). The editor's copy states the 24/7
behaviour so nobody assumes otherwise.
