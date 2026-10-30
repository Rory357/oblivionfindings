# PKG-03 final programme integration candidate

Prepared 28 September 2026 in `C:/Users/steph/.codex/worktrees/dcf0/oblivionfindings`, branch `codex/pkg03-local-main`. Main retains substantive review and the serial integration decision. This packet does not claim that PKG-03 is on Main, published, deployed or accepted for operational use.

## Exact source and authority

- Application/test candidate: **`e693f4269591c6db3ab29a94e466db9bc86ae266`**.
- Reconciled base: **`926b4981b0289da08a20baca0995117fb53e413e`**. Main still matched this base at final verification.
- Merge: `75c9546958b250140f7029488d1505af8a90b5eb`, preserving the previous PKG-03 history through `3a8e1ab48` and Main's published Transport, Fleet, Assets and Maps work.
- `source-manifest.json` identifies **72 changed application, test, migration, dependency-lock and build files** relative to that base, with working-file SHA-256 and committed Git blob identities. The final evidence-only successor is identified in the handoff to Main; it does not change these application hashes.
- Direct user instructions in this chat include “ok do all please”, “please start implementation you do implementation”, “can you complete it please”, and “can you let main know can you get this on main locally”. Main's PKG-03 Designer brief revision 2 independently records the later implementation/integration authority superseding the original design-only launch. On 28 September Main resumed this owner for final consolidation and audit against the exact base above. No separate remote-publication authority is inferred; Main said it would resolve that explicitly.
- The user's latest visible reference was **v9**, the repaired approval wizard and preview. Its immutable candidate hash is `18598A055D4CFBCF2035A260632D13A7072EBC6021DD234DB276ACB3D5C25981`, from `../v9/freeze-manifest.json`. All **311 frozen v1–v9 files** match their manifests. No additional formal hash-specific mockup approval is invented; the direct implementation authority and Main's verified supersession are the recorded basis for implementation. Frozen preview files remain intact in this worktree and outside the application commits.

`main-context-readonly.json` records the current Main programme context consulted. `read-only-references.json` records Rory's DESIGN, popup and work-record guides; their hashes match Main and no guide was edited. Main's dirty context and untracked work were not staged, stashed, reset, overwritten or removed. The architecture remains one organisation, with roles, approved sites, source ownership and privacy boundaries.

## Reconciliation and audit corrections

Four content conflicts were reconciled:

1. `VehicleFinanceService` retains Main's Asset Profile creation path, current asset permission and source checks, and general asset/vehicle labels. PKG-03's transactional notification intent replaces the obsolete synchronous notification method; it was not duplicated.
2. The Finance review dialog retains the shared multi-step wizard, scanned evidence preview, retained history, stale/retry recovery and assignment. Main's source-profile wording and accessible source link are retained.
3. The combined queue retains Main's Asset & vehicle wording and links together with PKG-03's server pagination, preparation/correction filters and per-record batch outcomes.
4. Maintenance retains Main's safe Fleet return link and PKG-03's cost-stage title, source records and independent operational/accounting outcomes.

Auto-merges were inspected for the Asset review type and queue source links, shared command recovery wording, wizard maximum-width/free-navigation contracts, dependency lock and routes. Main's shared Transport modal/picker changes, Assets and Maps implementation are retained.

The integration audit found two concrete compatibility gaps and corrected them in `e693f4269`:

- Outcome notices previously recognised only vehicle requesters. They now recheck current Finance/source access for non-vehicle assets and link to the approved Asset Finance profile. A site move or access loss prevents delivery; notice retries still deliver once.
- Non-vehicle Asset Profiles do not yet implement PKG-03's requester evidence-resubmission flow. Their existing **Resolve / Decline** decisions are preserved. The server rejects unsupported `changes_requested` decisions, and the queue omits that choice for those records, preventing an unrecoverable waiting state. Vehicle correction/resubmission remains available. This is an explicit capability boundary, not a claim that the additional Asset correction workflow is complete.

