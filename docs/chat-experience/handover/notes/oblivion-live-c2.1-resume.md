# Oblivion Live: paused 23 Oct 15:49 (Stephan's "pause all work" via the coordinator)

Branch `claude/eloquent-shamir-b83b60` (worktree `quirky-haslett-822050`), clean at `f46c5c7d5`. Nothing pushed or merged; no process of this session is running.

**Passed after the crash:** Pest batch (232 passed; the 3 failures were test-setup slips, fixed in `f46c5c7d5`), full `tsc` (0 errors), the end-to-end database `oblivion_live_e2e` (migrated and seeded: staff users 1 and 4, conversation 1).

**Never ran (killed unrun when heavy-lock v8 replaced the script):** the `OblivionLiveTest` re-run and the production build.

**To resume, in order (each through heavy-lock, one at a time):**
1. `vendor/bin/pest tests/Feature/Chat/OblivionLiveTest.php`.
2. Build with the Wayfinder plugin off for that run (`scratchpad/run-build3.sh` does it and restores `vite.config.ts`). On Windows the plugin fails creating `routes/api/medications/prn` (PRN is a reserved device name). The route files are copied from the parent.
3. Two-browser e2e: launch configs `live-e2e-app`, `live-e2e-gateway`, `live-e2e-proxy-a` (127.0.0.1:8899) and `live-e2e-proxy-b` (127.0.0.2:8900). Sign in with `/_dusk/login/1` and `/_dusk/login/4`, open `/chat?conversation=1`, time A's send to B's display, stop the gateway (polling within 15 s), check typing, then stop every server by PID.
4. Bring in the removal draft (branch `claude/oblivion-live-removal-wip`, scratch worktree `scratchpad/removal-wt`, commit `80022d227`). Then `composer install` and `npm ci` (heavy-lock), regenerate `public/legal/chat-third-party-notices.txt`, run Pest on the changed tests, vitest, and eslint on the changed files.
5. Ask the user to start the checker session for the register sign-off.
