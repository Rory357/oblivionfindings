# H&S UI/UX audit, plan and mockups (31 Oct 2026)

Audit-only work: no app code changed. Brief from MASTER (`hs-uiux-audit-brief-2026-10-30.md` in the shared notes).

| File | What it is |
|---|---|
| `REPORT.md` | The problems (H1–H25, ranked), why the workflows feel weird, why Parts 1–4 missed it, and the workflow map per role |
| `PLAN.md` | The fix plan: interaction rules, build packages B1–B10, shared and backend work (S1–S4, K1a–K9), decisions Q1–Q13 (no Q5) |
| `AUDIT-LOG.md` | What each independent audit round found and what changed |
| `WORKFLOW-FACTS.md` | Code facts (routes, permissions, file:line) and the live permission corrections |
| `shots/` | 22 live screenshots from oblivionfindings.com (a admin, m manager, t team lead, s support worker) |
| `mockups/` | The HTML boards and the build script that makes the one-page artifact for Stephan |

Build the artifact page: `node mockups/build.mjs` (paths inside point at this folder and the session scratchpad). Render
one board: `bash mockups/preview.sh m5 1500` (headless Chrome).
