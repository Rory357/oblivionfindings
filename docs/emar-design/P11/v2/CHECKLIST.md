# P11 v2 — design-rules checklist (self-check)

Checked on 29 September 2026 against `docs/emar-audit-2026-09-28/claude-second-review/Mockup-design-rules-checklist.md`.

**Evidence tools** (in this folder, or described below):
- **`reuse-check.mjs`**: P00 wording, real-component imports, token and honesty lint. 21 of 21 pass.
- **The route sweep**: 217 routes × 1440, 1280 and 200 % zoom. It checks console errors, page errors, page-level horizontal overflow, and that every `?open=` deep link opens a dialog.
- **The interaction run**: 69 checks in headless Chromium at 1440. 69 of 69 pass, with no console errors.
- **Screenshots**: 142 in `screenshots/`. The file name gives the size, then the screen. The in-app **Preview guide** (`#/guide`) links every state.

Status legend:
- **Pass**: meets the item.
- **Pass, note**: meets the item; the note explains how.
- **Question**: needs Stephan's decision.
- **N/A**: not in P11's scope.

## 1. Build method

| Item | Status | Evidence |
|---|---|---|
| Vite + React preview in `docs/emar-design/P11/v2/`, scaffold copied from the Fleet previews | Pass | `vite.config.mjs`, `index.html`, `src/main.tsx`, `serve.mjs`. Same shape as `docs/fleet-assets-audit/previews/PKG-02B/v13/`. |
| `@` aliased to `resources/js`; `@tailwindcss/vite`, so the `app.css` tokens apply | Pass | `vite.config.mjs`; `src/styles.css` imports `resources/css/app.css` and `maintenance-date-time.css`. |
| Dependencies available in the worktree | Pass, note | `node_modules` is a **junction** to the main checkout's (407 packages; the review session confirmed this is fine). Never run `npm install` or `npm ci` inside it. Delete the junction link before the worktree is removed. |
| Real primitives, never hand-rolled | Pass | `reuse-check.mjs`, "imports the app's real primitives": 26 app modules. Covers PageHeader, meters, filter selects and PageHeaderRail; TierTwoTabs through Fleet's `Sections`; Fleet's `Modal` and `Notice`; Switch; WizardShell with ReviewCard, ReviewRow and WizardSuccessPane; wizard primitives; ConfirmDialog; DiscardDraftDialog; EntityTable with EntityKebab and EntityContextMenu; ListCaption; StatusBadge; EmptyState and EmptyError; SkeletonTable; Popover + Command; the PKG-01 DatePicker and TimePicker; Input, Textarea, Label, Card and Button. |
| Synthetic only; fixed clock in Pacific/Auckland | Pass | Tue 29 Sep 2026, 9:12 am NZDT, in `src/data.ts`. `reuse-check.mjs` "no network calls". `serve.mjs` is GET/HEAD only, with `connect-src 'none'`. |
| No edits to shared components | Pass, note | `reuse-check.mjs` "no shared component, DESIGN.md or design_styles change". **Question Q1:** Fleet's `Modal` and `Notice` live in a page-local file (`pages/fleet-assets/settings/_ui.tsx`). Importing them here proves the exact look. When P11 is built, they should become a shared component (for example `components/settings/*`) rather than an eMAR page importing a Fleet page. That is a shared-component change needing Stephan's OK. |

## 2. Checklist

### A. Page top

| Item | Status | Evidence |
|---|---|---|
| `PageHeader`, not `PageHero` | Pass | `settings.tsx`, `eligibility.tsx`, `today.tsx`. |
| Plain title, **one** StatusBadge chip, one-line fact subline | Pass | "Settings" with *Organisation*; "Safety & oversight" with *2 houses*; "Meds today" with *Kōwhai House*. The chip is `PageHeaderStatusChip`, which is a StatusBadge. See `1440-set-rules-safety.png`. |
| No greetings, no "LIVE / refreshed" eyebrows | Pass | "Updated 9:12 am" is a filter-row refresh button (Fleet pattern), not an eyebrow. |
| One meter row of 4–6 blocks, each a link, real synthetic data, graph where one exists, n/a, "Unavailable" | Pass, note | Settings has 5 blocks, with a donut for Witness PINs. Staff eligibility has 6, with a donut for "Can record alone". Every block navigates to its view. Loading shows "—" and "Loading…"; failure shows "Unavailable" (`1440-state-settings-error.png`). A zero denominator reads "n/a" (`1440-state-elig-empty.png`). **Meds today** shows only P11's *My eligibility* block: its other blocks belong to P01 (approved in P00 v5), and the page says so. |
| Search and primary filters inside the header filter row; nothing between header and content | Pass | Scoped search and the "Changes" glass button are in the top row. Filters and "Updated" are in the filter row (`PageHeaderFilterSelect`, `PageHeaderFilterButton`). The Sections tabs start the content, as in Fleet Settings. |
| `PageHeaderRail`: one line, ≤ 8 views, "More" overflow, Find chip, no drop shadow | Pass | Settings has 5 views; Safety & oversight has 7. It is the real rail, with its own overflow and Find chip. Unsaved views get a dot through `decorations`. At 1280 and 200 % the rail overflows to "More" (`1280-elig-register.png`). |
| Breadcrumbs rooted at Home | Pass | Home › Medication › Settings, and Home › Medication › Safety & oversight. |

