# eMAR mockup session brief (every design package from P02 onwards)

30 September 2026. The review session ("Codex eMAR audit re-review") maintains this brief. Every eMAR design session must follow it exactly. It records how P00, P01 and P11 reached approval, including the corrections Stephan made along the way, so that the next package gets there first time.

## 1. What you are and aren't allowed to do

**Design only:**
- No application code, routes, schema, seeders, config, DESIGN.md or `design_styles` changes.
- No edits to shared components.
- If a shared part can't do something, write it down as a question.

**Commits:**
- Commit only your own package folder, on your own branch.
- Never push, and never merge to main.

**Data:** synthetic only.
- No calls to application APIs and no network.
- Use a fixed synthetic clock in Pacific/Auckland.
- Clearly label the preview as a mockup.

**Scope:** respect roles, permissions, approved houses, canonical record ownership, direct-object denial (show "not found" rather than leaking that a record exists) and privacy. Don't add tenant selectors: the app is single-tenant.

**PC load:** the machine crashes under load.
- Never run Pest.
- Run one heavy command at a time (builds, the screenshot harness).
- If you run vitest at all, use `--maxWorkers=2`.

**Other rules:**
- Desktop web only (D7). Check at 1440 px, 1280 px and 200 % zoom.
- NZ supported living: people may self-manage some medicines and need prompting, assistance or administration for others. It is not a hospital ward, and there is no on-site nurse. Write in NZ English and plain words.

## 2. Read before you design

