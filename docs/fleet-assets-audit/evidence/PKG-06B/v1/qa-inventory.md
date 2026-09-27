# PKG-06B v1 — QA inventory before browser testing

Design-only synthetic candidate. Desktop web only. Expected first view: Asset identity, condition/hold, intended placement, accountable custodian and next receipt action are visible. Page scroll is intentional; no horizontal document overflow.

- Shell / navigation: seven published workspace names; profile's six connected groups and all 16 subviews; breadcrumbs, meter drill-ins, return/context links, collapse/expand, section Find. Boundary dialogs must label out-of-scope workspaces honestly.
- Custody: pending receipt; complete/incomplete/disputed outcomes; kit validation; assignment guard; transfer/loan/return review; expected date separate from receipt; departure/loss/damage; fail/retry; concurrent change; repeated click. Screenshot receipt and outcome.
- Maintenance: open/history; original check/template/version/actor/time; linked evidence; source duplicate/link failure/retry; work progress directly below Next action; notes collapse/draft/search; guarded Complete and terminal progress; hold remains separate. Screenshot original check and work.
- Documents: search/filter; file cover and source lineage; unavailable/quarantine; staging/browse/validation, progress/fail/retry; replacement and archive reason; draft close. Capture library and upload.
- Relationships: parent/component/kit history, missing removable item and prior component; Finance source decisions and no duplicated totals; retirement dependency block. Capture kit, Finance and retirement.
- States: read-only, direct-object denial, empty, unknown, stale/concurrent, archived, client-owned privacy, vehicle handoff. Direct-object denial must conceal asset details. Read-only controls must not mutate.
- Accessibility / visual: 1600×1000, 1280×900 and 1024×768 desktop resize; keyboard dialogs/picker Escape/focus; visible action footer; source and error wording; real browser zoom if supported (otherwise explicitly unverified). Check page errors and all network destinations.
- Exploratory: cancel an edited draft and verify committed fields unchanged; change receipt outcome with missing kit; reopen already received movement; failed upload with long filename; switch sections with unsent note.

UI simulation cannot prove production authorization, locking/idempotency, upload storage/scanning, physical custody, operational policy, Finance posting or cross-module runtime journeys. No test result is prefilled here.
