# Financial Insights object scope

Oblivion Findings is a single-tenant, multi-site application. `finance.dashboard`
allows a user to open Financial Insights surfaces; it does not grant access to
every Site or Client.

`FinancialInsightsScopeResolver` is the one object-scope decision point for the
Financial Insights JSON API and its matching server-rendered views. It returns
`denied` unless the user holds the base `finance.dashboard` capability, then
returns one of four explicit decisions:

| Decision | Meaning |
| --- | --- |
| `global` | The user holds the separately seeded `finance.insights.viewAllSites` permission. |
| `accessible_site` | The request is limited to the user's current active, non-archived Sites. |
| `client_relationship` | The live Client belongs to one of those accessible Sites and is linked to the user through the canonical `client_user` support-worker relationship. |
| `denied` | No qualifying Site or Client relationship exists. |

## Endpoint contract

| Endpoint family | Required decision | Data boundary |
| --- | --- | --- |
| `/finance/api/sites/{site}/financial-summary` and `/finance/sites/{site}/financial-dashboard` | `global` or matching `accessible_site` | The resolved route Site only. |
| `/finance/api/sites/{site}/budget`, `/variance`, `/variance/trend`, and `/forecast` | `global` or matching `accessible_site` | The resolved route Site only. |
| `/finance/api/clients/{client}/financial-summary`, `/ledger`, and `/finance/clients/{client}/financials` | `global` or matching `client_relationship` | The resolved live Client only. |
| `/finance/api/sites/overview`, `/kpis`, `/kpis/sites`, `/kpis/clients`, `/insights`, `/finance/sites`, and `/finance/executive-dashboard` | `global` or `accessible_site` | Only Site IDs returned by the resolver. Named Client rows are additionally limited to the resolver's live Client relationships. |
| `/finance/api/budgets`, `/variance`, and `/forecast` | `global` or `accessible_site` | Only Site IDs returned by the resolver. |

Wrong-Site, wrong-Client, missing, Site-less, inactive-Site, archived-Site, and
deleted-Client object requests all return the same generic 404 before any object
name, status, amount, count, or deleted-state field is serialized. Aggregate
requests with no authorised Site decision return a generic 403.

Deleted Clients cannot be opened through Financial Insights, even by a global
finance user. Historical deleted rows may contribute to period-correct occupancy
math inside an already authorised Site aggregate, but no deleted Client identity
or client-level financial payload is exposed.

Route IDs are authoritative. Query-string or body fields such as `site_id` or
`client_id` are not accepted as alternate dimensions and never replace the IDs
carried by the resolver decision.
