# eMAR desktop modal controls audit — 5 October 2026

## Scope

The user reported circular On/Off controls in the on-call contact and alert-recipient dialogs and requested a review of all eMAR modals. **The user explicitly requires desktop web only. Phone work is outside this task.**

Work stays in the existing integration branch and draft PR #16. The browser preview uses synthetic local data. This is a UI repair pass, not a production or clinical release sign-off.

## Findings and repairs

1. The earlier `.frontline-dialog` rule applied 44px minimum width and height to every button. Radix switches and checkboxes are buttons: the rule distorted their compact marks. Exclude those primitives and existing `frontline-hit` controls from drawn-size enlargement. Keep the expanded interaction area and enough row spacing separately. The reported switches measured 44×44 before the repair and 31.5×17.5 at the normal 14px text setting afterwards; checkbox marks measure 14×14.
2. On-call editing, adding an alert recipient, creating rounds and reviewing settings changes inherited the 480px detail width. They now use the standard 720px form width.
3. Most eMAR wizards inherited the shared 980px width; the older recording wizard used 1040px. Medication wizards now use Rory's 1100px token with a viewport bound. Other modules keep their existing default. Existing explicit heights remain intact.
4. Review cards used fixed side-by-side label/value rows even when a desktop wizard split them into narrow columns. The rows now stack when their own card is narrow. Review headings preserve icon size and space for Edit. On-call preview values inherit the row alignment.
5. Shared SettingsModal and ConfirmDialog footers now allow long actions to wrap. Stock movement/contact-method choices allow their text to wrap inside the existing grid.
6. Follow-up switches now spell out On/Off. Settings switch rows preserve room for their control and state text. Dark eMAR modal switch handles use the foreground token so the inactive handle remains distinguishable from its track.
7. Closing an edited on-call contact could silently lose its unsaved choices. It now uses the existing discard confirmation, keeps choices when editing continues, and prevents closing while save is in flight. No save payload, authority rule or clinical behavior changes.

## Source coverage

The [call-site inventory](modal-inventory-2026-10-05.csv) records **208 calls** after adding the on-call discard guard: 35 SettingsModal, 53 WizardShell, 50 MedsWizardDialog, 16 direct DialogContent, 45 ConfirmDialog and 9 DiscardDraftDialog. This includes wrappers and retained legacy paths; it is not a claim of 208 unique user workflows or 208 browser-tested dialogs.

Reviewed coverage includes shared sizing/touch selectors, switch and checkbox usages, width overrides, review cards, footer layout and date/time presentation. Direct dialog calls use their local `frontline-dialog` class; wrapper calls inherit it. Data-dependent clinical states remain covered by existing tests rather than fabricated browser records.

## Verification

- Frontend regression suite: **537 files / 3,633 tests passed** (389.91 seconds). The new on-call keep/discard behavior is included.
- Following the final wizard-width edit, the focused settings and shell rerun passed **5 files / 30 tests**. These overlap the full suite and must not be added to its total.
- Final formatting and changed-source lint passed.
- Final TypeScript check passed. The final contrast-only CSS adjustment does not change application types or behavior.
- Final Vite build passed in **3m55s**, with the existing chunk-size advisory. The browser confirmed **`app-CAVaVD3s.js`** and the dark switch handles' foreground colour before the final on-call and alert screenshots were saved.

### Desktop browser checks

- On-call contact: 720px form, correct On/Off pill shape, keyboard toggling, fixed/roster mode, conditional team-lead control, preserved choices after Keep editing, explicit discard, and readable three-night roster preview.
- Alert recipients: 1100px wizard, 248px rail, fixed header/footer, 44px Continue/Cancel controls, On/Off states, Continue/Back preserving groups, and discard protection. Review cards in 397px desktop columns stack their label/value rows; the 808px full-width card retains its horizontal rows. Card content fits without horizontal overflow.
- Report export: 14px checkbox mark with a 44px interaction region; keyboard toggling and Back preserve the selected state. No file was generated.
- Stock receipt: medication picker, Quantity & batch step, compact 44px expiry field, reachable footer, and existing discard guard. No stock was posted.
- Create rounds: 720px form, 44px date field, authoritative no-active-template result, and disabled Create action. No rounds were created.
- Meds today: the current synthetic account is not clocked in. The recording-blocker modal explains this and offers the correct Clock in destination. The audit does not bypass that gate or claim a fresh full recording walkthrough under this account.
- Both light and dark desktop appearances were inspected. The normal System appearance and viewport are restored afterwards. Existing user tabs and their drafts are left untouched.

Evidence files in the local `emar-resume-20261004` proof directory:
`emar-oncall-switches-desktop-20261005.png`,
`emar-alert-switches-desktop-20261005.png`, and
`emar-review-cards-desktop-20261005.png`.

Browser checking uses desktop windows and representative modal families; it does not certify every conditional clinical or permission state. No medicine, stock, alert-recipient or on-call change is saved during these visual checks.
