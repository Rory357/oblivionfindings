# eMAR Redesign — Page Plan: Emergency Access / Break-Glass (`/emar/emergency-access`)

## 0. Identity
- **Route:** `GET /emar/emergency-access` → `emar.emergency_access` (`EmergencyAccessController@index`, perm `medications.breakglass`) → renders **`emergency/access.tsx`**.
- **Write endpoints — REAL ones only:** **grant** `POST /clients/{client}/break-glass` (`clients.break_glass.store` — `reason` required, `minutes` 5–1440 default 60), **revoke** `DELETE /clients/{client}/break-glass/{access}` (`emar.clients.break_glass.destroy`, owner-or-manager). **No extend / review / policy-store / flag-dismiss endpoints exist** (handoff gaps).
- **Model:** `ClientBreakGlassAccess` — `client_id`/`user_id`/`reason`/`expires_at`. **No SoftDeletes** → revoke HARD-deletes (loses audit). Fix per §5.
- **Goal:** bare search-and-grant page → break-glass oversight surface — brand hero + 4-tab `TabStrip` (Active / Audit log / Flagged / Policy) + a **Request-access wizard** on `MedsWizardDialog` (the real grant) + per-card Revoke. Honest: only real writes; derived/read-only for the rest.

## Key findings (verify-against-code)
- Grant takes a flat `reason` string + `minutes`. The wizard's reason-category + detail compose into `reason`; duration chips → `minutes`; acknowledgement checkboxes gate the final button **client-side** (no ack columns exist).
- **Revoke hard-deletes** → no "revoked" audit row. **Fix:** SoftDeletes (+ `revoked_by`) so the audit log retains revoked grants (mirrors Destructions/Self-Admin immutability).
- **Omit (no endpoint, per [[feedback_hide_unbuilt_actions]]):** Extend (+30 min), post-event Review modal, Policy toggle editing, Flagged dismiss, structured reason_category/co-sign/ack persistence. Policy panel = **read-only** real constraints; Flagged = **derived** repeat-use signal only.

## 1. Section + modal map (§1/§4)
| Block | Component | Source / endpoint |
|---|---|---|
| Hero (live eyebrow, stats Active/Granted-7d/Awaiting/Flagged, badges, actions) | `PageHero` + `brandColour` | payload + colour |
| Tabs (active/audit/flagged/policy) | `TabStrip` | client-side |
| Active grants (countdown ring, Open MAR, Revoke) | inline cards | `activeAccesses[]` + revoke endpoint |
| Audit log (history incl. revoked) | inline table | `auditLog[]` (withTrashed) |
| Flagged (derived repeat-use) | inline | `flaggedSignals[]` |
| Policy & settings (read-only facts) | inline | `policy` |
| Request emergency access (4-step) | **BUILD** `RequestAccessDialog` on `MedsWizardDialog` | `clients.break_glass.store` |

## 2. Hero spec
Eyebrow live-ping `LIVE · BREAK-GLASS MONITORING`; title "Emergency access for {site underlined / your services}"; description (time-limited, reasoned, auto-expiring, logged); meta (60 min default · 4 h max · auto-revoke); stats **Active now · Granted (7d) · Awaiting review · Flagged**; badges flagged/auto-revoke-on; actions **Request emergency access** (primary → wizard) + **Export audit** (CSV — client-side). Brand colour from `?site_id`.

## 3. Backend (§5)
| # | Gap | Action | Test |
|---|---|---|---|
| immutability | revoke hard-deletes | **migration** SoftDeletes + `revoked_by`; model `SoftDeletes` + `revokedBy`; `destroy()` sets `revoked_by` then soft-deletes (retains audit) | feature: revoke soft-deletes (record retained) |
| brand | parity | `index()`: `?site_id` brand colour + `sites` | feature: brand colour |
| oversight | only own active shown | `activeAccesses` org-scoped (live grants) w/ granted_by + can_revoke + minutes_total/expires; `auditLog` (withTrashed history → status active/expired/revoked); `flaggedSignals` (derived: ≥4 grants/user/7d); `policy` (read-only: default 60 / max 1440 / auto-revoke / reason-required) | feature: payload keys present |
- Keep the privacy-conscious server `results` search (wizard step 1 partial-reloads `only: ['results']`).

## 4. Cross-module (§6)
- Grant/revoke share `BreakGlassController` with the clients + operations break-glass routes (same model → a grant here shows there). "Open MAR" deep-links `/emar/mar?client_id=`. The "you're not assigned to this client" interstitial elsewhere can open this wizard prefilled. Auto-revoke is enforced by `expires_at` (existing).

