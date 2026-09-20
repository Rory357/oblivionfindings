# PKG-01 v8 — hero navigation correction

Owner: DESIGNER ASTRA. 2026-09-19 23:44 UTC. Bounded design correction; no application implementation.

## Authority and exact delta

Stephan explicitly requested, before Sol starts, moving the screenshot's Back to work queue, View vehicle and WO-0264 in All Tasks controls into the hero under the existing rules. Main independently verified that instruction and recorded live register revision27. V7 approval and Sol High selection remain recorded; exact v8 review and implementation readiness remain pending.

The same Designer continuation is verified `gpt-6-astra / xhigh`, turn `01a0bc00-4218-7680-8947-755c32654d21`, metadata timestamp `2026-09-19T23:32:27.760Z`. Main manifest10 SHA-256 `E2421A0957C942E55B898B0FBA5B6D5280F75DD7E30E315C43BEC15B35B169A7` and all17 listed hashes matched. Master hash remains `A61469ED0C48B0A1F179D873A43E73B1254FFB9754733C3556CE6BC88AFFD990`.

Read the newer baseline DESIGN.md header section, PAGE_HEADER_STYLE_GUIDE.md §§1–4, actual PageHeader/GlassButton/Search implementations, and existing preview structure. Used the actual header slots and shared glass secondary buttons; the profile back chip is a preview-only composition in the mark slot because the shared backHref uses live Inertia navigation. No shared component or protected guide was edited.

- Back to work queue is the glass back chip before the ring mark, with its full accessible name/title.
- Resource reference VH-014 and All Tasks are glass actions next to scoped search. Their full accessible names identify View vehicle · VH-014 and WO-0264 in All Tasks. The vehicle action retains the same canonical hash destination; the task action opens the same linked-task detail.
- Report a problem remains the single white primary; selected-work search uses the existing component at200px so controls fit in one row. Queue search remains unchanged.
- Removed the old back/meta and quicklink stack beneath the hero and their unused styles. Tier-two navigation, contextual source/owner links, meters, status, tabs and all workflow state remain.
- Only version labels, local port, run instructions and generated assets change otherwise. No policy, permission, booking, Finance, date/time or source-evidence behavior was changed.

## Observed verification

Final build: `node docs/fleet-assets-audit/previews/PKG-01/v8/build.cjs` passed,2489 dependencies, actual source root worktree8424, loopback URL `http://127.0.0.1:4324/`. The initial sandbox build could not traverse a parent directory; the same bounded build succeeded through approved escalation. Formatting initially could not resolve the repo plugin from this dependency-free worktree; existing Prettier with `--no-config` succeeded. No dependency installation.

Browser source identity: tab13 title and visible banner both PKG-01 v8, served by its own4324 server. Explicit browser viewport checks:

-1280×900: hero969×241.5, all three controls inside the header, no horizontal document overflow. Screenshot01.
-1440×1000: hero1129×241.5, all header buttons/search inside its right edge, no horizontal document overflow. Screenshot02. Header height remains the same across both sizes.
-Keyboard Tab from the back chip reaches scoped search, vehicle, then All Tasks. Enter opens All Tasks · WO-0264; Escape closes and restores focus to its hero action. Task reference, owner and next action match the work record; existing task horizon explanation remains.
-Vehicle action opens `#/fleet-assets/vehicles/14`, Kōwhai van/VH-014. Its return control restores the work context; hero Back to work queue returns to Maintenance; opening the original queue row restores WO-0264. Hash-driven rendering needed a subsequent observation after navigation; final state was verified, not inferred from the immediate snapshot.
-Final browser warnings/errors: none. Both saved screenshots were viewed. Temporary viewport override reset; final tab left on WO-0264 and marked deliverable.
-Other rows remain the existing bounded summary modals; the static-asset full work profile is not newly demonstrated. Other workflow regressions were not exhaustively rerun for this placement-only change; unchanged source files and earlier frozen QA remain the evidence for their preview behavior.

## Limits and next gate

Synthetic preview only. It does not prove production links, permissions, persistence, attachments, DST, release rules, Finance approval/posting or task eligibility. Broader accessibility, zoom, reduced motion and cross-browser checks were not rerun. Full profile/calendar packages remain queued.

V1–v7 sources/assets/evidence remain frozen. Manifest records this exact v8 source/executable identity and two screenshots; README and this QA report are supporting documents outside the executable identity, consistent with the prior manifest convention. Main review and Stephan's exact-artifact gate precede Sol; model selection itself is already approved. Separate readiness/handoff records must resolve operating contracts and disposable verification before writer transfer.
