# Notes for Gemini

Read these before changing anything:

1. `AGENTS.md`: one organisation per installation. No tenant features.
2. `CLAUDE.md` and `DESIGN.md`: the design rules apply to every agent. Read
   `DESIGN.md` before any UI change.
3. Taking over a workstream? Read its hand-over first. Security & Devices:
   `docs/security-devices/HANDOVER-GEMINI.md`.

House rules, in short (the hand-over has the detail):

- Stage exact paths. Never `git commit -a`, `git add .` or `git add -A`.
- Never commit `database/schema/mysql-schema.sql` or `phpunit.xml`.
- Never force-push. Push to `main` only as a fast-forward, and only with
  Stephan's OK. Merges to `main` deploy to the .com test server.
- Never leave files in `/var/www/oblivionfindings` on the .com server.
- Never loosen safety, privacy or permission rules to make tests pass.
- One heavy command (Pest, full tsc, ESLint on everything, Vitest, builds,
  installs) at a time on the whole PC. No `--parallel`.
- Kill processes only by exact PID.
- Don't work in the main checkout `C:\Users\steph\Herd\oblivionfindings`. Use
  the workstream's worktree.
