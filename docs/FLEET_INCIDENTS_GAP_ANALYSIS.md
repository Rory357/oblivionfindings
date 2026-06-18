# Fleet & Asset Incidents redesign — Gap Analysis (single source of truth)

> **NZ-only** (Waka Kotahi NZ Transport Agency; Land Transport Act 1998 **s22 24-hour injury-crash Police report** / TCR; NZ Police; ACC; WorkSafe/HSWA; Ngā Paerewa NZS 8134:2021). **Web-only** (no phone frames).
> **MODULE SCOPE:** H&S-facing slice now; broader **Fleet & Assets** module later → register/telematics-dependent items are **`PREP-LATER`** (design now, wire later).
> Loop file for the Fleet & Asset Incidents redesign. Checklist `[ ]` / `[x]`. Seeded 17 Jun 2026 from `FLEET_INCIDENTS_DESIGN_PROMPT.md` + `FLEET_INCIDENTS_LIFECYCLE_PLAN.md` and the verified code audit. Build on it; re-audit live code each pass before ticking.

## Verified current state (the baseline)
- **Spine + cross-module wiring EXISTS (reuse, don't rebuild):** `FleetIncident` model; `FleetAssets\IncidentController` (index/create/store/show/update); `FleetIncidentObserver` → `HsEvent`(CATEGORY_VEHICLE_INCIDENT) + Control Room alert (critical/major); resident cascade → `ClientIncident`(transport_incident) + `SafeguardingAlert`. `HsEvent.asset()`/`asset_id`. Routes `fleet-assets.incidents.*`; nav "Fleet Incidents" under H&S (`app-sidebar.tsx:1174`).
- **UI off-standard:** list `index.tsx` (PageHero, **no tabs**, shadcn Select filters, **per-row View button only** — no right-click); detail `show.tsx` (**full page, not tabbed**, no attachments/follow-ups/timeline); create `create.tsx` (**full-page form**, no evidence upload, location text-only despite stored lat/long).
- **Data model captures ~40%** of a complete NZ record (see A). Severity vocab = minor/moderate/major/critical (differs from client incidents).

---

## A. Data-capture completeness (THE PRIORITY — "audit for missing info, cater for it")
Design the full capture set (plan §3). Each group: design now unless marked **`PREP-LATER`** (depends on Fleet & Assets register/telematics build-out).
- [ ] A1 — Vehicle/asset: **rego snapshot, WoF/CoF status+expiry, odometer at incident**, category. *(`PREP-LATER` source from register; snapshot onto incident.)*
- [ ] A2 — Driver: **licence number/class/expiry**, on-duty/shift context, supervisor. *(`PREP-LATER` from driver profile.)*
- [ ] A3 — People aboard: **passengers/residents + per-person injuries**, restraint/wheelchair-secured, whānau/next-of-kin informed.
- [ ] A4 — **Third party** (entirely absent): involved flag, name/contact, **vehicle rego + make/model**, **insurer + claim ref**, liability, injuries.
- [ ] A5 — Witnesses (absent): name/contact/statement; attending officer.
- [ ] A6 — Scene & conditions (absent): **map-picked location** (lat/long), road type, **weather**, **lighting**, traffic, speed limit, estimated speed.
- [ ] A7 — Damage & recovery: **severity classification** (light/repairable/write-off), **drivable / tow required** + provider, **off-road (VOR) from→to + service-resumption**, cargo/equipment damage.
- [ ] A8 — **Police & regulatory (NZ, compliance-critical):** injury/fatal flag → **Land Transport Act s22 24-hour Police report** (countdown + **TCR reference**); **WorkSafe-notifiable** + notified-at + ref; **ACC** claim + ref; breath/drug test.
- [ ] A9 — Insurance & cost: insurer, excess, amount sought/approved, contractor, **actual repair cost**, total incident cost.
- [ ] A10 — Investigation: assigned owner, root-cause, **corrective actions**, investigation-completed-at.
- [ ] A11 — Non-vehicle asset specifics: serial snapshot, condition before/after, warranty, replacement cost (form branch when `category != vehicle`).

## B. Backend foundation (Step 1 — DONE on `feat/fleet-incidents-redesign`)
- [x] B1 — Expanded `fleet_incidents` schema for A (one grouped migration `expand_fleet_incidents_capture`, 70 cols §3.1–3.12, all nullable/defaulted); register/licence snapshot columns added (populated `PREP-LATER`).
- [x] B2 — **`FleetIncidentAttachment`** table + model (mirrors `SafeguardingAttachment`; `kind`/`notes`/`alt_text`). Upload/download routes = Step 2.
- [x] B3 — **`FleetIncidentFollowup`** table + model (mirrors `IncidentFollowup`, FK to `fleet_incidents`). Endpoints = Step 2.
- [x] B4 — **Direct FK `client_incidents.fleet_incident_id`** + `ClientIncident::fleetIncident()` / `FleetIncident::clientIncidents()` reverse relations (Gap F1).
- [x] B5 — **s22 24-hour Police-report** model logic (`requiresPoliceReport`/`isPoliceReportDue`/`policeReportDueAt`/`policeReportHoursRemaining` + `police_report_due_at`/`_logged_at`/TCR cols) + WorkSafe cols mirroring `ClientIncident` (`is_notifiable`/`worksafe_*`, classified via reused `NotifiableEventClassifier`) + ACC cols (A8). Wizard/controller wiring = Steps 2/5.
- [x] B6 — **Severity vocab** decided (Gap F4): keep minor/moderate/major/critical, map at boundaries via `FleetIncident::mapSeverityToHs()`; **fixed the observer bug** (major never alerted / HsEvent recorded as low).
- [ ] B7 — `PREP-LATER`: register snapshots (A1) + driver licence (A2) columns exist but unpopulated; telematics crash-detection bridge (Gap F2) = storyboard only (Step 6).

## C. List (`/fleet-assets/incidents`) — hero, tabs, rows
- [ ] C1 — Replace `PageHero` with **`hs-hero-kit`** treatment; fleet clusters (This period / Needs attention incl. **Police report due**, **Off-road**, Injury/ACC, Open claims); **no** H&S compliance badges.
- [ ] C2 — `TabStrip`: All · Open · Under investigation · **Police report due** · Injury/ACC · Insurance & claims · **Off-road (VOR)** · Near misses · Closed.
- [ ] C3 — Footer band: date-range `HeroSegmented`, Site + Vehicle/Asset + Driver `EntityFilter` (`onDark`), type + severity filter, search; keep CSV export.
- [ ] C4 — Replace per-row View button with **`ShiftContextMenu`** right-click (copy PRN `openRowCtx`) + row click → detail modal. Item set per design §2c.
- [ ] C5 — Inline row badges: type, severity, off-road, injury/ACC, Police-report-due, claim open, alert-linked.

## D. Report wizard (modal) — capture-complete + branches
- [ ] D1 — Rebuild on **`WizardShell`** (Add-Client contract) as a **modal**; retire full-page `create.tsx`. Steps per design §4 covering the full A capture set.
- [ ] D2 — **Photo/evidence capture at report time** (drag-drop / file / webcam) (B2).
- [ ] D3 — Police/regulatory step auto-surfaces the **s22 24-hour duty** + TCR; auto-flags WorkSafe-notifiable; ACC (A8).
- [ ] D4 — **Map picker** for location/scene (reuse existing map; A6).
- [ ] D5 — **Branches:** `near_miss` (shorter, blame-free) + **non-vehicle asset** (asset-specific, no vehicle/Police steps) (A11).
- [ ] D6 — `WizardSuccessPane` (Report another / Done / Open incident) + Inertia partial reload.

## E. Detail = modal (PRN-style; retire navigate-away)
- [ ] E1 — Build **`FleetIncidentDialog`** on `WizardShell` read-only chrome (rail = sections, footer = Options bar); opens **over the list**. Keep `/fleet-assets/incidents/{id}` as deep-link fallback.
- [ ] E2 — Rail sections: **Overview** (stage tracker + injury/Police/WorkSafe banners + **24h countdown**) · **Vehicle/asset** · **People** (driver / passengers-residents / third parties / witnesses) · **Scene & conditions** (map) · **Damage & recovery** · **Police & regulatory** · **Insurance & cost** · **Photos & documents** · **Investigation & follow-ups** · **Linked records**.
- [ ] E3 — Options bar actions open modals in place (Edit · Update status · Add follow-up · Upload · Log Police report · Log claim · Mark off-road · Export).
- [ ] E4 — **Modal-first sweep:** all workflows are dialogs — no page navigation in the normal path.

## F. Lifecycle + cross-module
- [ ] F1 — Lifecycle stage tracker (Reported → Investigating → Resolved → Closed) + **closure gate** (warn on injury-crash without Police report, WorkSafe-notifiable not notified, open follow-ups/actions) (plan §4).
- [ ] F2 — **Linked records both ways:** fleet incident ↔ per-resident client incidents + safeguarding alerts (direct FK, B4); surface on both detail modals.
- [ ] F3 — Surface "active Control Room alert" banner + the H&S event/investigation (read-only, "Open in Health & Safety" jump).
- [ ] F4 — **`PREP-LATER`** storyboard: telematics crash-detection → operator confirm/dismiss → drafts `FleetIncident` (mirror client-incident `fall_detected`).

## G. Standardisation, a11y, scope
- [ ] G1 — Hero/tab/filter/right-click/modal idioms match the app verbatim (near-twin of Incidents & Safeguarding).
- [ ] G2 — Semantic tokens only (no raw `oklch()`); WCAG AA; keyboard-operable rows/menus/modals/uploads; no colour-only meaning; alt/notes on evidence; lazy-load galleries.
- [ ] G3 — Web-only (no phone frames); responsive reflow.

## H. NZ frameworks (current)
- [ ] H1 — Waka Kotahi NZ Transport Agency (rego/WoF/CoF); **Land Transport Act 1998 s22** 24-hour injury/fatal-crash Police report via 105 / TCR; ACC; WorkSafe/HSWA notifiable; strip any UK/other-region (MOT/DVLA/RIDDOR).

---

### Decisions log
- **17 Jun 2026** — Adopt the H&S gold standard: `hs-hero-kit` hero, modal-first incl. **detail-as-modal** (`FleetIncidentDialog`), `ShiftContextMenu` right-click, `WizardShell` report modal. `/fleet-assets/incidents/{id}` kept as deep-link fallback.
- **17 Jun 2026** — **Capture-completeness is a first-class goal** (user priority): expand the schema to a complete NZ fleet/asset incident record (A). Spine/cross-module wiring already exists and stays.
- **17 Jun 2026** — Module split: H&S-facing incident slice now; register/telematics-dependent fields + the telematics→incident bridge are **`PREP-LATER`** (designed now, wired when Fleet & Assets is built out).
- **17 Jun 2026** — Build around **Land Transport Act s22**: a 24-hour Police-report duty/countdown for injury/fatal crashes (TCR reference) is a first-class workflow element.