The previously failing published Transport allocation fixture has now been corrected on Main. Its allocation/identity case passes in this final combined run.

## Exact-source verification

- **82 backend cases, 1,523 assertions, zero failures/errors/skips, exit 0**. The 78 feature cases retain environment warnings; four pure unit cases pass without them. Both independent-process approval races pass. See `backend.xml` and `backend.log`.
- **53 UI cases across 12 files**, exit 0. Covers PKG-03 cost, preview, batch, bill, command recovery, Finance studio/history plus affected Asset review, shared booking/date-picker, Transport location and Fleet return behavior.
- Full repository TypeScript, scoped ESLint, affected PHP formatting and whitespace checks pass.
- **Actual production Vite configuration**, including Wayfinder, React compiler, Tailwind and PDF assets: **5,450 modules**, exit 0. Output was isolated in this worktree. Existing large-chunk warnings remain.
- Initial UI execution could not load the PDF worker through the shared `node_modules` junction. `ui-initial-environment.log` is retained. The successful run uses an isolated copy of the same installed PDF package and a test-only alias; no production dependency or test assertion was weakened.

`COMMANDS.md` records the bounded scope. No unrelated repository-wide backend suite, remote CI run or operational migration was claimed.

## Browser evidence and genuine zoom status

Fresh Chrome checks use the exact compiled production application and synthetic Inertia records on loopback `http://127.0.0.1:8773`. The verifier refuses all writes. It is **not a new authenticated Laravel service check**. Evidence includes:

- Asset review at measured **1440 × 900 CSS pixels**: source link, supported decisions, and rendered quote PDF inside the shared scrolling wizard.
- Bill approval at measured **1280 × 900 CSS pixels**: rendered quote PDF, bounded dialog/footer, and Escape returning focus to the pointer-activated Review & approve button.
- Bill-to-Maintenance navigation and the Cost & evidence page at 1280 CSS pixels, with page width 1268 and distinct estimate/commitment/invoice/posting/payment stages.
- No captured browser errors or warnings. See `browser-results.json` and the four viewport screenshots.

**Genuine 125% browser zoom remains unverified.** Two supported `ctrl+plus` shortcuts produced no observable change: width 2752, height 1044, devicePixelRatio 1.25, CSS zoom 1 and visual-viewport scale 1 remained identical. The device pixel ratio alone is not proof of browser zoom. Separate viewport overrides were used for desktop layout checks and reset afterward; no CSS zoom or resized viewport is labelled as actual zoom. Reuse the programme's existing supported/manual zoom setup request; no duplicate user question was issued.

The earlier genuine Laravel/Fortify test, saved note, protected PDF, bill posting, single retained receipt, balanced journal, unpaid balance and self-review denial remain retained in `../local-main/`, attributed to source `20281ab99`. Those are historical authenticated evidence, not a fresh authenticated run on this successor.

## Remaining gates and limits

- Main's substantive approval and explicit integration slot are still required under the existing programme workflow. No Main write, remote push or deployment occurred in this pass. Main must separately resolve publication scope for this previously local-only package.
- Actual 125% zoom, screen-reader speech and final user visual acceptance remain open where required. This desktop integration pass adds no phone-testing gate.
- The additional non-vehicle Asset correction/resubmission workflow is not implemented; Resolve/Decline remain supported as described above.
- Deployment must apply the reviewed migration and dependency lock, build assets, and validate workers/scheduler, durable private storage, scanner, monitoring, backups and expected load in the target environment. The migration preserves financial evidence and has no destructive automatic rollback. Legacy unassigned bills need explicit approved-site assignment.
- The signed-in fixture's clean scanner state was seeded. Neither that earlier check nor this visual pass certifies a live scanner, production throughput or operational financial processing.

All temporary verification resources are removed after archiving the small reproducible verifier. Application source is frozen for Main's review; earlier evidence remains historical rather than being silently rewritten as a new pass.
