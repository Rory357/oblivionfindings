# Porting an H&S page group to its approved drawings — helper brief

You are porting one group of Health & safety pages so they match Stephan's approved drawings (design viewer v35,
23 Oct 2026) view for view, on real data. Read this whole brief, then `DESIGN.md` (mandatory for any UI work),
then `docs/health-safety-build/PLAN.md`.

## Where things are

- **Code:** this worktree, branch `claude/hs-workspace-build` —
  `C:\Users\steph\Herd\oblivionfindings\.claude\worktrees\objective-mendel-a6cccd`. It holds Codex's H&S
  rebuild (pages, controllers, presenters) plus the new shared parts below. Edit only under this path.
- **Drawings:** the design kit `C:\Users\steph\Herd\oblivionfindings-design-kit` (read-only for you).
  - See a drawing: `node preview/hsx/prev.mjs <tag> pg-hsn-2 pg-hsn-2b …` → `preview/hsx/prev-<tag>-<n>-l.png`
    (add `MODE=d` for dark). Read the PNGs. Use a tag of your own (your group name) so you don't overwrite others.
  - The drawing as data (header, every block, its words): `node preview/hsx/dump.mjs pg-hsn-2 …` — this is the
    finished drawing after all header rules. `hero.strip` is the header strip, `hero.facts` the fact chips,
    `main`/`side`/`top` the body blocks, `was`/`change` say what changed from Codex's page.
  - Drawing → page file map: `docs/health-safety-build/PLAN.md` and the `source` field of each drawing.
- **Shared parts (use them, do not edit them):**
  - Header: `PageHeader` (`components/page/page-header.tsx`) with the new `facts` and `strip` props, and
    `components/page/page-header-strip.tsx`: `PageHeaderFacts`, `PageHeaderNumbers` + `PageHeaderNumber`
    (lists/hubs, at most four), `PageHeaderStages` (records: stage line + next step). Rail tabs:
    `PageHeaderRail`. Status chip: `PageHeaderStatusChip`.
  - Body: `components/signature/blocks.tsx` — `Surface`, `SectionHead`, `SideColumn`, `SideBySide`, `Ring`,
    `Tally`, `Journey`/`JourneyLine`, `RingCard`/`Checks`, `Log`, `PeopleCards`, `PlaceCards`, `QuietLine`,
    `KnowList`, `Story`, `Flow`, `RiskMap`, `DutyClock`, `Queue`, `StageDots`, `Choices`, `Routes`,
    `EvidenceWindows`. Their props mirror the drawing blocks (kit tone names map: ok→success, warn→warning,
    crit→critical, na→none). A worked example of almost every part, built from drawing pg-hsn-2:
    `C:\Users\steph\AppData\Local\Temp\claude\C--Users-steph-Herd-oblivionfindings--claude-worktrees-objective-mendel-a6cccd\dd00b656-07db-4781-930c-f8bd2ee955e0\scratchpad\sg-harness\entry.tsx`.
  - Status → always `<StatusBadge>`. Tables → `components/ui/table.tsx` / the list contracts in DESIGN.md.
  - If a drawing needs a part that doesn't exist, build it inside your page group's own folder only if it is
    specific to that page; anything reusable → describe it in your report instead of adding it to shared files.

## What "port" means

1. Same structure as the drawing: header rows in the drawing's order (title + chip + one sub-line, fact chips,
   ONE strip, filters, rail tabs), then the body blocks in the drawing's layout (main + side column, rows, tabs).
   Every tab/state drawn for the page (2, 2b, 2c …) is a tab or state of the real page — match each.
2. **Real data only.** Map the page's existing props (what the controller/presenter sends) onto the blocks.
   The drawings were drawn from this code, so most data exists; read the controller and presenter to find it.
   Where the drawing shows something the props don't carry, do NOT invent or hard-code it: leave the part out
   (DESIGN.md "hide unbuilt") and list it under "data gaps" in your report with the field you'd need. Do not
   change PHP; the lead decides backend changes.
3. **Keep every behaviour:** actions, buttons, dialogs and wizards (the pop-ups stay exactly as they are),
   permission checks (`can.*`), links, Wayfinder routes, forms, polling, unavailable/restricted states, empty
   states. Restyling must never drop a guard. If a drawing omits an action the page has, keep the action (put
   it under the header's More menu) and note it.
4. **Wording:** safety, privacy, restraint, consent, WorkSafe and HSWA wording stays word for word as the code
   has it. Plain words elsewhere, NZ English, the drawing's labels where they differ from Codex's.
5. DESIGN.md rules: semantic tokens only (no raw palette classes, no hex), typography helpers, no `dark:` pairs,
   lucide icons, accessibility (labels, focus rings, colour never the only signal).
6. Remove code the new structure no longer uses (old hero kits, ribbons) only within your group's files; leave
   shared old components in place even if unused by you.

## Check your work (light tools only)

Heavy commands (Pest, tsc, eslint, vitest, vite, npm/composer) are queued machine-wide — do NOT run them. The
lead runs them for all groups. Use these instead:

- **Render a page with made-up props** (esbuild + Tailwind + headless Chrome, a few seconds):
  ```
  cd "C:\Users\steph\AppData\Local\Temp\claude\C--Users-steph-Herd-oblivionfindings--claude-worktrees-objective-mendel-a6cccd\dd00b656-07db-4781-930c-f8bd2ee955e0\scratchpad\page-harness"
  node build.mjs incidents/show fixtures/incident-show.tsx out/<group>-incident-show --shot=shots/<group>-incident-show.png --height=2200
  node build.mjs incidents/show fixtures/incident-show.tsx out/<group>-incident-show --dark --shot=shots/<group>-incident-show-d.png
  ```
  A fixture is a `.tsx` module whose default export is the page's props (plus optional `__shared` and `__url`).
  Build fixtures in `fixtures/<group>-*.tsx`, typed with the page's own prop types, from the page's existing
  Vitest fixtures and the drawing's demo data (Hemi Walker, Aurora House, Wed 21 Oct 2026 …). `auth.can` answers
  yes to everything unless the fixture sets `__shared.auth.can`. Render each drawn state and compare it with the
  drawing PNG side by side at 1440 px wide. Errors show in red at the top of the screenshot.
- **Missing imports/exports:** `node "C:\Users\steph\AppData\Local\Temp\claude\C--Users-steph-Herd-oblivionfindings--claude-worktrees-objective-mendel-a6cccd\dd00b656-07db-4781-930c-f8bd2ee955e0\scratchpad\ts-missing.cjs"` from the worktree root.
- **Tests:** update your pages' existing Vitest files where they assert the old structure (keep every behaviour
  and guard assertion; change only what the redesign moved). You can't run them; the lead will.

## Rules

- No git writes (no add/commit/stash/checkout/reset). Read-only git is fine.
- Touch only your group's files (listed in your task). Never edit the shared parts, `app.css`,
  `signature-blocks.css`, the sidebar, routes or PHP.
- Don't start servers, browsers or long processes; headless Chrome via the harness is fine.

## Report (your final message)

1. Files changed, one line each.
2. Each drawing id → matched / matched with differences (say which).
3. Data gaps: what the drawing shows that the props don't carry, and the field needed.
4. Actions or guards the drawing omitted that you kept, and where.
5. Tests you updated, and anything you think will fail.
6. Parts you'd add to the shared kit.
