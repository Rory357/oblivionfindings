# Referrals — interactive prototype

Local review URL: **http://localhost:5186/**. The preview process must be running. Refresh an already-open tab after a rebuild; finish or save unfinished work first.

**Review status (16 October 2026): all 18 findings from the subsequent audit have been addressed and re-audited.** See the [18-item closure review](AUDIT-LOG.md#closure-of-all-18-findings--16-october-2026) for the changes and verification: 37 model tests, 33 browser journey groups, an additional restricted export check, and 20 desktop/mobile screen states. Earlier audit sections remain historical evidence. This is a fictional prototype, not production sign-off.

**Latest continuity review:** a new mobile website enquiry was taken through assignment, contact, assessment, funding submission/decision, pending-funded onboarding, verified funding, agreed proposal, independent approval, future scheduling and confirmed supported-living commencement. Additional continuity gaps were corrected; see [the end-to-end review](AUDIT-LOG.md#website-to-supported-living-continuity-review--16-october-2026). The focused suite now has **42 passing model tests**, with the complete fresh-person journey, **20 additional browser regression groups**, and four updated desktop/mobile screen checks passing. These are new checks; the earlier broader screen audit remains separate historical evidence.

This standalone mockup uses fictional data stored in this browser. It does not change production routes, databases or Rory’s design rules. Queues use the shared Cards/Table switch, entity cards, tables, cells and action menus. Settings uses the documented ReviewCard overview, Review links, configuration subtabs and guarded publication. Referral detail puts the person, next action and readiness first. The calendar reuses the application's SiteCalendar with a local referral adapter. Shared-calendar extensions are opt-in: export permissions, current-person identity, a matching Mine action, compact mobile summary and readable phone agenda entries. Existing callers retain their default behaviour. Month-grid accessibility fixes are also included.

## Try the journeys

1. **Website entry → Quick enquiry**: submit a short enquiry, inspect its receipt and duplicate-retry response, then follow it into the staff workspace. Professional referral and funding-help entry paths are also available.
2. **Walkthroughs → Maia**: assign a coordinator, review assessment checks, prepare a funding case, log external submission and decision, switch to Finance reviewer to verify it, prepare a proposal, convert to onboarding, then approve service commencement once all checks pass.
3. **Hana, Leo, Aria and Isla**: inspect partial funding/gaps, private payer acceptance, a capacity waitlist and personal income assistance. Income assistance is kept separate from the service budget.
4. **Eli**: link an existing supported person without duplicating or downgrading their active record. Additional service readiness remains a separate decision.
5. **Settings**: edit intake questions, routing and response targets, stages, pathway checklists, message templates, notifications, role permissions and site/ownership scope. Preview routing and notifications, then review differences and safeguard warnings before publishing. Existing case templates stay on their version; History offers immutable snapshots, restoration into a new draft and controlled migration with preserved evidence.
6. **Preview role**: compare the manager, receptionist, coordinator, funding navigator, finance reviewer, support worker and settings administrator. Direct scenario links also respect the simulated access boundary.
7. **Walkthroughs → Recovery state**: preview loading/empty states, failed saves, conflicts, failed delivery, quarantine and service unavailability. Save unfinished staff intakes in the draft chooser. On the public website, explicitly save one enquiry draft for up to 12 hours in the current browser session; resume or discard it.
8. **Calendar**: switch Month, Week, Day, Agenda and Timeline; navigate dates and filter follow-ups, assessments and funding reviews. Create a follow-up, inspect an entry, reschedule with a reason, then undo it. The current role controls visible sources, scheduling and export.
9. **Reports**: open the dedicated sidebar page. Choose a received-date period and permitted site, inspect a measure's matching referrals, and return from a person with the report filters preserved. Report filters are independent of queue filters. Custom dates, empty results and export availability are validated. Phones expand the banner's totals and filters when needed.

After revising a proposal, review the service agreement and safe-start checks for that version. **Handover** can create an onboarding person while funding is pending when policy permits; the open funding work and follow-ups stay visible. A future service-start approval schedules support and creates a commencement follow-up. It leaves a new person in Onboarding until staff return through **Review scheduled start** and confirm actual commencement. The handover screen retains the approving person, date, proposal version and safe-start note. **View transferred information** shows readable labels for the identity, goals and contact preferences copied at handover; assessment review notes remain restricted on the referral.

Within Reports, **Overview** presents the intake/contact line graph, handover gauge, source donut, stage distribution, coordinator workload and attention measures. **Detailed reports** provides site/channel charts and compact measure groups with definitions and record drill-through. **Current workload** shows all permitted open work, including older referrals outside the received-date period; site and search filters still apply. Views retain the selected tab on refresh. The report scope and headline figures live in the hero banner. **Preview export** offers the displayed report measures with scope information or raw referral rows separately, respecting funding visibility. The chart's exact values can be opened with **View chart data**; zero and missing values are never replaced with invented trend data.

For a fuller reporting demonstration, choose **Walkthroughs → Load fictional reporting scenario** and confirm replacing the current fictional examples. It provides 32 records across six weeks with contact, funding and handover outcomes. **Reset fictional examples** restores the original eight. Settings includes **Jump to settings section**, a bottom review action and **Access → Record scope → Staff assignment sites** for configuring staff eligibility alongside approved regions and role scope.

Header marks use the shared 48px ring and small module icon; person headers use initials. Desktop actions retain compact shared sizing. Phone totals use two columns. Cards emphasise the next step and owner, and follow-ups opened from Overview use the same reviewed completion flow as Tasks. The top-bar search (Ctrl/Cmd+K) searches permitted referrals without changing the current page's filters.

The pipeline fits complete columns to the available width (three on the reviewed desktop, two on tablet). Its stage overview follows board navigation, and opening a referral preserves the selected stage on return. Phones use a compact stage picker and previous/next controls with full-width cards. Queue search, contextual filters, workspace-specific saved views, list/cards/pipeline, follow-up completion, contact logging, contact directory, staged evidence, activity, reports/export and person handover are interactive. Refresh retains saved fictional data. **Reset fictional examples** clears only this prototype’s saved examples for the current browser origin. `localhost` and `127.0.0.1` have separate browser storage.

## Review evidence

See [the latest closure audit](AUDIT-LOG.md#closure-of-all-18-findings--16-october-2026). This revision used `qa/fixes-workflows.js`, `fixes-settings-reports.js`, `fixes-export-privacy.js`, `revision-public.js`, `revision-workflows.js`, `revision-handover-settings.js`, `revision-governance.js`, `revision-visual.js` and `reports-dashboard.js`. These are Playwright CLI callbacks for the running preview. Use the isolated `127.0.0.1` origin for QA; these scripts reset fictional examples there. Do not run them against a reviewer's saved `localhost` session.

Pure workflow checks: `npx vitest run --config docs/referrals/mockup/qa/vitest.config.mjs`. Final browser results and screenshots are under `output/playwright/referrals-final-*`. Earlier scripts and audit sections are historical evidence and may use superseded controls.

The subsequent dedicated Reports/header review uses `qa/reports-page-review.js` and `qa/global-search-review.js`, with results and screenshots under `output/playwright/referrals-reports-*`. See the latest audit-log entry for the measured header sizes and mobile corrections.

## Run locally

From the repository root with its dependencies installed:

```powershell
npx vite build --config docs/referrals/mockup/vite.config.mjs --configLoader runner
npx vite preview --config docs/referrals/mockup/vite.config.mjs --configLoader runner --host 127.0.0.1 --port 5186 --strictPort
```

Open http://localhost:5186/. `--configLoader runner` avoids generated configuration writes through the repository’s linked dependency directory. The current standalone preview build succeeds with an advisory about bundle size; production bundling remains part of implementation.

## Prototype boundaries

- Roles, sites and privacy restrictions demonstrate intended behaviour in the browser. They are not server-side security; all examples are synthetic. Production requires authenticated policy checks on every read/write and attachment access.
- No messages, applications or documents are sent to agencies. Funding decisions and contract values are fictional, recorded by staff and verified separately. Government eligibility is never inferred by the prototype.
- Files are staged locally as metadata; the included synthetic text evidence can be viewed/downloaded. A production storage, scanning, retention and legal-hold service is not implemented.
- Conflict and delivery failures are explicit simulations. There is no concurrent-user transport, real merge engine, notification scheduler or idempotency service. Duplicate retry demonstrates the intended public receipt behaviour locally.
- Dates follow the current New Zealand day. Business-day targets skip weekends and configured closed dates. An authoritative production holiday feed and reliable time-zone jobs still require implementation.
- Settings publish immediately with an effective timestamp. Forms, ordered routing, availability fallback, stage gates, pathway guidance, approval delegation, notification previews, historical restoration and selected-case migration are interactive. Scheduled activation, live notification transport and escalation/quiet-hour engines remain production work.
- Funding validity is structured in the mockup: dates, units, service/provider scope, conditions, allocation and decision history. Actual provider contracts, funding decisions and rate validation still need authoritative production sources.
- The public landing and entry forms demonstrate the intake journey. A full company website, real provider identity, real contracts/rates, portal access and live supported-person integration are outside this standalone preview.

The [specification](../SPECIFICATION.md) remains the intended production scope. Mockup completion does not establish production readiness.
