# UI, UX, accessibility and visual consistency

## Evidence boundary

The retained browser evidence includes the owner-confirmed test/development impersonation pass plus a later direct-login pass at historical browser-evidence pin `ad19f994a280835d039d1a31ebdcb05778733c5a` for the synthetic Clinical/Medication Lead. Together they sample 12/12 required actor classes. The historical-pin pass rendered Health & Clinical and eMAR at 390×844, 1024×768, 1280×800 and 1440×900 and submitted no domain forms. This closes actor entry only: full task, failure, recovery, denied-state and handoff coverage remains blocked. The older deployed-UI observations still lack a deployed source identity and are not silently attributed to current source.

A fresh frozen-baseline preflight did build and serve exact audited commit `081ef198f9f992f224e8c0c9fba33df33dde40be`, with pinned source and emitted asset hashes, for the four Control Room handover rows `VIS-000101`–`VIS-000104`. The safe GET to `/control-room/shifts` redirected to `/login` because no existing authenticated browser session was available. No credential, session, shift ID or fixture was manufactured, so the handover page was not rendered and all four rows retain zero new visual credit; see `evidence/browser/frozen-baseline-control-room-handover-preflight-2026-08-21.json`.

## Coverage

- 622/622 routes in the audit-defined one-admin safe set were observed at standard desktop width: 578 standard plus 44 parameterised. This is 622/1,211 (51.36%) of all user-facing GET templates. A further 81 observations were non-page/SSE and are not counted as rendered-page coverage.
- Parameterised user-facing GETs: 44/483 rendered (9.11%); 79 were non-page/not safely reproducible and 359 had no safe discovered demo link.
- The retained browser crawl observed 300/543 legacy user-facing controller/closure families. Against the working 788-human-capability denominator, no exact capability-level entry numerator is inferred where a page is shared; the old 55.2% is historical evidence, not stable-ID capability coverage.
- 470 selected pages/components × 4 required viewports = 1,880 required rows. Matrix 05 now retains all 1,880/1,880 required audited-baseline rows and 1,876/1,880 (99.79%) are fully measured. `VIS-001862` is restored from the pinned raw System Users 1280×800 observation; the later 1280×720 HR remediation screenshot remains separate finding evidence. Four audited-baseline Control Room handover rows have lightweight evidence without full geometry, so 469/470 families are fully measured. A supplemental pass at historical browser-evidence pin `ad19f994…` measured the materially changed handover page at 1440×900, 1280×800, 1024×768 and 390×844 with `scrollWidth == clientWidth` at each viewport; source blob drift prevents using those later measurements as immutable-baseline credit.
- The eight common/safety-critical journeys were source-cross-reviewed, but 0/8 were executed at all four viewports. Bounded actor entry is 12/12; this is not canonical task or journey completion.
- Horizontal-overflow observations: 2/470 at 1440, 4/470 at 1280, 9/470 at 1024 and 72/470 at 390.
- The invalid 8,688-row Cartesian state claim is stale. The current matrix contains **4,312** applicability-derived rows based only on exact routed actions/names, methods, access/parameter signals and mapped page presence. Final-ID reconciliation assigns 3,948 rows to 715 unique final capabilities and leaves 364 rows unresolved (91.56% assigned). Every row remains unexecuted. Across the whole 8,753-row visual matrix, 8,168 rows are assigned to 774 unique final IDs and 585 remain unresolved. Static linkage is not browser-state completion.
- Sixty PNGs are retained. Eight screenshots have a structured sidecar for the direct Clinical Lead pass and show Health & Clinical/eMAR at all four required viewports. Four additional screenshots plus `current-main-control-room-handover-viewport-evidence.json` record the later historical-pin handover pass. Existing matrix screenshot linkage remains six rows because these bounded post-matrix passes do not silently rewrite the 8,753-row audited census. The eMAR 390×844 image and DOM geometry prove a new page-level overflow; the historical-pin handover images show no document overflow but cannot fill the changed component's audited-baseline geometry gap. Screenshots do not establish task completion, system-wide keyboard rates, hero first-work distances or dialog behavior.

## Hero/banner census

| Layer | Total | Reachability | Classification |
|---|---:|---:|---|
| Direct `PageHero` sites | 521 | Per-row reachability not retained in the final census | 521/521 source-classified; 0 per-instance browser proofs |
| Genuine custom/legacy hero/banner usages | 85 across 38 symbols | Per-row reachability not retained in the final census | 85/85 source-classified; 0 per-instance browser proofs |
| Wrapper references | 50 | reference-only | separate; never summed into the primary instance denominator |

The source census is complete, but all 606 primary rows have blank route and screenshot fields. A proxy join associates 323/521 PageHero sites and 38/85 custom usages—361/606 (59.57%)—with an observed component page; that does not prove the individual instance rendered or locate the first actionable work. Earlier exact pixel claims are removed because no retained per-instance measurement/screenshot identifies the boundary. The correct next review is not universal sameness: retain the My Day avatar and justified resident/clinical identity context while testing compact task-page versus richer dashboard variants in the existing PageHero system.

## Overlay census