1. `DESIGN.md` and every guide in `design_styles/`.
2. `docs/emar-audit-2026-09-28/claude-second-review/Mockup-design-rules-checklist.md`. This is mandatory, and every item needs evidence.
3. `docs/emar-audit-2026-09-28/claude-second-review/Approval-record.md`, which holds every decision Stephan has made. Never re-ask a decided question.
4. `docs/emar-audit-2026-09-28/claude-second-review/Revised-navigation-and-page-plan.md`: §2 (navigation), §4.1 (the client profile vs the person record), and §7.2–7.3 (your package's scope, dialogs and states).
5. **The approved packages (reuse their wording verbatim):**
   - **P00 v5** at `ff3bff860`: the shared states, blocked reasons, allergy rule, witness PIN and amount given.
   - **P01 v1** at `3ac640485` on `claude/goofy-noyce-ae9936`: the recording contract. Never redesign recording; open P01's dialog.
   - **P11 v4** at `71d86968c` on `claude/serene-aryabhata-d0e908`: settings, alerts and eligibility.
6. **Your template:** copy the P01 v1 package scaffold. P01 passed review with the fewest fixes.
   - `vite.config.mjs`
   - `src/inertia-shim.tsx`
   - `src/styles.css` (with its explicit Tailwind `@source` lines)
   - `serve.mjs`
   - `tools/verify.mjs`, the headless-Chrome harness. It records overflow, console errors, one-line sublines and truncated captions.
   - The contract page.
   - `CHECKLIST.md`
   - `VERSION.txt` (SHA-256 of every file)

## 3. Build method (non-negotiable)

- Build a Vite + React preview on the app's **real components**, with `@` pointing at `resources/js` and `@tailwindcss/vite`. Never write hand-made HTML/CSS imitations; that is why P00 v1–v3 and P11 v1 kept failing.
- Install `node_modules` in your own worktree (`npm ci`). If you use a junction instead, record it, and the link must be deleted before the worktree is removed.
- Pick an unused preview port: P11 uses 4371–4375, and P01 uses 4381. The next free ports are **4382** and up.

## 4. Stephan's corrections so far (each one cost a version)

| # | Correction | What to do |
|---|---|---|
| 1 | "No toggle switches… modals not following the rules… look at Fleet settings" | Use `Switch` for every on/off setting. Use Fleet's `Modal`, `WizardShell` or `ConfirmDialog` per POPUP_STYLE_GUIDE. |
| 2 | "Alerts… no in-app or email like Fleet / looks so plain, no structure" | Copy Fleet's **page structure**, not only its parts. Every view gets an Overview of ReviewCards with a "Review … ↗" `variant="link"`. Settings sit in titled groups (icon + caption) of compact rows. Anything that notifies people gets In-app/Email switch columns. |
| 3 | "Why is it not interactive?" | Don't make a tab of info cards with badges. Every tab needs real controls, grounded in today's code. |
| 4 | The PKG-01 DateTimeField, view-for-view | Use the real DatePicker/TimePicker with the Pacific/Auckland legend. |
| 5 | Every row: click opens it; ⋯ menu plus the same menu on right-click and Shift+F10 | Use `EntityTable`, `entity-menu` and `EntityContextMenu`. |
| 6 | "Hide unbuilt" | No stubs and no "coming soon". Anything owned by another package is a link or is labelled as outside this preview. |
| 7 | Build approved designs view-for-view | Once approved, a version is frozen; a change needs a new version folder. |

## 5. Review findings the earlier packages had to fix (check these yourself first)

- **A number lives once**, in the meter row. Don't repeat it in cards (P01 My Day).
- **One-line subline** in every PageHeader at 1440 and 1280, and **no truncated meter captions**. Measure them.
- **No link tiles dressed as meters.** A meter shows data.
- **No codes in product copy** (P04, D8, NF-03). Design notes go in design-note chips or on the contract page.
- **Buttons use the real variants only:**
  - default, outline or destructive for actions;
  - `link` for "↗" jumps;
  - `ghost` for secondary reset actions;
  - never restyled; `className` holds spacing classes only.
- **Honest states:** "Not configured", "Unavailable", "n/a". Never a fake 0, and never "No known allergies" when nothing is recorded.
- **Controlled-drug concealment everywhere (EM-12, a P0 theme).** For people without `medications.controlled.view`, controlled-medicine rows are hidden or redacted in:
  - lists and caption counts;
  - search;
  - dialogs and timelines;
  - exports.

  Show that state with a persona.
- **House scope:** each persona sees only their approved houses. Show "no access" (the page) as different from "not found" (a record).
- **Loosening a safety setting is destructive**, and the Review step says "Loosens this check".
- **Known shared bug:** a destructive `ConfirmDialog` renders purple (`AlertDialogAction` + `btn-soft-primary`). PR #15 fixes it. Use the real ConfirmDialog anyway and say so; don't work around it.
- **Reference frames from other modules must mirror current origin/main.** P01's transport header had drifted, so run `git fetch` and check the live file before copying another module's header.
- **Ground every claim about today's code** with `file:line` on origin/main. Mark each one **verified** (you read it) or **reported** (an agent said so). Audit agents over-report.
- **Blocked actions say why and who can unblock**, naming a person or role.
- **No invented clinical values or regulator names.**

## 6. The process: how P00, P01 and P11 got approved

1. **Ask first, briefly.** Before building, find the decisions that change the design and ask Stephan with **pop-up questions** (AskUserQuestion). Put the recommended option first, with 2–4 short options.
   - Ask only what isn't already in `Approval-record.md`.
   - Record the answers in your README and send them to the review session for the Approval record.
2. **Build v1** in `docs/emar-design/<package>/v1/` with:
   - README.md: what the package decides, a table of entry points and states, and the open questions;
   - CHECKLIST.md: every checklist item with its evidence file;
   - AUDIT.md: today's code vs the design, with verified and reported items and the bugs found;
   - VERSION.txt;
   - screenshots at 1440, 1280 and 200 %;
   - the harness report;
   - a contract page that deep-links every state.
3. **Compare with the live references yourself** at 1440 (oblivionfindings.test, as the demo admin from `DemoSeeder`):
   - Fleet Settings for settings;
   - the Fleet vehicle profile (PKG-02B) for a record page;
   - Fleet lists for lists;
   - **the live client profile** for anything person-level.
4. **Send it to the review session** ("Codex eMAR audit re-review") for inspection. Include:
   - the commit hash;
   - the VERSION.txt hash;
   - the port;
   - the screenshot list;
   - the deviations you're declaring.

   **Don't show Stephan anything before the review session passes it.**
5. **Fix the numbered list** you get back, in the same version when nobody has approved it yet. Re-send the hash and the changed screenshots.
6. **Ask Stephan for exact-version approval, keeping it short.** He has said there's too much reading. At most 10 lines:
   - what it is, in one line;
   - the preview link;
   - the three things to look at;
   - each question with a recommended answer, one line each.

   Use a pop-up question for approve / approve with recommended answers / changes.
7. Once approved, **freeze** the version. Any later change goes in a new version folder.

## 7. Package-specific scope

Each new session's first message gives the scope (from plan §7.2 and §7.3). If the scope and this brief disagree, ask the review session.
