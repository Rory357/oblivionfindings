# 09 — UI, UX, accessibility and visual consistency

> Status: in progress. The complete static visual census is materialized, but **0 current-source rendered application instances** are credited. Every browser claim below is explicitly labelled.

Application source pin: `a0493442b9e392d324055c35bf25b69421dc2d35` (tree `f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1`).
Governing prompt SHA-256: `4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f`.

## Evidence boundary

- `Source-inferred`: committed TSX/component/trigger locators only.
- `Observed`: none attributable to the current application source pin.
- `Blocked`: all 2,812 static visual rows lack the required attributable role × Site × viewport × state browser observation.
- `Not safely reproducible`: the signed-in six-route sample is preserved only as an unknown-build observation and carries zero current-source credit.

## Hero and overlay census

| Pattern | Rows | Browser status | Finding links |
|---|---:|---|---|
| HERO_BANNER | 659 | Blocked | 0 / 659 |
| OVERLAY | 1,211 | Blocked | 0 / 1,211 |
| OVERLAY_TRIGGER | 942 | Blocked | 0 / 942 |
| **Total** | **2,812** | **2,812 Blocked** | **0 / 2,812** |

Static subtypes: `DECLARATIVE_TRIGGER` 115, `DIRECT_INLINE_TRIGGER` 689, `HERO_INSTANCE` 659, `NAMED_HANDLER_TRIGGER` 138, `OVERLAY_INSTANCE` 1,211.

| Static definition/trigger family | Count | Partition |
|---|---:|---|
| Hero definitions | 57 | primitive=2; wrapper=24; custom=31 |
| Overlay definitions | 473 | primitive=4; wrapper=272; custom_or_host=197 |
| Declarative overlay triggers | 115 | component trigger tags |
| Direct inline opening handlers | 689 | positive opening locators |
| Named-handler references | 138 | named positive opening locators |
| Gate 9 current static accounting | 1,415 | 473 definitions + 942 trigger sites; rendered verification remains 0 |

Overlay material-state locators: `controlled_expression` 795, `literal_open` 162, `conditional_mount` 155, `no_explicit_open_state` 99. These are source states, not role/Site/viewport browser observations.

## Ownership and linkage partitions

| Partition | Count | Interpretation |
|---|---:|---|
| page owner — `UNIQUE_STATIC_RENDER_ROOT` | 2,384 | static render-root relation only |
| page owner — `MULTIPLE_STATIC_RENDER_ROOTS` | 358 | static render-root relation only |
| page owner — `UNRESOLVED_STATIC_RENDER_ROOT` | 70 | static render-root relation only |
| route owner — `SOURCE_INFERRED_CONTROLLER_BASENAME_ROUTE_OWNER` | 2,712 | static route-owner relation only |
| route owner — `BLOCKED_NO_STATIC_ROUTE_OWNER` | 71 | static route-owner relation only |
| route owner — `DIRECT_ROUTE_RENDER_OWNER` | 29 | static route-owner relation only |
| candidate feature link — `SOURCE_INFERRED_ROUTE_OWNER_ANCHOR` | 1,473 | not final FEATURE-ID ownership |
| candidate feature link — `SOURCE_INFERRED_DIRECT_RENDER_OWNER_ANCHOR` | 728 | not final FEATURE-ID ownership |
| candidate feature link — `BLOCKED_NO_CURRENT_CANDIDATE_LINK` | 611 | not final FEATURE-ID ownership |

## Internal baseline census

| Internal baseline locator | Instances | Status |
|---|---:|---|
| `resources/js/components/ui/dialog.tsx:15` | 1,321 | source-inferred only |
| `NOT_ESTABLISHED` | 542 | source-inferred only |
| `resources/js/components/page/page-hero.tsx:417` | 527 | source-inferred only |
| `resources/js/components/ui/alert-dialog.tsx:7` | 225 | source-inferred only |
| `resources/js/components/ui/popover.tsx:6` | 99 | source-inferred only |
| `resources/js/components/command-centre/hero-kit.tsx:31` | 55 | source-inferred only |
| `resources/js/components/ui/sheet.tsx:15` | 32 | source-inferred only |
| `resources/js/components/ui/dialog.tsx:15; resources/js/components/ui/sheet.tsx:15` | 6 | source-inferred only |
| `resources/js/components/ui/alert-dialog.tsx:7; resources/js/components/ui/dialog.tsx:15` | 5 | source-inferred only |

The baseline census is not a rendered side-by-side comparison. In particular, `NOT_ESTABLISHED` means that this audit has not linked an internal gold standard; it does not prove inconsistency.

