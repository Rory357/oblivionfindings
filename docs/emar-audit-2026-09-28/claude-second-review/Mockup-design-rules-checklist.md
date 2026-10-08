# eMAR mockups: build method and Rory design-rules checklist

29 September 2026 · Mandatory for every eMAR design package from P11 v2 and P01 onwards · Sources: `DESIGN.md`, `design_styles/*`, and Stephan's recorded corrections. These reference files stay read-only.

## Why the mockups keep failing

The Fleet mockups that passed review (e.g. `docs/fleet-assets-audit/previews/PKG-02B/v13/`) are **small React builds that import the app's real components.** Their `vite.config.mjs` aliases `@` to `resources/js` and uses the Tailwind plugin, so every button, input, date-time field and upload is the real component, styled with the real tokens.

The eMAR P00 and P11 mockups were **hand-written HTML/CSS/JS** (`mockup.css`) that imitated the components, and they drifted from the rules: no real switches, modal anatomy off, header details wrong. **Fixing the method matters more than a longer checklist.**

## 1. Build method (mandatory)

1. Build each version as a Vite + React preview in `docs/emar-design/<package>/<version>/`. Copy the scaffold from the Fleet previews: `vite.config.mjs`, `index.html`, `main.tsx`, `serve.mjs`.
   - Alias `@` to `resources/js`.
   - Use `@tailwindcss/vite`, so `resources/css/app.css` tokens apply.
   - Install dependencies **in your own worktree**; the main checkout's `node_modules` is empty.
2. **Import the real primitives. Never hand-roll them.**

   | Need | Real component |
   |---|---|
   | Page top | `components/page/page-header.tsx` (`PageHeader`, `PageHeaderRail`, meter blocks) |
   | Record sub-navigation | `components/page/grouped-profile-nav.tsx` (`TierTwoTabs`) |
   | Buttons | `components/ui/button.tsx`: the real variants as Fleet uses them. Default, outline and destructive for actions; `link` for "Review … ↗" inside cards (`_owners.tsx`); `ghost` for secondary reset actions. Never restyled. |
   | On/off settings | `components/ui/switch.tsx` |
   | Status | `components/ui/status-badge.tsx` |
   | Multi-step add/edit | `components/wizard/shell.tsx` (`WizardShell`) |
   | Simple dialogs | `components/ui/dialog.tsx`, laid out like Fleet's `pages/fleet-assets/settings/_ui.tsx` `Modal` |
   | Confirm / destructive | `components/confirm-dialog.tsx` |
   | Lists | `components/lists/*` (`EntityTable`, `EntityCard`, cells, `entity-menu`) and `components/ui/laravel-pagination.tsx` |
   | Empty / loading / error | `components/ui/empty-state.tsx`, `loading-state.tsx`, `error-state.tsx`, `skeleton-*` |
   | Uploads | `components/ui/file-dropzone.tsx` (`FileDropzone`, `StagedFileCard`) |
   | File viewing | `components/files/file-preview-dialog.tsx` |
   | Date and time | `components/fleet-assets/maintenance/date-time-field.tsx` (the approved PKG-01 DateTimeField) |
   | Searchable pickers | `components/ui/popover.tsx` + `components/ui/command.tsx` |

3. **The mockup is synthetic only.** No calls to application APIs; keep the data in local fixtures. Keep a fixed synthetic clock in Pacific/Auckland.
4. **No edits to shared components** in a design package. If a primitive can't do something, record it as a question.

## 2. Checklist (every item must pass, with evidence)

Fill in `CHECKLIST.md` in the version folder. For each item, write pass/fail and the screen or file that proves it.

### A. Page top (`PAGE_HEADER_STYLE_GUIDE.md`, `NAVIGATION_STYLE_GUIDE.md`)

- [ ] `PageHeader`, not `PageHero`.
- [ ] The title is plain, with **one** `StatusBadge` chip and a one-line fact subline.
- [ ] **No greetings** ("Kia ora …") and no "LIVE / refreshed" eyebrows.
- [ ] One meter row of 4–6 blocks. **Every block links to its view** and uses real synthetic data. Use the graph form where one exists; show "n/a" for a zero denominator and "Unavailable" rather than 0.
- [ ] Search and primary filters sit **inside** the header's filter row; nothing sits between the header and the content.
- [ ] Main views use the `PageHeaderRail` connected tabs: **one line**, 8 views or fewer, overflow to "More", and the Find chip at the end. The header casts no drop shadow.
- [ ] Breadcrumbs are rooted at **Home**, e.g. Home → Medication → Settings.

### B. Layout (`DESIGN.md`, `APP_SHELL_STYLE_GUIDE.md`)

- [ ] Only the shell gutter; no extra page padding. Use `gap-5` between sections and cards.
- [ ] The page body is full width, with no centred `max-w` cap.
- [ ] A number lives once, in the meter row. It is **not** repeated as body KPI cards.
- [ ] Desktop web only. Check at 1440 and 1280 px and at 200% zoom, with no horizontal page scroll.

