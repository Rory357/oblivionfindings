# eMAR header, navigation and modal corrections

4 October 2026, Pacific/Auckland. Follow-up to the [dated re-audit](hero-tabs-reaudit-2026-10-04/README.md). Main owns every frontend change in this pass; the existing GPT-6.1 Sol Extra high backend agent owns the bounded controller/payload repairs. Work remains on draft PR #16, not deployed.

Application commits: backend `26c2161d3`; frontend `daabfaaa2`. Verification below covers their resulting source. The temporary legacy preview on port 8767 was stopped after review; the existing synthetic P02 preview on 8765 remains available.

## Implemented

- Replaced the reachable legacy headers on Stock, Rounds, Handovers, MAR, Medicines, PRN and retained Prescriptions with `PageHeader`. Kept each page's actions, safety messages, role checks and data sources. Removed greetings/LIVE claims, repeated outer gutters and the legacy white page-level tab strips.
- Kept seven sidebar hubs. List scopes now sit inside the header; true person-record sections use bare position-coloured tabs. Settings retains its expressly required sub-tabs, now with the shared 36px strip, 18px circular active icon and 3px underline. Keyboard navigation and effective touch targets remain.
- Added or corrected real linked meter anatomy on Orders, history and downtime; moved the working history date form into a header popover. Moved existing scoped searches to header actions and added a clearly labelled search of the loaded change log. Kept empty states factual and date labels valid across NZ daylight saving changes.
- Supplied the controlled register's selected, authorised house colour through both page and refreshed reader responses. Returning to all houses clears the colour; foreign houses remain denied. No clinical reader scope or capability changes.
- Medication dialogs now reuse the canonical wizard and simple-dialog shell. Fixed titles and footers surround scrollable bodies, with explicit responsive widths. Medication forms opt in to actual 44px buttons and input heights without changing unrelated forms.
- Converted the audit detail drawer to the actual shared section shell while preserving its scroll spy, flags, integrity checks and record links. Normalised round details, audit export, scheduled counts, support and downtime evidence dialogs.
- Added real form dirtiness/request guards and a focused server-error summary to the migrated forms, including nested footer cancellation. Split the ten-field INR form into Result, Instruction and Review; the same request UUID, supplied values and server command remain. Alert and reminder forms have separate sections and keep their distinct commands.
- Hardened pharmacy order dependent field updates to use the current form state. The regression checks person-specific medicine choices and progression to order details. No pharmacy order was placed during browser review.
- Fixed Reviews → To check/Reconciliation destinations and backend selector normalisation, including aliases, malformed input and safe defaults. The backend retains site/person/search scope for its reviews redirect.
- Restored PRN client-house projection, rejected zero-row imports honestly and reported actual CSV import and round-generation counts. Removed the corresponding unconditional frontend success toasts.

No feature flag, permission grant, administration authority, clinical writer, operational data or primary checkout was changed. The small INR and alert presentation changes retain the existing clinical commands and server validation.

## Verification

Backend: **82 tests / 1,577 assertions passed** across the independent Orders workflow (36/769), order verification/import (19/242), PRN plus round templates (14/315), and controlled branding/privacy (13/251) runs. Each used its own synthetic database. PHP syntax, Pint and whitespace checks passed. These are bounded runs, not whole-module acceptance.

Frontend: **431 tests across 62 files passed** in the final selected eMAR, medication wizard, shared page/wizard and navigation run. This includes draft cancellation, server errors, pharmacy selection, INR review, alert/reminder separation, controlled branding refresh and phone audit actions. Earlier runs overlap and are not added to this total. The only subsequent application changes were the legacy header-control minimum target CSS and the Handovers section landmark.

Final full TypeScript, changed-file ESLint with zero warnings and source whitespace checks passed. The final production build completed in **4m 3s**, with the existing chunk-size advisory. The browser loaded the manifest's exact entry, `app-B8ZEru95.js` with `app-CMYlSpHi.css`; `public/hot` is absent. A type check attempted during generated-route replacement was repeated successfully after the build completed.

Browser checks use only the existing synthetic database `oblivion_emar_preview_20261003`. Port 8765 selects the P02 person record; temporary port 8767 selects the retained legacy rendering, with stock-pack release flags off. No production records or screenshots are included.

| Observed journey | Result |
| --- | --- |
| Stock, Meds today, Safety, Reports and Handovers | Corrected compact header and connected rail rendered; stock actions, scopes and meters retained. Final stock and report captures use the final build. |
| Settings and retained MAR | Bare section tabs rendered; Settings tabs measured 36px high. Legacy MAR's Due view selected correctly. Legacy Medicines and PRN also rendered their migrated headers. |
| Reviews → To check / Reconciliation | Arrived at the canonical selectors, with the correct selected view and factual results/empty state. |
| New pharmacy order | Native keyboard person selection limited medicines to that person and advanced to order details. Phone dialog fit at 390px; inspected controls measured at least 44px. Dirty close prompted before discarding. No order submitted. |
| INR | Result → Instruction → Review retained all supplied synthetic values, with Back supported. Review screenshot captured before the final build; this modal code did not change afterwards. Draft discarded without submitting the clinical command. |
| Medication history | Custom date popover opens and dismisses. Audit record uses the shared section shell with retained source checks and record links. |
| Audit record at 390 × 840 CSS pixels | Dialog measured approximately 359 × 741px, with no horizontal overflow. Every inspected visible button/link was at least 44px high. Close, Open record and More actions remain fixed; the menu preserves View client, Verify integrity, Export event and the backed investigation flag. No investigation/export command submitted. |

Synthetic proof images are in [screenshots/hero-tabs-modal-2026-10-04](screenshots/hero-tabs-modal-2026-10-04/): [stock header](screenshots/hero-tabs-modal-2026-10-04/stock-header-desktop.jpg), [reports](screenshots/hero-tabs-modal-2026-10-04/reports-desktop.jpg), [safety overview](screenshots/hero-tabs-modal-2026-10-04/safety-overview-desktop.jpg), [INR review](screenshots/hero-tabs-modal-2026-10-04/inr-review-desktop.jpg), [phone audit record](screenshots/hero-tabs-modal-2026-10-04/audit-record-phone.jpg) and [phone actions](screenshots/hero-tabs-modal-2026-10-04/audit-actions-phone.jpg). The temporary viewport override was reset after verification.

## Remaining boundary

This corrects the reported header/tab problems and the modal defects found in this pass. It does not certify every role, theme, data volume, configuration or clinical write. Shared report-builder state-specific anatomy and broader cross-page scope continuity require their own complete acceptance pass; no guessed query propagation was added. Existing canonical actions and destinations remain available.

The release holds and broader failing regression ledger in [CURRENT-STATUS.md](CURRENT-STATUS.md) still apply. The previously failing controlled-branding case now passes, but its entire legacy file was not rerun here, so the dated 62-case ledger is not silently rewritten. No merge or deployment is justified by these passing subsets.
