# eMAR P00 v2 — Medication rules & states

**Status: design candidate awaiting Stephan's exact-version approval.** Not approved, not implemented. Supersedes v1, which was never approved and stays frozen in `../v1/`.

- Package: P00 (shared rules and states), approved to start by Stephan on 28 September 2026.
- Version: v2, 29 September 2026 (NZDT). Baseline `52dafa672`, branch `claude/vigilant-mclaren-233129`.
- Exact file identity: [`VERSION.txt`](VERSION.txt) (SHA-256 of every mockup file). Approval applies to those hashes only.

## Why there is a v2

1. **The P0 fixes landed** (branch `claude/friendly-greider-b40f6d`, not yet accepted). v2 matches the corrected behaviour and wording:

   | Fix | Commit | What v2 now shows |
   |---|---|---|
   | EM-26 as-needed dose false success | `399a5ab98` | A refused save stays on Review: "Not recorded — this dose was not saved." Offline items refused on replay get an app-wide banner and are never re-sent. New "Not confirmed" state. |
   | EM-12 Tasks controlled-medicine leak | `e2bbe2368` | Concealment unchanged (already designed in v1). |
   | EM-07 allergy source and wording | `1410ef3f4` | Allergies from the medication allergy list and the health profile. Three notices; health-profile match warns by default. |
   | EM-01 dashboard counts | `1b9060953` | "Overdue — not recorded", "Missed", "{n} overdue", "All recorded", "Given of due", n/a. |
   | NF-03 competency restrictions | `dda3beef6` | Restricted competency is an organisation setting (Off / Block / Co-signer). Areas: Controlled drugs and Covert administration (failed / not seen). Insulin deferred. Organisation-wide safety rules card in Settings. |

2. **Stephan asked for three additions (29 September, relayed by the review session):**
   - **Allergy match as an organisation choice**, side by side: Warn · Block · Block unless the prescriber has confirmed this allergy on the order (who, when, source; shown on the order and at dosing). The specific match ("Amoxicillin — matches recorded penicillin allergy (health profile)") shows before signing in every mode. Blocked next steps: contact the prescriber, or an authorised override with a reason.
   - **Second-person confirmation without a colleague's login password**, as options for restricted competency and for controlled-drug witnessing: A block and show who can give it · B personal witness PIN · C confirm from the colleague's own session. Each shows success, declined, expired, locked and no eligible colleague.
   - **My Day and handover touchpoints** as reference frames: the My Day medication card (same counts as Meds today, Open meds, own follow-ups) and the handover medication lens (live follow-ups with owner and due time; acknowledging closes nothing; a controlled count mismatch raises the real discrepancy record).

3. **Stephan's review corrections to v1**, which were existing DESIGN.md rules the mockup had missed:
   - Every row now has the ⋯ actions button **and** the same menu on right-click (also Shift+F10 or the menu key), per LIST_STYLE_GUIDE §1.
   - Times are entered with the approved **clock and manual time picker** (POPUP_STYLE_GUIDE, 20 September 2026), not a text box.
   - Every outcome has an optional **"What happened" note**.

## Open it

```
node docs/emar-design/P00/v2/serve.mjs
```

Then open http://127.0.0.1:4360/. The hatched bar is the mockup viewer, not product UI: *Navigation frame* / *State catalogue*, *Signed in as*, *Scenario*, and (with the co-signer scenario) *Second person*.

| Try | Link (append to the URL) |
|---|---|
| Allergy modes on Mele's amoxicillin | `#/frame/sw/today/schedule?allergy=warn` · `=block` · `=confirm&confirmed=0` · `=confirm&confirmed=1` |
| Change the allergy rule for real (as clinical lead) | `#/frame/clinical/settings/rules`, then switch to Support worker |
| Co-signer options | `#/frame/sw/today/schedule?scenario=restrictedCosigner&method=B&open=record:r2:1&outcome=given` (method `password`, `A`, `B`, `C`; add `&nobody=1`) |
| Time picker | `#/frame/sw/today/schedule?open=record:r2:1&outcome=given&tp=clock` |
| Row menu | `#/frame/sw/today/schedule?allergy=block&open=menu:dose:r3` (or right-click any row) |
| Offline refused / not confirmed | `?scenario=offlineRefused` · `?scenario=uncertain` |
| My Day / handover | `#/myday/sw` · `#/handover/sw` |
| Catalogue | `#/catalogue/intro` (one section: `#/catalogue/second?only=second`) |

## State checklist (v2)

Everything from v1 remains ([v1 README](../v1/README.md)). New or changed in v2:

- Allergy status: **recorded** (both sources, "check the label against the allergy list") · **no allergies recorded** (amber) · **record couldn't be loaded** (amber) · **health-profile match — warn** · no known drug allergies (only once D5 approves)
- Allergy match rule: **mode 1 Warn · mode 2 Block · mode 3 Block unless the prescriber confirmed** (not confirmed / confirmed) · **specific match line** · **prescriber confirmation on the order** · **authorised override**
- Blocked reasons: **You can't sign doses as given** (restricted, Block rule) · **Co-signer required** (Co-signer rule, with fields and ineligible co-signer) · **"Controlled drugs" not passed** · **"Covert administration" not assessed** · **Allergy match — don't give (Block)** · **Allergy match — the prescriber hasn't confirmed it** · competency expired and exemption ended now critical (matching the implemented notice)
- Second-person confirmation: comparison table; restricted competency **A / B / C**; controlled-drug witness **A / B / C** (with the presence caveat); each with success, declined, expired, locked, no eligible colleague (or "not applicable" and why)
- Recording lifecycle: rejected online (**stays on Review**) · **offline item refused when sent** (app-wide) · **not confirmed** (app-wide + row) · sending · confirmed · queued · corrected
- Counts for leads: **Overdue — not recorded · Missed · {n} overdue · All recorded · Given of due / n/a**
- Organisation setting pattern: **Default — not yet reviewed** · Set by … · confirm dialog stating the effect · read-only for people without all-sites authority
- Universal: **row actions (⋯ + right-click + Shift+F10)** · offline · order changed · already recorded · validation kept · resume · focus return
- Time: **clock and manual time entry** (hours then minutes, typed exact minute, AM/PM, Use time / Cancel, Escape first, zone visible, future time rejected) plus the nine display rules
- Touchpoints: **My Day medication card** (incl. couldn't load / loading / nothing left) · **handover medication lens** (acknowledge, live follow-ups, count discrepancy, hidden for roles without controlled access)

## Recommended changes to the P0 fixes (for Stephan's acceptance review — not applied)

The P00 contract differs from the implemented wording or style in these places. Each is small; none changes a server rule.

1. "No allergies recorded" uses the brand info tint. It should be fixed amber (DESIGN.md non-negotiable #6: safety surfaces are brand-independent).
2. The offline banner shouts "NOT recorded". Use "not recorded" with "not" in bold (plain language, sentence case).
3. "A manual retry will reuse the original request ID" is developer language. Use "Check the chart before trying again. Trying again won't create a duplicate."
4. The health-profile safety alert reads "⚠️ ALLERGY ALERT: Client has a recorded allergy to …". Use "Possible allergy match — {Medicine} matches recorded {allergen} allergy (health profile)", with no emoji, no capitals, and the person's preferred name.
5. "Only someone who manages eMAR settings for all sites …" should say "medication settings".
6. "Not recorded — this PRN dose was not saved." should say "this dose", and add "The chart doesn't show this dose."
7. Optional: show "Default — not yet reviewed" on each safety rule until someone deliberately saves a choice (needs a stored flag).

## Verification record (29 September 2026)

- All 52 routes, 13 scenarios and 3 allergy modes load with no console errors (checked in the live preview).
- Flows exercised:
  - row menu by ⋯, right-click and Shift+F10
  - time picker: hour → minutes, typed exact minute, AM/PM, invalid minute, Escape keeps the value, future time rejected
  - optional note carried to Review
  - allergy modes 1–3 (row, meter and dialog follow the setting)
  - co-signer with password (validation, ineligible co-signer refused on Review), option A, option B (locked PIN), option C (send → decline → send again → approve)
  - no eligible colleague
  - Not confirmed; offline refused (banner on every page until dismissed)
  - safety rules: read-only as the house lead, editable as the clinical lead, confirm dialog, "Set by …"
  - My Day counts match Meds today
  - handover acknowledgement leaves work open; controlled count hidden for the clinical lead
- No horizontal page scroll at 200 % zoom (720 px CSS viewport, device scale 2) on Meds today, dialogs (time picker, co-signer C, allergy block), Settings, My Day, handover and every new catalogue section (document width = client width).
- Real Tab key presses (Chrome DevTools Protocol) at 1440: the ⋯ buttons follow each row's own actions; handover order is breadcrumbs → Acknowledge → follow-ups → dose → discrepancy.
- Screenshots: 68 in `screenshots/` (1440, 1280, zoom200).
- The preview uses a temporary `emar-p00` entry in the worktree's git-ignored `.claude/launch.json`.

## Decisions for Stephan

New in v2:

- **Allergy match rule:** mode 1 Warn, mode 2 Block, or mode 3 Block unless the prescriber has confirmed it. Mode 3 needs new storage on the order. Also decide whether the rule covers severe matches in the medication allergy list, which always block today.
- **Second-person confirmation, one choice per use:**
  - restricted competency: A, B or C
  - controlled-drug witness: A, B or C, where C needs a presence rule (D8)
  - either way, whether today's login-password method is retired
- **Restricted competency setting value** (NF-03): Off, Block or Co-signer. The default is Off, which doesn't enforce restrictions.

Still open from v1:

- **D2:** recording after a shift ends, override rights, identity check without a photo.
- **D4:** timing rules, re-offer, how as-needed limits are counted, DST reschedule, the witness request time limit and PIN attempt limit.
- **D5:** allergy source of truth and "No known drug allergies" status; whether "given" pauses when allergies can't be read.
- **D6:** support levels.
- **D7:** offline kept or paused.
- **D8:** controlled-drug count cadence and witness credential. B and C above feed into this.
- **D9:** controlled-medicine need-to-know.
- **D11:** print pack.
- **D12:** escalation and on-call contacts.
- **D13 (proposed):** covert process.

Also reported by the P0 session:

- the co-signer picker on the guided round, client-profile MAR, shift card and transport (P01)
- "can administer unsupervised" and insulin enforcement are deferred
- the main /dashboard medication widget still uses the old admin-rate maths

## Approval requested

Please approve **eMAR P00 v2** exactly as identified by the hashes in `VERSION.txt`, or list the changes you want for a v3. Nothing after P00 starts until this version is approved and the P0 fixes are accepted.
