# eMAR P00 v3 — Medication rules & states

**Status: design candidate awaiting Stephan's exact-version approval.** Not approved, not implemented. Supersedes v2 (frozen in `../v2/`, never approved); v1 is also frozen.

- Version: v3, 29 September 2026 (NZDT). Baseline `52dafa672`, branch `claude/vigilant-mclaren-233129`.
- Exact file identity: [`VERSION.txt`](VERSION.txt) (SHA-256 of each mockup file). Approval applies to those hashes only.
- Everything in the [v2 README](../v2/README.md) still applies unless changed below.

## What changed from v2

1. **Date and time use the approved control itself.** The approved PKG-01 composition (`DateTimeField`, `DatePicker`, `TimePicker` in `components/fleet-assets/maintenance/`, styles from `resources/css/maintenance-date-time.css`), copied view-for-view:
   - a fieldset with a "Pacific/Auckland" legend
   - 66 px outline triggers with an icon tile, a bold value and a helper line ("28 Sep 2026 · Choose a day on the calendar", "09:12 AM · Choose on the clock or type a time")
   - the calendar popover with summary and "Use date"
   - the clock popover with 83 × 76 digit boxes, AM/PM, Hours/Minutes tabs, a 256 px dial and Type time / Cancel / Use time
   - popovers float above the dialog and flip when there's no room, like the Radix popover
   - a date or time later than now is rejected

   (Stephan: "this still does not show the new style".)
2. **Clicking a row opens it** (Stephan: "i also cant left click on schedule items?"):
   - a due dose opens Record
   - a blocked dose opens "Why can't I record this?"
   - a recorded or not-yet-due dose opens its detail
   - follow-up rows open their main action; people rows open the medication record

   The ⋯ button and right-click still open the actions menu.
3. **Second-person confirmation is the personal 6-digit witness PIN** (Stephan chose option B, 29 September, relayed by the review session):
   - Used for restricted-competency co-signing and controlled-drug witnessing. The login-password method is retired; A and C stay in the table as "not chosen".
   - The colleague is chosen from a searchable picker of people on shift now. Anyone with no PIN set is listed as "no PIN set" and can't be chosen.
   - Mockup PINs: 000000 is a wrong PIN; 999999 is a locked PIN.
4. **Forgotten-PIN fallback.** A tickbox "They've forgotten their PIN" switches to naming the colleague from the list.
   - **Decided by Stephan:** the named colleague gets an item in their own login ("I was there" / "I wasn't there"). "I wasn't there", or no answer within the time limit, raises a follow-up for the house lead. Both appear in Safety & oversight › Follow-ups.
   - **Proposals for Stephan to judge:**
     - the dose is marked "Second person not verified — PIN forgotten" (on the dose, in the audit trail and in reports), never shown as a PIN-verified signature
     - an organisation switch for the fallback, plus a separate switch for controlled drugs
   - The time limit and follow-up routing read "Not configured".
5. **PIN management:**
   - **Settings › Second-person confirmation:**
     - method (witness PIN; login password retired)
     - PIN length fixed at 6
     - wrong-attempt limit, lockout length, optional renewal
     - fallback switches (general and controlled drugs)
     - who can reset another person's PIN
     - confirmation time limit and follow-up routing

     All unapproved values read "Not configured" and fail closed. Saving goes through a confirm dialog and a change history. Read-only without all-sites authority.
   - **Staff witness PINs** (in the same view): status only (set, not set, locked, reset — must set a new one), with the ⋯ and right-click menu. Reset is disabled until the organisation chooses who may reset. Nobody can see or set another person's PIN.
   - **My HR › Witness PIN** (reference frame): set, change (current PIN or login re-check), forgot PIN / self-reset. It shows the not set, locked and reset-by-admin states. Linked from My eligibility.

## Open it

```
node docs/emar-design/P00/v3/serve.mjs
```

Then open http://127.0.0.1:4360/.