| Layer | Total | Reachability / result |
|---|---:|---|
| Primitive overlay roots | 477 | 477/477 classified: 242 exact static trigger/state relations, 235 unresolved |
| Explicit primitive triggers | 146 | All 146 nodes pair statically into 145 roots; runtime behavior untested |
| Genuine custom overlay JSX usages | 659 / 417 symbols | 659/659 classified: 253 exact static trigger relations, 144 source-inferred candidates, 262 unresolved/blocked; 654 reachable / 5 unreachable |

The Pass-8 parser re-count corrected the earlier invalid 672/420 lexical result: 19 TypeScript generics and one comment had been mistaken for JSX. All 659 custom usages now have a static classification; independently reviewed static waves resolve bounded state→handler→trigger→open chains, so 253 have exact static trigger relations, 144 are source-inferred candidates and 262 remain unresolved/blocked. Static resolution is not runtime reachability, focus, Escape, scroll lock, teardown or restoration proof. The separate mobile-navigation record supports one custom-overlay failure; prior claims without structured per-instance logs remain excluded.

## Five widespread visual/interaction problems

1. **Responsive overflow:** 72/470 selected components overflowed the document at 390px. H&S Corrective Actions measured 1,518px scroll width against 373px client width ([BVIS-0004](evidence/browser/BVIS-0004-health-safety-corrective-actions-390x844-overflow-cropped.png)). A fresh exact My Day check measured 457px scroll width against 373px client width and traced the dominant 84px overflow to the non-wrapping StaffHeader action group ([BVIS-0010](evidence/browser/BVIS-0010-my-day-390x844-header-overflow-cropped.png)). Baseline: responsive card/table and header-action conventions with no document-level overflow.
2. **Hero governance evidence gap:** 606 source instances have no per-instance browser proof or first-work measurement. Source composition suggests density risk, but the denominator of materially dense instances is unknown. Baseline: governed compact task-page versus dashboard variants, with explicit exceptions and measured first-action distance.
3. **Overlay trigger/focus evidence gap:** 262/659 custom usages remain unresolved/blocked and 144/659 are only source-inferred candidates; even the 253 exact static relations do not establish runtime restoration or failure rate. The mobile app sidebar is the one retained structured interaction failure and visible-open screenshot ([BVIS-0008](evidence/browser/BVIS-0008-dashboard-mobile-navigation-overlay-390x844-observed-cropped.png)). Baseline: shared Radix Dialog/Sheet with name, trap, body lock and restoration.
4. **Control Room settings initial-page accessibility/reflow:** automated signals report 28 potentially unnamed controls at 1024 and 390; at 390, scroll width is 712px versus 373px client width. Both observations have `dialogCount=0`, so no create-rule-dialog overflow is claimed. Manual name adjudication and the opened dialog remain blocked.
5. **Long operational surfaces and deployed provenance:** structured observation `VIS-000048` records Control Room Alerts index at 11,910px height on 390×844 (~14.1 viewports). Audited source/deployed causation is blocked because the site exposes no build SHA; no screenshot proves a card/table drift claim.

## Internal standards and acceptance

- Continue using the existing PageHero/variant/category system; govern compact task/worklist versus dashboard uses.
- Use Clients → Add Client and existing WizardShell patterns only as internal structural baselines, not as license to duplicate every dialog.
- One clear primary action per state; preserve person/site/status/freshness; show queued, failed, retry and amendment states.
- All overlays: named title/description, initial focus, trap, Escape/backdrop, body lock, restore, unsaved-change behavior, safe internal scroll and reachable footer.
- All responsive pages: `scrollWidth <= clientWidth` at 390px unless a deliberately labelled local data scroller contains the overflow; no document-wide pan.
- Add keyboard/a11y/visual checks at all four viewports and each representative role. Hidden UI never substitutes for server authorization.

Retained visual findings: `VIS-MOBILE-NAV-01`, `VIS-RESPONSIVE-OVERFLOW-01`, `VIS-HERO-DENSITY-01`, `VIS-OVERLAY-FOCUS-01`, `VIS-CR-SETTINGS-NAMES-01`, `VIS-DEPLOYED-DRIFT-01`, `VIS-SYSTEM-USERS-COUNT-01`, `VIS-MY-DAY-HEADER-OVERFLOW-01`, plus `INCIDENT-RECOVERY-01` for interruption recovery. The four material hero/overlay finding families have a reproducible independent-resample denominator. A read-only pass on historical browser-evidence pin `ad19f994a280835d039d1a31ebdcb05778733c5a` sampled 4/4: mobile navigation, overlay focus and incident recovery reproduced, while task-first hero distance partially reproduced. The audited-baseline numerator remains 0/4. A later exact-`081ef198…` preflight successfully installed the pinned dependencies, built and served exact audited assets, but `/control-room/shifts` redirected to login and no existing authenticated session or safe Active-shift fixture was available; no baseline interaction was credited. See `evidence/browser/current-main-visual-family-resample-2026-08-14.json` and `evidence/browser/frozen-baseline-control-room-handover-preflight-2026-08-21.json`. The My Day finding is browser-observed, screenshot-retained and source-traced; Support Worker repetition remains pending.
