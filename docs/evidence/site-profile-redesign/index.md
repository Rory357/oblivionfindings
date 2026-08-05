# Site Profile redesign browser evidence

**Verification window:** 2026-07-18 20:25 to 2026-07-19 01:26 NZST

**Host:** `http://127.0.0.1:8765`

**Worktree:** `C:\Users\steph\.config\superpowers\worktrees\oblivionfindings\codex-site-profile-redesign`

**Branch:** `codex/site-profile-redesign`

**Starting HEAD:** `50b7a6ba53e23e97e5e6df7c4b652933000998fd`

**Browser:** Codex in-app browser, final tabs closed
**Desktop viewport:** 1440 x 900 unless stated otherwise

The host identity was confirmed from the isolated worktree, its generated Vite assets, and uniquely named Site Profile QA fixtures before screenshots were accepted. No production or deployed environment was changed.

## Variant coverage

| Variant | Evidence | Result |
| --- | --- | --- |
| Branded house | `branded-house-1440x900.png` | Site brand `#4F46E5` controlled the hero. |
| Organisation fallback | `unbranded-house-1440x900.png` | Missing Site colour resolved to `var(--primary)`, not the green Sites category colour. |
| Day-service hub | `day-service-hub-1440x900.png` | Facility labels and navigation rendered through the shared typed registry. |
| Head office | `head-office-1440x900.png` | Client, shift-coverage, and meal-planner actions/tabs were absent; office-relevant sections remained. |
| Restricted viewer | `restricted-viewer-1440x900.png` | Profile rendered with all four mutation shortcuts absent; protected deep links safely selected Overview without protected links or records. |
| Narrow web | `branded-house-390x844.png` | No page-level horizontal overflow (`body` and `main` scroll width 375 px); hero/cards stacked and the group rail remained horizontally scrollable. |

## Interaction coverage

- Group navigation, tier-two tabs, `?tab=` deep links, Readiness review navigation, attention-row navigation, and the `/` section finder were exercised.
- Escape closed the finder and returned focus to the visible Find control.
- Pinning Checklists persisted after reload through `user_ui_preferences`; the test pin was removed afterwards.
- Add Client opened the complete shared eight-step Client wizard with the Site preselected (`canonical-client-create-modal-1440x900.png`). Link existing client opened the separate compact placement workflow; no duplicate quick-create flow appeared.
- Checklists remained a compact summary linking to `/sites/9401/checklists`.
- Finance linked to `/finance/sites/9401/financial-dashboard` and the named Site ledger; Vendors linked to `/vendors?site_id=9401`.
- The restricted viewer had no Edit Site, Add Client, Add Event, or Report Hazard shortcut. Direct `residents`, `financials`, and `vendors` tab requests exposed no protected canonical links and selected the safe Overview panel.
- The restricted Inertia page contained only shell/Overview/Readiness props. A key-path audit found permission flags, translation labels, and the existing public maps configuration only; no credential record or credential secret was present. Backend payload tests provide the authoritative secret non-disclosure assertion.
- A controlled local-server outage proved the deferred Hazards request settles on the labelled `Could not load Hazards` alert with one `Try again` button. Restoring the server and clicking the button loaded the canonical Hazard summary once (`deferred-retry-recovered-1280x720.png`) with no new console error from the handled request.

## Observed defects fixed during verification

1. The Site index and Add Site reference payload admitted foreign/unscoped tenant records. Queries now fail closed for non-platform users and scope Sites, users, and service contexts to the viewer's organisation. A red/green authorization regression test covers the boundary.
2. A Site without `brand_colour` still inherited the green category hue. The hero now explicitly falls back to the organisation `--primary` token, with a frontend regression assertion.
3. A failed deferred request first retriggered indefinitely and then remained stuck on Loading because network exceptions do not use Inertia's validation-error callback. The loader now deduplicates in-flight requests, handles only the matching partial-request exception, renders Retry, and recovers through a single forced reload.

## Cleanup and console result

Synthetic Sites `9404`, `9405`, and `9406` and synthetic viewer `245` were identity-checked and permanently removed after evidence capture. A follow-up query returned zero remaining records. The ordinary desktop, narrow, and restricted passes ended with no console warnings or errors. Network errors recorded while deliberately stopping the local server were part of the retry test; the corrected request was handled without adding a new console error.

## Files

- `branded-house-1440x900.png`
- `branded-house-390x844.png`
- `canonical-client-create-modal-1440x900.png`
- `day-service-hub-1440x900.png`
- `deferred-retry-recovered-1280x720.png`
- `head-office-1440x900.png`
- `restricted-viewer-1440x900.png`
- `unbranded-house-1440x900.png`
