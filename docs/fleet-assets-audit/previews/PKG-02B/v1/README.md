# PKG-02B v1 — Vehicle profile and readiness

Synthetic desktop design candidate for Main and Stephan's exact mockup review. This is not the operational application. No mockup approval or implementation release is recorded.

Open `http://127.0.0.1:4336/PKG-02B/v1/` while the preview server is running. The version and synthetic-data banner remain visible. `/__preview` reports the worktree, base and candidate version; file hashes in the sibling evidence folder identify the exact frozen source and bundle.

From repository root, serve the already built candidate with:

```powershell
& 'C:/Users/steph/.hermes/node/node.exe' 'docs/fleet-assets-audit/previews/PKG-02B/v1/serve.mjs'
```

The server binds to loopback only. It serves the frozen `dist` files, accepts GET/HEAD, and blocks browser connection requests with CSP. All records, people, rules, dates, responses and upload outcomes are synthetic. Demo state lives in memory and resets on reload or scenario change. File previews remain local; no application API, database, tracker or notification is used.

## Suggested review

1. Start with Active restriction. Read the readiness reasons, source dates, mileage, service schedule and unknown RUC/applicability evidence. Open source details and return.
2. Visit Service & compliance, Checks & inspections, Maintenance and Calendar. Header meters, Find and the evidence filter navigate the same vehicle context.
3. Start a check. Choose a searchable template, set an observation time, complete the fictional examples and review the original answers/version. A recorded issue can create or link existing Maintenance. Saving a check never releases the vehicle.
4. Follow a failed check into the report wizard. Review its fixed vehicle/source, advisory date range, authorised existing-work choice and Coordinator route. Use First save interrupted and Search failure to inspect retained drafts and retry.
5. Open work, expand notes and add evidence. Partial upload failure demonstrates the saved first file, failed second file and retry. “Saved in this preview” is a simulated result, not durable storage.
6. Compare Ready, Unknown, Overdue, Awaiting release, Stale/no tracker, Empty, View-only, Report-only and Denied scenarios. Open the Busy calendar item to inspect the limited disclosure.

The Calendar destination is a context list; full booking/day/week/month interactions remain PKG-04. Work details are a bounded destination demonstration of existing Maintenance ownership, not a replacement implementation. Genuine 200% browser zoom remains unverified; viewport resizing is not zoom evidence.

## Reproduction and preservation

The base is `5307692ec59be84f3503c06354419b7da95be805`, branch `codex/pkg-02b-vehicle-profile-design`, worktree `5b0a`. Shared components and styles come unchanged from that base. Existing local node_modules are reused through a junction; no dependencies were installed.

Type check: `node node_modules/typescript/bin/tsc --noEmit --project docs/fleet-assets-audit/previews/PKG-02B/v1/tsconfig.json`.

Build command used: `node node_modules/vite/bin/vite.js build --config docs/fleet-assets-audit/previews/PKG-02B/v1/vite.config.mjs`. Do not rebuild or edit this version after its manifest is frozen. Revisions must use a new version folder and preserve v1 and its evidence.

Review evidence: `docs/fleet-assets-audit/evidence/PKG-02B/v1/REVIEW.md`. Source ownership and unresolved decisions: `docs/fleet-assets-audit/evidence/PKG-02B/reuse-contract-v1.md`.
