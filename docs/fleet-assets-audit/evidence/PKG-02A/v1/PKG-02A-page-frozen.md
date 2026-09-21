# PKG-02A — Client Location

Status: **v1 design candidate frozen for Stephan's review; exact-version and bounded-scope approval pending.** No implementation release is implied.

## Identity and authority

- Designer task: `01a0be31-19ef-7d10-86a9-cbe968989a76`.
- Worktree: `C:/Users/steph/.codex/worktrees/2b9f/oblivionfindings`.
- Branch: `codex/pkg-02a-client-location-design`.
- Verified source and origin/main baseline: `2302ca33a95616e442a78e957ddce82d8db98669`.
- Actual initial turn: `01a0be31-1b3d-7450-b4b2-1b17b1d3e579`, `gpt-6-astra` / `xhigh`. Main independently verified metadata and released isolated mockup writes before they began. All design in this task uses Astra Extra High.
- Canonical authority: Revision 10 + A1–A5 master, SHA256 `AAD1B712C20F5387CE6E1AF7FD7F76EFA74F7121BEE0CB5E3CCC170AB5D1D2FA`, and Main's PKG-02A Designer handoff. Current programme records were read from the canonical Herd docs; implementation and design guides were revalidated at the fresh 2302 baseline.

## Reviewable version and scope

[Open PKG-02A v1](http://127.0.0.1:4332/PKG-02A/v1/).

The bounded surface is **Client Snapshot → Location**, with reciprocal **Relationships & governance → Consents** context and a canonical tracking-assignment preview. It preserves the existing profile identity, useful header meters, navigation groups and Snapshot tab order.

The page distinguishes collection authority, the staff viewer's permission, named-recipient disclosure and client self-access. It shows factual observation time/timezone, source, age, accuracy and device availability without inferring wellbeing. It includes authorised, absent, restricted, uncertain, stale, expired, withdrawn, reassigned, loading, empty, failed and limited-action examples, plus an illustrative five-step recipient review and withdrawal demonstration.

All names, accounts, places, evidence references and telemetry are synthetic. No client data is read, no consent or sharing grant is written, and no locate command or export is issued. The review outcome explicitly changes no access. New review fields have no policy defaults. Illustrative existing-grant values are labelled examples.

Excluded: resident-tracking overview, Fleet device register/health, vehicle compliance/readiness, vehicle calendar, whole-profile redesign, full Consents rewrite, a new evidence store, mobile deliverables, and a generic emergency override.

## Artifacts

- Preview source and built assets: `../previews/PKG-02A/v1/`.
- Exact file hashes: `../evidence/PKG-02A/v1/artifact-manifest.json` and `artifact-manifest.sha256`.
- Source and guide identity: `../evidence/PKG-02A/v1/baseline-inputs.json`.
- Revalidated boundaries and later contract: `../evidence/PKG-02A/v1/source-contract.md`.
- QA record: `../evidence/PKG-02A/v1/qa-results.md`, `state-checks.json`, `console-check.json` and `screenshots/`.
- Required rendered viewports: **1280×800 and 1440×900**. Earlier 1366×768 captures and exploratory captures under `output/playwright/pkg-02a-v1` are not frozen acceptance evidence.

Serve the frozen build with `node docs/fleet-assets-audit/previews/PKG-02A/v1/serve.mjs`. The server binds only `127.0.0.1:4332`, uses no-store headers, denies non-read methods, and disallows network connections through its content security policy. It needs only Node to serve the existing `dist` files. `/__preview` exposes synthetic preview identity. Do not rebuild v1 after approval; changes require a preserved new version.

The build reused baseline components through a local read-only dependency junction. Vite's cache lives inside the isolated preview and is excluded from delivery. Application source, shared guides, shared Herd branch/index, worktrees 8424/2375 and the PKG-01 v8 preview on 4324 were not changed.

## Review result and remaining gates

Forty state/viewport combinations passed the recorded visibility and overflow checks. Key dialogs, search failure/retry, date/time cancellation, exact-minute entry, history access loss, reciprocal context, limited actions and keyboard return were exercised. The clean static-build browser session has no captured warnings or errors. A reduced 640×400 desktop viewport checked reflow; actual 200% browser zoom remains unverified because the available in-app browser ignores zoom shortcuts. This is recorded explicitly rather than represented as a pass.

Focused privacy decisions remain open: permitted purposes/audiences/scopes, decision-maker authority and review responsibility, grant duration/review/expiry rules, retention and withdrawal effects, client self-access rules, and observation freshness/availability thresholds. Prototype fixtures are not policy.

Implementation requires Stephan's approval of **this exact v1 and bounded scope**, required PKG-01 closure, the applicable privacy decisions and Main's technical release. The same Designer then owns frontend at Astra Extra High and may supervise one backend-only Sol assignment with recorded effort and explicit ownership/handoffs. No Sol or additional task has been started. Main retains substantive exact-code technical approval and publication verification; Stephan retains final page acceptance.

