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
- **Deferred (no endpoint — not stubbed):** Extend (+30 min — no update route), post-event **Review** modal (no review columns/route), Policy **editing** (no policy store — shown read-only), Flagged **dismiss** (no route), and the handoff's structured `reason_category`/`authorization_mode`/`co_signed_by`/ack/`review_*`/`incident_report_id` columns + co-sign verification + repeat-block enforcement. The wizard captures reason-category + acks as **UI** (composed into `reason` / client-side gates). Reasons: new columns + endpoints out of scope. Core = brand 4-tab oversight surface + real grant wizard + real revoke + **audit-retention immutability fix** + derived flagged + read-only policy.
