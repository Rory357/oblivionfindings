# PKG-06B — Asset Profile v5 review

Status: **Design candidate awaiting Stephan's explicit mockup approval.** No application implementation or activation is implied.

[Open the revised Documents view](http://127.0.0.1:8900/#view=overview&section=library&scenario=normal) · [Open Summary](http://127.0.0.1:8900/#view=overview&section=summary&scenario=normal)

The Vehicle Profile consistency pass and all three additional audit/improvement rounds are complete. Versions v1–v5 remain separate. AS-104 — Transfer hoist and every displayed person, file, source reference and operation are synthetic. The fixed sample clock is 26 September 2026, 10:42 am NZST, intentionally retained when this review completed on 27 September locally.

## What improved

**v2 — Vehicle Profile consistency.** Reused the published Vehicle Profile structure: profile search and actions, four header meters, connected navigation and Overview's Summary / Asset details / Documents / Finance grouping. Details have an identity/photo area and attachment shelf. Documents, Maintenance and kit use the actual shared Vehicle record collection with list/card views and row actions. Photos and archived versions are document filters. Search covers titles, files, types, sources, references and people. Next actions lead Summary.

**v3 — custody and work gaps.** Accepting a discrepancy no longer confirms complete receipt, even when all kit items are checked. Dispute/discrepancy requires notes; complete receipt requires every item. A receipt does not clear the independent hold. Issue reporting distinguishes a new issue from a follow-up to MW-271, preserves failed drafts and demonstrates a single local result on retry.

**v4 — document lifecycle gaps.** New uploads have distinct synthetic document-set identities. Replacement preserves the source, classification, prior versions, reason and uploader. Archive includes reason, actor/time and failure/retry recovery. Empty libraries can receive their first local record; header counts follow the local records. Staged report attachments are visible/removable. Profile photos validate image type and show a local image after confirmation.

**v5 — overall access, navigation and layout audit.** Closed read-only shortcut bypasses, retained unsaved Maintenance edits, restored authority on browser Back, preserved old document-scope links, improved menu/dialog focus and narrow desktop layout, retained exception attribution and added a dark-theme preview. Replaced the custom date picker with the shared calendar after finding a year-boundary bug.

See [consistency findings](../v2/qa-inventory.md), [round 1](../v3/ROUND-1.md), [round 2](../v4/ROUND-2.md), and [round 3](ROUND-3.md).

## Vehicle Profile source-to-design mapping

- `vehicle-workspace/vehicle-header.tsx` and `pages/fleet-assets/vehicles/show.tsx`: identity, primary actions, search, four meters and Overview navigation rhythm.
- `vehicle-workspace/overview-details.tsx`: photo/identity, facts and attachments hierarchy.
- `vehicle-workspace/overview-documents.tsx`: discoverable document search, source/availability filters, list/cards, version and archive actions.
- `vehicle-workspace/record-collection.tsx`: imported directly for the shared collection and view toggle, with actual Entity table/card/menu primitives.
- `vehicle-workspace/studio.css`, `mockup-port.css`, `workspace.css`: reference styles only; asset-specific adjustments stay in the owned preview.
- `maintenance/date-picker.tsx`, `hr/leave-calendar-range.tsx`, `maintenance-date-time.css`, `lib/datetime.ts`: actual shared date selection and formatting, imported read-only.
- Existing PageHeader, GroupedProfileNav, Dialog, Wizard, Command and FileDropzone remain bundled read-only. `build-inputs.json` identifies the actual build inputs.

The current published Vehicle Profile source is the reference. The old port 4348 preview was unavailable; it was not treated as current evidence. A historical Vehicle screenshot was only secondary visual context. The prior [canonical ownership map](../v1/REUSE.md) still applies. Current source hashes are in `source-references.json`.

## Coverage and evidence

Browser verification covers all 14 subviews: Summary, Asset details, Documents, Finance, Current custody, Movement history, Original checks, Service & calibration, Issues & work, Location & observations, Kit contents, Replacement history, Asset history and Retirement review. Navigation and reload were checked. The final view gallery is indexed in `view-evidence.json`.

The final log records 14 passing checks and no captured runtime errors. Three failed harness attempts are retained and explained in [round 3](ROUND-3.md); corrected checks pass. Earlier version logs also retain development failures. v3 has four passing workflow checks and v4 has five passing evidence checks. This is focused browser verification, not a production regression suite.

Desktop widths checked: 1600, 1280 and 1024 pixels, including overflow navigation, menus and list/cards. Light/dark, relevant dialogs, focus and selected keyboard journeys were checked. **Genuine browser zoom is unverified.** Mobile/tablet/native are outside this desktop design brief.

The final bundle builds. Scoped TypeScript finds no candidate-file errors but flags an existing imported PageHeader `dusk` attribute diagnostic. No full-repository green typecheck is claimed. Server identity, served/disk bundle hashes and the frozen v1 preservation check are recorded in `server.json` and `preservation.json`; the manifest hashes this candidate's preview and evidence files.

## Remaining gaps before implementation

- **Policy inputs:** approved check templates/versions, service or calibration intervals, independent release authority, custody/loan applicability and approval scopes, evidence retention and retirement/disposal authority. These remain illustrative or unresolved rather than live defaults.
- **Backend contracts:** real atomic assignment/receipt and concurrency/idempotency handling; immutable checks and Maintenance linkage; private upload, scanning, access denial, version/archive and recovery; persistent history; permission enforcement for roles, approved sites and direct objects. Preview interactions do not prove these services.
- **Canonical handoffs:** Register/stocktake, Vehicle, Site/room, Device health and Finance remain labelled context viewers. Real routes, projections and permission checks need later integration. There is no new registry, device control or duplicate financial approval.
- **Retirement decisions:** unresolved loans, assignments, kit, Maintenance/evidence and Finance dependencies remain visible and block an invented successful disposal. No hard-delete or accounting write-off is performed.
- **Verification still required later:** genuine desktop zoom, production accessibility review, real permission/concurrency/private-file tests, and production end-to-end checks after approved implementation.

## Boundaries and review gate

One operating organisation across approved sites. Roles, canonical ownership, direct-object denial and privacy remain the boundary. No tenancy, operational database/file writes, notifications, paid services or external persistence. Local state resets with reload. The server is loopback GET/HEAD only with `connect-src 'none'`.

Only PKG-06B preview/page/evidence files were written. Application, shared components, routes, schema, guides and other packages were not changed. No worker, sibling contact, commit, merge or push occurred.

The [design handoff](C:/Users/steph/Herd/oblivionfindings/docs/fleet-assets-audit/handoffs/PKG-06B-DESIGNER.md) requires: “Present the exact candidate directly to Stephan and stop at explicit mockup approval.” Review v5 as this exact candidate. Approval of other packages does not approve implementation here. v1's page and manifest are preserved; the new v5 page points to this packet.