### C. Lists (`LIST_STYLE_GUIDE.md`)

- [ ] `EntityTable` / `EntityCard` contracts, with the identity cell first and the kebab last.
- [ ] Every row has the **⋯ menu and the same menu on right-click** (plus Shift+F10), and clicking a row opens it.
- [ ] Server-style pagination.
- [ ] Empty, loading and error states use the shared components.

### D. Dialogs (`POPUP_STYLE_GUIDE.md`)

- [ ] Any add/edit of a record with 2 or more sections uses **`WizardShell`** (stepper, "Step x of y", review step, success pane). Never a full-page wizard, and never a hand-built stepper.
- [ ] Simple dialogs follow the shell/body split, width tokens and footer layout (reference: the Fleet Settings `Modal`).
- [ ] Consequential saves use `confirm-dialog`, stating the effect. Destructive buttons use the destructive variant.
- [ ] Never a browser `prompt` or `confirm`.
- [ ] Errors keep the entered values and move focus to the first error. Closing a dialog returns focus to the thing that opened it.

### E. Controls

- [ ] **On/off settings use `Switch`** (Stephan, 29 September). Use a select or segmented control only for 3 or more real options, with a one-line summary.
- [ ] Growing lists (people, medicines, houses) use a **searchable picker**, not a long dropdown.
- [ ] Uploads use `FileDropzone` + `StagedFileCard`, with honest staged, uploading, saved and error states.
- [ ] Dates use the approved calendar selection; times use the clock and manual entry, with the **timezone visible** (PKG-01 DateTimeField, view-for-view).
- [ ] Buttons aren't restyled per page.

### F. Tokens, status and accessibility (`DESIGN_TOKENS.md`)

- [ ] Semantic tokens only: no raw palette classes, hex values or `dark:` pairs.
- [ ] Every status uses `StatusBadge`. Safety surfaces use the fixed critical/warning pairs, never a brand tint.
- [ ] Colour is never the only signal.
- [ ] lucide icons only. Icon-only buttons have an `aria-label`.
- [ ] Visible focus ring. Tap targets of 44 px or more on frontline actions.

### G. Language (the plain-language vocabulary anti-pattern)

- [ ] NZ English, sentence case, plain words. No codes used as titles, no raw enum values, and no developer or auditor words ("immutable", "snapshot", "envelope", "request ID").
- [ ] "Not configured", "Not available" or "Unknown" instead of fake zeros or false negatives, e.g. **never** "No known allergies" when nothing is recorded.
- [ ] Every blocked action says **why, and who can unblock it**.
- [ ] No invented clinical values or regulator names.

### H. Honesty

- [ ] No dead or decorative actions or meter blocks. Anything not built is hidden, never stubbed.
- [ ] Synthetic data is clearly labelled as a mockup.
- [ ] Views approved earlier are reused unchanged unless an approved change says otherwise.

## 3. Review process before Stephan sees it

1. **The designer self-checks** every item in `CHECKLIST.md`, with evidence, and screenshots at 1440, 1280 and 200%.
2. **The review session (Main) inspects** against this checklist, then opens the **live Fleet equivalent of every view** in the browser and compares the page *structure*, not only the parts. That means:
   - an Overview of ReviewCards for each view;
   - titled setting groups with an icon and caption;
   - channel columns (In-app / Email) wherever people are notified;
   - the sub-tab set.

   Each screen is compared **side by side with its Fleet reference** at 1440 px: Fleet Settings for settings, Fleet Vehicles/Maintenance for lists and records, and the PKG-01 DateTimeField for time entry. Every dialog is opened, not just the landing screens.
3. **Only after both pass** does Stephan get the link. Failed items go back as a numbered list to the same designer.
4. **New corrections from Stephan** are added to section E or I of this checklist straight away, and proposed as DESIGN.md anti-patterns (DESIGN.md itself is read-only here).

## I. Stephan's recorded corrections (grows over time)

| Date | Correction | Checklist items |
|---|---|---|
| 29 Sep | Settings need on/off toggle switches, easy navigation, and modals following the rules; look at Fleet Settings | E (switches), D, A |
| 29 Sep | Use the approved PKG-01 DateTimeField view-for-view; clicking a row opens it; ⋯ plus right-click on every row | E, C |
| 29 Sep (P11 v2 not approved) | "alerts and access there is no in app or email similar to fleet / staff pins tab looks so plain there is no structure". Copy Fleet's **page structure**, not just its components: Overview ReviewCards, grouped setting sections, and In-app/Email switch columns for anything that notifies people | A, E, Process |
| Standing | Build approved designs view-for-view, and walk the mockup beside the build at 1440 px before calling it done | Process |