| Try | Link (append to the URL) |
|---|---|
| Time and date picker | `#/frame/sw/today/schedule?open=record:r2:1&outcome=given&tp=clock` · `…&dp=1` |
| PIN co-signing | `#/frame/sw/today/schedule?scenario=restrictedCosigner&open=record:r2:1&outcome=given` |
| Forgotten-PIN fallback (allowed) | add `&fallback=yes` (or use the "Forgotten-PIN fallback" control in the mockup bar) |
| PIN settings and staff status | `#/frame/clinical/settings/secondperson` (editable) · `#/frame/lead/settings/secondperson` (read-only) |
| My witness PIN | `#/mypin/sw?pin=set` · `notset` · `locked` · `adminreset` |
| Catalogue | `#/catalogue/second?only=second` · `#/catalogue/time?only=time` |

## New or changed states (v3)

- Date and time entry: closed field, time popover (clock / type), date popover, future time rejected, invalid hour or minute
- Row click: dose (due, blocked, recorded, not yet due), follow-up, person row
- Witness PIN co-signing:
  - choosing the colleague (searchable, "no PIN set" disabled)
  - PIN typed, PIN missing, success, wrong PIN, locked
  - colleague has no PIN, no eligible colleague
- Controlled-drug witness with a PIN: witness wording, entered at the medicine cupboard
- Forgotten-PIN fallback:
  - fallback used
  - colleague's confirmation item (decided)
  - confirmed later
  - disputed, with the follow-up (decided)
  - expired without an answer, with the follow-up (decided)
  - not allowed by the organisation
- PIN rules in Settings: fixed length, Not configured values, confirm dialog, change history, read-only
- Staff witness PINs: set, not set, locked, reset by an admin; reset disabled until configured
- My witness PIN: not set, set, locked, reset by an admin; set/change validation ("Enter exactly 6 digits.", "The two PINs don't match.")
- My eligibility: witness PIN status with a "Manage my witness PIN" link

## Verification record (29 September 2026)

- All 53 routes (including the PIN settings, PIN page, My Day and handover), 13 scenarios and 4 PIN-page states load with no console errors.
- Flows exercised:
  - row click on due, blocked, recorded and not-yet-due doses, and on a people row
  - time popover: hour → minutes, apply, Type time mode, Escape back to the trigger
  - date popover: pick 27 September, "Use date"; review shows "27 Sep 2026 · 8:45 am NZDT"
  - co-signing: "Choose who is co-signing." → picker search → Daniel Ahn → "Enter their 6-digit PIN." → saved as "Co-signed by Daniel Ahn (witness PIN)"
  - fallback allowed: tickbox → Mere Kahu (no PIN) selectable → "Not verified — PIN forgotten" on Review and on the saved row
  - settings: edit, confirm, history; the staff menu shows reset disabled with its reason
  - PIN page validation and set
- No horizontal page scroll at 200 % zoom on the time and date popovers, the fallback dialog, PIN settings, the PIN page and the catalogue sections. Tab order in the co-sign dialog: tiles → picker → PIN → tickbox → date → time → amount → note → Back / Cancel / Continue.
- 79 screenshots in `screenshots/` (1440, 1280, zoom200).

## Decisions for Stephan

- **Forgotten-PIN safeguards** (proposals): the "Second person not verified — PIN forgotten" marking, and the organisation switches (general, and controlled drugs, part of D8).
- **PIN numbers** stay "Not configured" until someone owns them:
  - attempt limit and lockout length
  - renewal
  - who can reset
  - confirmation time limit and follow-up routing
- Still pending from v2, not treated as decided:
  - the allergy-match rule (mode 1, 2 or 3)
  - the 7 copy fixes for the P0 code
  - the restricted-competency setting value (NF-03)
  - D2 / D4–D9 / D11 / D12, and proposed D13
- Known app-wide difference: the approved time picker shows "09:12 AM" while running text uses the app's formatTime ("9:12 am").

## Approval requested

Please approve **eMAR P00 v3** exactly as identified by the hashes in `VERSION.txt`, or list the changes you want for a v4.