## 5. Retire → fold into modals
- `emergency/request.tsx` separate request page → the in-page **Request wizard**. No routes removed (grant/revoke kept).

## 6. Execution checklist
- [ ] Backend: migration (SoftDeletes + revoked_by); model; `destroy()` revoked_by; `index()` rebuild (brand + activeAccesses + auditLog + flaggedSignals + policy + results). Tests.
- [ ] Frontend: `emergency/_request-dialog.tsx` (4-step wizard → grant) + `emergency/access.tsx` rebuild (hero + 4-tab + active/audit/flagged/policy + revoke + live countdown + CSV export).
- [ ] §9 gate; commit; tick PROGRESS. **THEN mark the whole 16-page loop DONE + stop.**

## 7. Notes / deferrals (backlog)
- §3d: the workflow modal is the **Request-access wizard** on `MedsWizardDialog` (real grant write).

### ✅ Pass 2 — Tier 1+2 implemented (2026-06-15)
Closed most of the original deferrals + design gaps. Migration `2026_06_15_080000_add_structured_fields_to_break_glass_accesses` adds `reason_category`, `authorization_mode`, `co_signed_by`, `acknowledged_min_necessary`, `acknowledged_incident_report`, `reviewed_at`, `reviewed_by`, `review_outcome`, `review_notes`, `incident_report_linked` (all nullable/default-off → legacy + the legacy request page keep working). Model gains `coSignedBy()`/`reviewedBy()` relations, `authorizationLabel()`, and `DEFAULT_MINUTES=60` / `MAX_MINUTES=240` / `EXTEND_MINUTES=30` constants (single source of truth for store/extend/policy payload).
- **Wizard** now 4 steps (Find → Justify → **Authorise** → Review) — co-sign approver / self-authorise persisted (authorization_mode + co_signed_by; co-signer must be a different person); acks persisted.
- **Extend** endpoint `emar.clients.break_glass.extend` (+30 min, capped at created_at + MAX_MINUTES) wired to the card Extend button.
- **Review** endpoint `emar.clients.break_glass.review` (justified/not_justified + notes + incident-linked, `medications.audit.view`-gated) + new `_review-dialog.tsx`, surfaced from the audit table's per-row Review button + Review-state pill.
- **Card** parity: two-tier reason (uppercase category + detail), `{cosign_label} · by {staff}` line, Extend button, red Revoke, `1h 55m` time format. **Audit** gains Duration + Review columns. **Hero** gains badges + an honest **Awaiting review** stat. **Flagged** gains a derived "awaiting review" signal. `store` now enforces the policy max (240, not 1440).
- Tests: `EmergencyAccessTest` (10 green) — structured grant + co-sign-different-person, duration cap, extend within/at cap, review records outcome + audit-gating, approvers/awaiting-review payload.

### Still deferred (Tier 3 — governance decisions, not stubbed)
- Editable **Policy** store (toggles shown read-only), co-sign **PIN/re-auth** verification (currently records approver only), Flagged **dismiss** persistence, repeat-misuse **auto-block** enforcement, real `incident_report_id` linkage + access-scope activity log.
- Cleanup: retire `emergency/request.tsx` (open the wizard prefilled from the MAR interstitial) + consolidate the duplicate `emar.emergency_access` / `emergency_access.index` routes.
- Browser: axe a11y / responsive + side-by-side-vs-design pixel parity on the dev server / .com.

### Pass 3 — Tier 3 + cleanup (2026-06-15, in progress)
- ✅ **1. Card de-dup** — the active grant card no longer prints the reason category twice when a grant has no free-text detail. `reasonBody` shows a *distinct* free-text detail when present, otherwise nothing for categorised grants (the uppercase eyebrow already names it); legacy rows with no category still render the raw reason. `access.tsx`.
- ✅ **2. Retire `emergency/request.tsx` + route consolidation** — the MAR break-glass interstitial (`ClientMarController@show`) now redirects to `emar.emergency_access?request_client={id}`, which pre-opens the `RequestAccessDialog` for that client (new `prefillClient` prop → wizard starts at Justify, auto-opens once unless a live grant already exists). Deleted the bare `emergency/request.tsx`. Removed the duplicate `emergency_access.index` route (`routes/clients.php`); canonical is `emar.emergency_access`, and bare `/emergency-access` still redirects there (`routes/web.php`). Compliance KPI link now points straight at `/emar/emergency-access`. Test updated to assert the redirect.
