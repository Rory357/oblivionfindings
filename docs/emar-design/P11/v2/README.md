# P11 v2 — Medication settings and staff eligibility

A synthetic design preview for Stephan and the review session. It is design only: no application code, routes, schema or seeders are changed. `DESIGN.md` and `design_styles/*` stay read-only.

## Why v2

Stephan withdrew the v1 approval on 29 September 2026 ("there is no toggle switches on off, it is difficult to navigate and the modals is not following the rules. Please look at the Fleet settings"). The review session then made the Fleet preview method mandatory: a small Vite + React build that **imports the app's real components**. v1 imitated them in hand-written HTML.

v2 keeps v1's behaviour and wording, plus the 21 answers. What changes is the method and the presentation, and three later decisions from Stephan (29 September).

## Stephan's decisions since v1 (29 September 2026)

1. **Settings read as cards; lists stay tables.** Configuration uses Card rows, like Safety checks: Who gets alerts, On-call contacts and every other setting. Lists of records (medicine rules, round templates, staff, change history) stay as `EntityTable`, with row click, the ⋯ menu and right-click.
2. **Managers set who gets each alert.** This reverses answer 15.
   - Each alert is a card with a switch for each group that can receive it. Stephan's decided routing is locked on: overdue doses and overdue follow-ups go to everyone rostered on a covering shift plus the house lead; override requests go to the people who can grant overrides plus the house lead.
   - Named people can be added through a search picker.
   - Changes go through Review changes and are recorded in the change history.
3. **Scope is organisation-wide plus house extras.** One set of groups and named people applies at every house, changed by someone with all-sites authority. A house manager can add extra people for their own house only; extras add to the groups and never replace them.

## Run it

It needs `node_modules` in the worktree. Here it is a **junction** to the main checkout's folder, so never run `npm install` or `npm ci` inside it, and delete the junction link before this worktree is removed.

From the worktree root:

```bash
node node_modules/vite/bin/vite.js build --config docs/emar-design/P11/v2/vite.config.mjs
```

```bash
node docs/emar-design/P11/v2/serve.mjs
```

Open http://127.0.0.1:4372/. The server is GET/HEAD only and no application API exists behind it. The built `dist/` is committed, so the reviewed build can be served without rebuilding.

- The **preview bar** switches role (clinical lead, house lead, provider manager, auditor, finance, support worker) and data states, and sets the next save to work, fail once, or find someone else saved first.
- The **Preview guide** (`#/guide`) links every state.

## What's in it

**Medication › Settings.** One PageHeader page with 5 rail views and tabs inside each (Fleet Settings):

| View | Tabs |
|---|---|
| Medication rules | Medicine rules (P00 v5; house rules kept for house managers), Safety checks, Controlled drugs, Medicine photos |
| Rounds & timing | Round templates (moved from Meds today › Rounds), Dose timing |
| Staff & PINs | Competency, Exemption limit, Witness PINs, PIN status |
| Alerts & access | On-call contacts, Who gets alerts, Emergency access |
| Change history | Still to decide, All changes |

- Drafts survive switching tabs and views. A sticky save bar leads to "Review … changes", which states when each change applies and shows old → new values.
- Leaving with a draft asks Fleet's "Leave with an unsaved draft?".
- Read-only roles see everything, with switches disabled and the reason and who can change it.

**Safety & oversight › Staff eligibility.** Four tabs: Register, Renewals, Exemptions, Witnesses & PINs. Assessments, exemptions and the assessment viewer use WizardShell.

**Meds today.** Only the *My eligibility* block, and the Rounds link back to Settings, are P11's. The rest of the page is P01's.

## Evidence

- **`CHECKLIST.md`**: every item of the review session's design-rules checklist, with pass/fail and evidence.
- **`reuse-check.mjs`**: P00 v5 wording is verbatim, the preview imports the real primitives, no shared file changed, and there are no raw colours, no browser dialogs and no network calls. Run it from the worktree root:

```bash
node docs/emar-design/P11/v2/reuse-check.mjs
```

- **`screenshots/`**: 1440, 1280 and 200 % zoom, plus dark theme.
- **`VERSION.txt`**: SHA-256 of every file in this version.

## Questions for Stephan

- **Q1:** Fleet Settings' `Modal` and `Notice` are imported from a Fleet page file. When P11 is built, should they become a shared component (a shared-component change)?
- **Q2:** "When a lead countersigns" and "Who gets the follow-up" each have exactly two answers that aren't on/off. Keep the two-button choice, or reword each as a switch?
- **Q3–Q7** come from the gap audit. They are listed in `AUDIT.md` §4, together with what building the design needs and the bugs found.
