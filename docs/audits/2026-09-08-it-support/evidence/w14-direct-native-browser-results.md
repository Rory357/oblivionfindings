# W14 direct native monitoring browser verification — 12 September 2026

Scope: W14 / F06 / B04 / E13, current direct/native intake slice only. Full W14 and E13 remain incomplete.

## Environment and method

- Owned run `cb57a07bc03846b1`, fingerprint `90005a5b6ec4dd03273ea9fb72ea8df7ab29304ed088ddd32004ed8739a75017`, schema `oblivion_it_draft_browser_cb57a07bc03846b1`, server PID41300 on http://127.0.0.1:8766. Bootstrap32417 completed terminal0. Only synthetic records and fake notifications/providers.
- Read-only HTTP identity confirms the intended checkout, schema, normal CSRF, array mail, sync queue and manifest `a69df113659426996552d1b29389292d2ca87025d44632e35ed4dff80cd24fa8`; see `w14-direct-native-browser-identity.json`. Built app is app-BnOtWrxV.js; public/hot absent. The in-app browser reports ERR_BLOCKED_BY_CLIENT for the JSON identity route, but the actual application and sign-in routes load. No browser settings or security controls were changed.
- Real Codex in-app browser, owned tab1, existing desktop dimensions unchanged. Normal sign-in/out for synthetic technician, cover and requester. AX state read after each transition. Direct-ticket full-page screenshot inspected in the conversation: existing workspace hero, connected navigation, full-width linked context and readable sealed-evidence card; no cramped right-hand ticket column in this view. Screenshot is conversation evidence, not a saved local PNG. No external Chrome tabs touched.

## Observed journeys

- Technician: `/it/tickets/8?tab=links` renders Open, Direct to IT, integrity verified, evidence v2 and the actual Device link. No fabricated Control Room alert. Following the Device link opens `/security-devices/devices/1` successfully.
- Recovered case: `/it/tickets/9?tab=links` stays Open and displays Monitoring recovered with its timestamp and Technician resolution is still required. Original offline evidence remains sealed. Classification & ownership shows the actual synthetic team, fallback queue, accountable technician and distinct cover. Clicking Classification & ownership, pressing Tab then Return activates Linked records and updates its URL/selected tab correctly.
- Urgent case: `/it/tickets/10?tab=links` shows original High alert CR-2026-0001, evidence v1 and the actual source link. Following it opens `/control-room/alerts/1` with Open operational response.
- Site denial: the technician receives 404 for `/it/tickets/11?tab=links` (unapproved Site C); returning to permitted ticket8 restores the workspace.
- Cover role has Device entry/view but no Control Room alert permission. Ticket8 still renders direct evidence. Ticket10 remains usable, but hides the sealed alert snapshot/reference and replaces the source control with Control Room access is required to open this alert. Direct access to `/control-room/alerts/1` returns403; returning to ticket8 works.
- Requester: direct access to unrelated system ticket8 returns404. `/it` recovers normally and lists exactly the five base requester fixtures, with no monitoring tickets.

## Findings and limits

- Confirmed presentation gaps: automatically raised tickets say Raised by Unknown, and the direct-only evidence footer still refers to Control Room records below when none exist. Correct these in the next focused UI change.
- The canonical Device record is Healthy while the trigger observation is Offline. Source inspection confirms the evidence preserves Device.health_status separately from the native Monitor state; this fixture uses a manual Device record. Do not rewrite the immutable snapshot or infer recovery from that separate health field. Clarify that distinction in the evidence card; broader Device health aggregation remains a separately assessed source capability.
- These are local browser read/navigation/permission proofs, not real provider delivery acceptance. Current backend replay, recovery, maintenance and competing/interrupted worker proofs are separately recorded in `w14-direct-native-results.md`. Delivery failure/retry UI and complete Fleet/operator handoff remain open.
- Cleanup92562 completed terminal0 with the original fingerprint. Independent postflight confirms exact schema and owned directory absent; `w14-direct-native-browser-postflight.json`. Owned tab1 closed. No working-database mutation.
- Follow-up presentation edits identify source-system tickets without a requester as Raised automatically, distinguish recorded Device status/health from the monitoring observation, and avoid asserting that a direct-only ticket has Control Room records below. Existing evidence/context7 tests and workspace29 tests pass (3.64s and10.85s respectively); full TypeScript and scoped ESLint pass. All10 protected design files match the original baseline. Vite build4357 completed terminal0 in3m24s.

## Current-copy verification

- Fresh owned run `d8c24b736921a0ef`, fingerprint `c647a421f9597c18886b4fb62b09f461314480fa6562f04b810d06a5ecda44e8`, bootstrap3066 terminal0. Exact HTTP identity is in `w14-direct-native-browser-copy-identity.json`: current app-Co7HIszW.js, manifest2081cf4753fe63e1ef5416cb8d87998e212b86f9918a84d74f08880b6ee0cb03, correct checkout/disposable schema, normal CSRF and fake communications.
- Owned Codex in-app tab3, normal technician sign-in, unchanged desktop dimensions. Ticket8 Linked records displays Raised automatically, Direct to IT, Device status: Active, Recorded health: Healthy and the explanation distinguishing Device record values from the offline observation. Footer now refers conditionally to any linked Control Room alert. The screenshot was visually inspected in the conversation: text fits its existing card and header, with the sealed observation clearly separate. No immutable evidence was rewritten.
- Ticket1 retains Raised by W06 d8c24b736921a0ef requester and the normal conversation workspace. The conditional automatic-source label does not replace a human requester.
- These checks cover the three presentation corrections. First-build full role/source/recovery journeys above remain the evidence for unchanged behavior. Exact current18-file source hashes are in `w14-direct-native-browser-final-source-hashes.json`.
- Owned tab3 closed. Cleanup31714 completed terminal0 with the original fingerprint; independent postflight confirms exact schema and owned directory absent (`w14-direct-native-browser-copy-postflight.json`). No active owned runtime remains. Working database untouched.