### B. Layout

| Item | Status | Evidence |
|---|---|---|
| Only the shell gutter; `gap-5` between sections | Pass | Pages are `space-y-5`. The preview shell copies the Fleet preview chrome, with the same 20 px gutter. |
| Full width, no centred `max-w` cap | Pass | All screenshots. |
| A number lives once, in the meter row | Pass | There are no KPI cards in the bodies. Lists carry only ListCaption "n of n shown" captions. |
| Desktop web only; 1440, 1280 and 200 % zoom; no horizontal page scroll | Pass | Route sweep: 651 page loads, 0 problems (`sweep` log in the review notes). An earlier failure (a long `ListCaption` caption can't wrap at 200 %) was fixed by moving descriptions under the caption. |

### C. Lists

| Item | Status | Evidence |
|---|---|---|
| `EntityTable`, identity cell first, kebab last | Pass | Medicine rules, round templates, PIN status, on-call contacts, alert routing, still to decide, all changes, the register, renewals, exemptions and witnesses. |
| ⋯ menu and the same menu on right-click (and Shift+F10); row click opens | Pass | Interaction run: "right-click opens the row menu", "Shift+F10 opens the row menu", "⋯ opens the same menu", "row click opens the on-call dialog". See `1440-menu-row-context.png`. |
| Server-style pagination | Pass, note | Change history uses Fleet's ChangeHistory pattern: Previous, Next and "Page x of y", 8 rows a page. `LaravelPagination` needs Inertia links, and Fleet Settings doesn't use it either. |
| Empty, loading and error use the shared components | Pass | `EmptyState`, `EmptyError` with Try again, and `SkeletonTable`. See the `state-*` screenshots. |

### D. Dialogs

| Item | Status | Evidence |
|---|---|---|
| Add or edit with 2+ sections uses `WizardShell` (stepper, "Step x of y", review step, success pane) | Pass | Four wizards, all with free step navigation, a completeness meter, review cards with Edit, and a success pane: rule (3 steps), round template (3), assessment (5), exemption (3). Multi-section viewers use WizardShell with `sequential={false}` and `headerLabel`: the assessment, and My eligibility. |
| Simple dialogs follow the Fleet Settings `Modal` | Pass | Fleet's `Modal` is imported as is (480 px, bordered header, scrolling body, muted footer). It is used for review changes, the leave guard, the unsaved list, history detail, rule and template view, on-call contact, time-critical medicine, create rounds, alert detail, end exemption, acknowledge, and not found. |
| Consequential saves state the effect; destructive uses the destructive variant | Pass | Settings saves go through Fleet's "Review … changes" Modal. It states when and where each change applies, and shows old → new values. Single consequential actions use `ConfirmDialog`: pause or turn a rule back on, pause, turn on or retire a template, reset a PIN, remove an on-call contact, discard a view's changes. Discarding a wizard draft uses `DiscardDraftDialog`. Ending an exemption needs a reason, so it is a Modal with a destructive "End exemption". |
| Never a browser `prompt` or `confirm` | Pass | `reuse-check.mjs`. |
| Errors keep values and focus the first error; closing returns focus to the opener | Pass | Interaction run: "validation error shown inline", "focus moves to the invalid field", "other values kept", "on-call validation keeps values and focuses first error", "focus returns to the opener after Escape". After a save, focus moves to the page's status notice (Fleet message pattern). |

### E. Controls

| Item | Status | Evidence |
|---|---|---|
| **On/off settings use `Switch`** (Stephan, 29 Sep) | Pass | Every on/off is a Switch with its On/Off word, as in Fleet `_notifications.tsx`. Examples: rule Active; template Active and Every day; the four safety checks; controlled-drug witness and heads-up; the photo prompt; re-offer reminder; core areas; observed minimum; PIN renewal and fallbacks; the reset roles (two switches); the emergency reason; assessment flags and declaration; acknowledgement. The alert recipients have one switch per group, with your decided groups locked on. |
| Select or segmented only for 3+ real options, with a one-line summary | Pass, note | Choices under a switch belong to 3-option settings (for example Off / Block / Co-signer), with an ⓘ summary line. **Question Q2:** two settings have exactly two non-on/off answers. "When a lead countersigns" is by the end of the next day or before the end of the same shift. "Who gets the follow-up" is the house lead on shift or the house lead for the house. They use Segmented. Keep that, or reword each as a switch? |
| Growing lists use a searchable picker | Pass | Popover + Command pickers for medicines, people, houses and scope. Also used for the default staff on a template, the person being assessed, observed people, and the exemption person. People who can't be chosen stay listed, with the reason. |
| Uploads use FileDropzone | N/A | P11 has no uploads (medicine photos are taken in P06). |
| Approved date and time pickers, timezone visible | Pass | The PKG-01 `DatePicker` (calendar) is used for assessment, end, exemption and create-rounds dates. The PKG-01 `TimePicker` (clock plus manual entry) is used for the round time, inside the `date-time-field` legend "Pacific/Auckland" (`1440-dlg-tpl-clock.png`). Date-only fields carry a "Pacific/Auckland" hint. |
| Buttons not restyled | Pass | Only spacing classes are added. `reuse-check.mjs` "default / outline / destructive only". |

### F. Tokens, status and accessibility

| Item | Status | Evidence |
|---|---|---|
| Semantic tokens only | Pass | `reuse-check.mjs`: no hex, no raw palette classes, no `dark:` pairs. The preview CSS is tokens only. Dark theme works unchanged (`1440-dark-*.png`). |
| Every status uses StatusBadge; safety surfaces use the fixed pairs | Pass | Competency, PIN, rule, template, exemption, review-state and alert badges. Warnings and blocks use InfoCard `warn`/`crit` (status tokens). |
| Colour is never the only signal | Pass | On/Off words beside switches. Badges carry text. "What they can do" lists say Yes / No / With conditions / Not checked. |
| lucide icons only; icon-only buttons have `aria-label` | Pass | For example "Remove observation 1", "Messages (outside this preview)", "Collapse sidebar". |
| Visible focus ring; 44 px tap targets on frontline actions | Pass, note | Focus rings come from the real components. P11's one frontline surface is *My eligibility*: the meter block is ≥ 80 px tall. Dialog buttons are the app-standard 36 px, because this is desktop web only. |

### G. Language

| Item | Status | Evidence |
|---|---|---|
| NZ English, sentence case, plain words, no codes as titles | Pass | Decision codes (D2, D8, P10) appear only as small neutral chips, never as titles. |
| "Not configured", "Unavailable", "n/a" instead of fake zeros | Pass | See the `state-*` screenshots, and "Still to decide". |
| Every blocked action says why and who can unblock it | Pass | Read-only save bars name the rule and a person, for example "for example Hana Kereama, clinical lead" or "Ask Rangi Parata". No-access pages name who can help and offer a page the person can open. Picker options that can't be chosen say why. |
| No invented clinical values | Pass | Timing values are today's configuration. PIN values and the 30-day exemption come from Stephan's answers. Observed administrations and time-critical medicines stay "Not configured". |

### H. Honesty

| Item | Status | Evidence |
|---|---|---|
| No dead or decorative actions or meters | Pass, note | Outside P11, sidebar and top-bar items open a labelled "… is outside this preview" page. The same goes for other Meds today and Safety & oversight views ("… is designed in P0x"). Unbuilt actions (for example Export) are hidden. |
| Synthetic data clearly labelled | Pass | The preview bar reads "Synthetic design · nothing is sent". |
| Views approved earlier reused unchanged unless an approved change says so | Pass, note | P00 v5 wording is reused verbatim (`reuse-check.mjs`, 12 declaration checks). The presentation changed because Stephan corrected it on 29 September ("toggle switches … Fleet settings") and the review session told us to use the real components. Two things changed with it: P00's selects became switches where a setting is on/off, and the "Not configured" option disappears where Stephan decided the value. The only wording change beyond answer 20 is the PIN renewal help: "When on, people are asked…" replaces "Leave empty for no renewal…", because a switch replaces the empty box. |

### I. Stephan's recorded corrections

| Correction | Status | Evidence |
|---|---|---|
| Settings as cards like Safety checks; managers set who gets alerts (29 Sep, second round) | Pass | Configuration uses Card rows: Who gets alerts, On-call contacts and every setting tab. Lists stay EntityTable. Recipients are configurable: organisation-wide, plus extras per house (`AUDIT.md` §1). |
| Settings need on/off toggle switches, easy navigation, and modals following the rules; look at Fleet Settings | Pass | E (switches). Navigation: 5 rail views, then Sections tabs, as in Fleet Settings. D (real WizardShell and Fleet Modal). The review, discard and leave guard follow Fleet wording. |
| Use the approved PKG-01 DateTimeField view-for-view; row click opens; ⋯ plus right-click on every row | Pass | E (PKG-01 DatePicker and TimePicker). C (row interactions). |
| Build approved designs view-for-view; walk the mockup beside the build at 1440 | Pass, note | This applies when P11 is implemented. The screenshots at 1440 are the reference. |

## Questions for Stephan

- **Q1 (shared component):** Promote Fleet Settings' `Modal` and `Notice` to a shared `components/settings/*` when P11 is built?
- **Q2 (two-answer choices):** Keep Segmented for "When a lead countersigns" and "Who gets the follow-up", or reword each as a switch?
- **Q3–Q7:** see `AUDIT.md` §4 (one owner per alert, more than ordered, more settings, after-hours alerts, end-date warning).