## Other required UI pattern families

| Pattern family | Current denominator | Current-source browser observation | Required closure |
|---|---:|---:|---|
| Primary/secondary navigation and mobile navigation | unknown | 0 | freeze implementations/destinations, roles, Sites, active/focus states and four-viewport behaviour |
| Page containers, breadcrumbs and tabs | unknown | 0 | classify hierarchy, duplicate destinations, overflow, keyboard order and canonical owner |
| Filters, searches, pickers and pagination | unknown | 0 | classify empty/loading/error/result states, Site/privacy scope and recovery |
| Cards, tables and mobile-card alternatives | unknown | 0 | classify responsive transforms, density, sorting, status semantics and horizontal overflow |
| Forms and validation | unknown | 0 | classify required fields, inline/summary errors, destructive confirmation, cancel and retry |
| Empty, loading, error, success and stale-data states | unknown | 0 | bind every material state to a route, role, Site, viewport and VISUAL-ID |
| Status badges, timelines, notifications and toasts | unknown | 0 | prove vocabulary, provenance, acknowledgement, undo/recovery and access-filtered projections |

These unknown denominators are explicit evidence gaps. The hero/overlay census must not be misrepresented as a complete design-system or accessibility audit.

## Responsive, state and accessibility coverage

| Required evidence | Current numerator | Denominator | Classification |
|---|---:|---:|---|
| Safely reachable routes at standard desktop width | 0 | unknown | Blocked |
| Selected families and journeys at 1440×900, 1280×800, 1024×768 and 390×844 | 0 | unknown | Blocked |
| Material visual states fully classified | not established | not established | Blocked |
| Material hero/overlay finding families independently resampled | 0 | 2 provisional unknown-build families | Not safely reproducible |
| Current-source WCAG 2.2 AA browser checks | 0 | unknown | Blocked |
| Redacted current-source screenshots | 0 | unknown | Blocked |
| H-feature ten-dimension current ease scores | 0 | 3,000 | Not measured |
| H-feature ten-dimension target ease scores | 0 | 3,000 | Not measured |

The unknown-build observation records 6 routes, 24 route/viewport cells, 5 pre-submit overlay families and 2 provisional candidates. It retained zero screenshots and changed zero records. Because no authoritative deployed commit/tree or build marker was established, it supplies **no current-source browser, responsive, accessibility, finding or ease credit**.

The later RUN-072 check selected 3 routes but stopped after `/my-day` redirected both available contexts to `/login`; authenticated cells remain 0 and no credentials or mutations occurred.

## RUN-084 current designated-application access preflight

The current controlled session is signed out. A navigation-only preflight observed the public home page and the login form; the login view was checked at 1280×720 with no page-level horizontal overflow and zero console warnings/errors. No credentials were read or entered, no form was submitted, no private record was opened, and no screenshot was retained. The target exposed no independently proven non-production marker or deployed commit/release identity.

This is public/login access evidence only. Signed-in application routes, representative role/Site behavior, responsive families, journeys, workflows, ease, rendered current-source visuals, runtime, tests, Pass, and completion all remain unobserved and zero-credit.

## Provisional pattern risks requiring attributable resampling

1. Focus restoration was a candidate across four unknown-build overlay families; trigger, initial focus, close mechanism and restored locator were not captured.
2. Escape-key behaviour was a candidate in one unknown-build employee overlay; the current source build and exact overlay owner were not proven.

Neither item is a current-source finding. The required resample must bind build identity, actor role, approved Site, safe fixture, exact VISUAL-ID/FEATURE-ID, all four viewports, material states, DOM/focus evidence and a redacted screenshot before independent review.

## Reusable native design-system recommendations

These are neutral audit recommendations, not application fixes:

- Declare one auditable hero/banner variant contract around existing Oblivion primitives and link every local exception to a reason; preserve current routes and wording unless a later remediation explicitly changes them.
- Route modal, dialog, drawer, sheet and popover behaviour through existing internal primitives with explicit trigger, focus, Escape, cancel, error, success and recovery contracts.
- Store role/Site/state/viewport evidence by VISUAL-ID so a screenshot can never stand alone without scope and root-cause analysis.
- Treat desktop/mobile layouts as the same workflow owner with responsive variants, not independent feature identities.
- Preserve necessary safety friction; improve clarity and recovery only after representative task evidence is measured.

## No-copy boundary

All eventual designs must be original native Oblivion implementations. External projects may contribute neutral user needs and verified behaviour references only; no source, assets, wording or distinctive layout may be copied.
