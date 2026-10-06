# eMAR modal sizing audit — 5 October 2026

Scope: the reported oversized date/time group and sizing consistency across
`pages/emar`, `pages/meds`, `components/emar` and `components/meds`. This is a UI
correction in the existing integration checkout, not a clinical release approval.

## Findings and changes

- The reported timestamp used a maintenance-style padded card, repeated its
  purpose in two field labels, and displayed two lines inside each trigger. On
  the local desktop preview the group was 195.89px high and its triggers were
  68.59px high. eMAR now opts into the shared controls' compact presentation:
  one purpose/timezone legend, Date/Time labels, no nested card and 44px triggers.
- The date/time pair now stacks based on available form width. Calendar selection,
  clock/manual entry, exact minutes, accessible purpose labels, hints/errors,
  optional clearing and paired-value semantics are retained.
- All 72 date/time picker usages found in eMAR source opt into that presentation,
  including fields reused by settings and record views. Other modules retain the
  existing default presentation.
- Shared wizard, settings and confirmation shells consistently opt into the
  existing frontline target sizing. The 16 direct dialog content instances use
  the same class; the two loading/error shells that lacked explicit dimensions
  now use the guide's bounded 480px width and vertical scrolling.
- Confirmation dialogs use the guide's pixel-based 480px cap with viewport
  margins and scrollable content. The six remaining discard guards also opt into
  frontline targets. Frontline wizard footer actions can wrap instead of
  squeezing or clipping long action labels.
- Compact calendar popovers use 12px padding; the clock uses 8px to leave room
  for both its 256px dial and the native scrollbar inside a 320px viewport.
  Clock controls, month navigation and picker footer actions retain 44px
  targets; footer groups can wrap.
- The house-settings viewer's footer selector occupied 225.8px at a 320px
  viewport. Small screens now use its existing searchable picker in the body;
  the footer keeps its Close action. Desktop house switching remains available.
- The settings record picker had a fixed 360px minimum and the dose-recording
  picker had a fixed 360px width. Both now fit within the viewport with margins.
- Calendar/time triggers and the clear control explicitly avoid submitting an
  enclosing form. The canonical timestamp and clinical save handlers are unchanged.

## Coverage and verification

The source inventory contains 50 `MedsWizardDialog`, 53 `WizardShell`,
35 `SettingsModal`, 45 `ConfirmDialog`, 16 direct `DialogContent` and eight
`DiscardDraftDialog` usages. These are call sites, including shared and legacy
entry points, not a claim that 207 distinct workflows were exercised in a browser.

Focused checks passed: 4 files / 17 tests, including new compact-field checks for
paired-date preservation, exact-minute entry with Enter, cancellation and clearing
without submitting the parent form. Browser and final suite evidence will be
recorded below after the rebuilt preview is checked. Follow-up checks also passed
for the responsive house selector and confirmation focus: 3 files / 17 tests.

The first broad run passed 535 files / 3,626 tests and found one house-viewer test
that assumed a footer selector at every viewport. That test now explicitly checks
the desktop variant, with a separate phone test verifying house switching and
the corresponding house-specific rules. Both pass in the focused rerun.

## Final browser evidence

The rebuilt preview at `http://127.0.0.1:8765` loaded
`app-BSfYpu4V.js`. Browser checks used the existing synthetic preview account;
no medication record or setting was saved.

- Desktop (1726 × 995 CSS px): the report's date/time group measures 146.5px
  instead of 195.89px, and both triggers measure 44px. The extra card is removed.
- Phone (320 × 800 CSS px): date and time stack into 238.8px-wide controls.
  The report body and footer have no horizontal overflow. Both timestamp
  popovers measure 288px wide, with equal client/scroll widths; the clock no
  longer has the sideways scroll observed in the first visual pass.
- Clock and calendar month-navigation buttons measure 44px. Manual entry applied
  exactly 08:17 while retaining 5 October; Escape closed the calendar, retained
  the pair and returned focus to the date trigger. The temporary draft was
  explicitly discarded.
- Phone house viewer: its footer is now 69.3px high instead of 225.8px. The
  searchable dropdown is 288px wide, remains within the screen and has 44px
  options. Searching for and choosing Kauri House updated the read-only viewer.
- At 720 × 720 CSS px, the house viewer, body and footer also fit without
  horizontal overflow. The standard dose-prerequisite modal and discard prompt
  were checked at 320px; both fit within their viewport margins.

Saved local screenshots: `emar-compact-timestamp-desktop-final-20261005.jpg`,
`emar-compact-timestamp-phone-final-20261005.jpg` and
`emar-house-modal-phone-final-20261005.jpg` in this chat's eMAR evidence folder.

TypeScript, scoped lint and changed-file formatting passed. The final preview
build passed in 3m47s. The final complete frontend suite passed **536 files /
3,628 tests** in 370.45 seconds. Existing clinical recovery and deployment gates
are unchanged.
