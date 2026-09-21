# PKG-02A v1 — design QA

Final synthetic static build at `http://127.0.0.1:4332/PKG-02A/v1/`. Verified through the real Codex in-app browser on 20 September 2026. Baseline 2302; required model Astra Extra High. This record covers the mockup, not backend behavior or production acceptance.

## Verified

- **40 state/viewport checks:** each of the 20 review states at 1280×800 and 1440×900, actual dimensions recorded in `state-checks.json`. No document horizontal overflow; no internal header scrolling after the focus fix. Restricted states omit map/history; unavailable/empty states do not fabricate a current observation. Representative screenshots were inspected visually.
- **Profile and context:** preserved profile identity, five useful meters, grouped navigation and accurate Snapshot ordering; Location ↔ contextual Consents navigation; assignment source dialog. Sensitive contextual navigation from a denied actor remains denied. Contextual destinations remain labelled synthetic and outside this bounded implementation.
- **Observations:** timestamp/timezone/age/source visible; refresh changes the check time from 2:35 to 2:36 without changing the 2:32 observation (age changes from 3 to 4 minutes); older observations remain explicitly old; unknown accuracy supplies no map accuracy area. Active registry status is separate from last contact. Map zoom/reset/summary controls exercised during the design pass.
- **Authority details:** missing decision supplies no fabricated evidence ID; wrong-purpose example identifies photography; expired collection and sharing examples have past end times; withdrawn/reassigned cases clear observations. Collection, staff and recipient decisions stay distinct.
- **History:** expand and query, calendar selection, reversed-date validation, visible empty/loading/failure states, retained date range and retry; retry restores three synthetic rows; access-ended response clears both current map and history immediately.
- **Limited actor:** request, export and grant-changing controls unavailable; an explanatory sharing summary can remain visible. Denied actors cannot navigate to sensitive consent, assignment or named-recipient information.
- **Recipient picker:** blank required-field error focuses the invalid combobox with its error association; search/no match, failed search/retry and denied-directory states; retained selection; existing illustrative grant pre-fills the same review wizard. New reviews start blank.
- **Wizard:** named account, explicit purpose/scope, all time bounds, canonical evidence, review/back navigation, dirty-close Keep editing/Discard; exact 01:17 PM input retained; changing minutes to 28 then Escape cancels that picker only; browsing to next month then Cancel retains 20 September; partial inputs cannot pass review. Simulated review failure preserves values; retry ends with “Review prepared. No access change.” Closing returns keyboard focus to Review sharing.
- **Withdrawal:** missing reason error and focus, local named-recipient withdrawal, explicit ended state while collection/staff observation remains. This simulates no server write, evidence deletion or new policy.
- **Rendering:** required desktop screenshots include page top, observation/history detail, denied state, reciprocal Consents, time fields, calendar, clock, review failure and outcome. Modal body scrolls independently and the footer is reachable. Shared baseline PageHeader, WizardShell, searchable record selector pattern, calendar and exact-minute clock patterns are reused.
- **Build/isolation:** Vite build passes (2456 modules). Preview bundle size warning is recorded; this standalone design bundle imports shared application components and is not a production bundle change. Static server identity/no-store/CSP verified. Fresh final-build tab has no captured console warnings/errors (`console-check.json`). Tracked application/source/guide diff is empty.

## Corrections found during QA

- Included the imported component source paths in preview Tailwind generation so shared wizard/calendar/clock styles render fully.
- Access-ended history now removes the entire protected view, not only its rows; denied contextual actions use the same restricted path.
- Corrected expired dates, missing/wrong-purpose authority references and unknown map accuracy.
- Review outcome describes no access change even when reviewing an already illustrated grant.
- Added explicit wizard focus return and the existing My Day `overflow: clip` treatment to the preview header. The latter prevents decorative overflow from scrolling the client's name out of the header when a tab is focused. No shared source was edited.

## Limitations and evidence discipline

Actual **200% browser zoom is unverified**: Ctrl+plus and Ctrl+equal do not change the available in-app browser's viewport/device-pixel ratio, and its exposed controls include no zoom API. A **640×400 reduced desktop viewport** was checked as a reflow approximation of a 1280×800 screen at 200%; it is not a mobile deliverable or proof of actual zoom. The modal and footer remain within that viewport. Real browser zoom remains a later verification item.

Exploratory 1366×768 and early full-page captures under `output/playwright/pkg-02a-v1` are excluded from the freeze. In-app full-page capture omitted offscreen paint and early captures exposed the now-fixed internal header scroll. Only the settled viewport screenshots under this evidence directory are review artifacts. Old Vite/HMR console messages from the development session are not final-build errors; the clean final static session is separately recorded.

No screen-reader audit, production API test, permission proof, portal grant enforcement, concurrency race test, DST resolution, realtime/jobs/cache invalidation, live export/device command, privacy-owner approval or implementation acceptance is claimed. Exact mockup approval, PKG-01 closure and applicable policy/technical gates remain open.
