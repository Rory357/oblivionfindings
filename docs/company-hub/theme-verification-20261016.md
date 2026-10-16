# Final appearance verification — 16 October 2026

**Completed:** final build 85596 exited 0 in 5m57s and the browser served app-BpnVj4vd.js on the intended Herd host. The final browsing option computes to oklch(0.7 0.262768 289.545) in Dark and oklch(0.46 0.262768 289.545) in Light. Both actual screenshots passed inspection. No console errors or horizontal overflow in the checked surfaces. Light was restored through settings and remained selected after a fresh reload; temporary tab26 closed, original tabs1/5/19 untouched. No Hub content, schema, permissions, commit, push or remote deployment changed. No task build worker remains.

The progress notes below are historical; all named pending build/appearance steps are superseded by this completed result. Final evidence: theme-browse-menu-dark-passed-20261016.png, theme-browse-menu-light-passed-20261016.png, theme-restored-light-20261016.png, and theme-shoutouts-dark-final-20261016.png / theme-shoutouts-light-final-20261016.png under evidence/. Earlier guide/editor paired screenshots verify the same delivered CSS; the final follow-up changed only dropdown text classes.

The user said “Please continue” in direct response to the request to temporarily switch themes for the final comparison and restore the original preference. This supplies authorization for that specific check. The previous unanswered-popup gate is superseded.

## Current source correction

The actual dark browser exposed raw brand-purple text on dark Hub cards. The application already defines `--primary-text` to clamp brand text darker in Light and lift it in Dark. Hub custom CSS bypassed that token.

Changed only exact `color: var(--primary)` declarations to `color: var(--primary-text)` in recognition.css (12), designer.css (34), and hub.css (13). Backgrounds, borders, authored colours, permissions and interactions remain unchanged. This covers recognition labels/reactions, guide links, selected designer controls, metadata and shared Hub accents. Prettier check passed for all three files. No unit tests added for a token-only CSS change; actual built browser verification is the relevant check.

## Verification in progress

- Original theme confirmed as **Light** in the visible Appearance settings before changing it.
- Temporary tab 26 is the only browser tab used. Original user tabs 1, 5 and 19 remain untouched; no page/editor content saved.
- Dark applied through the real Appearance UI. Reloaded Hub served app-D_Z4v7Au.js on the intended Herd host; public/hot absent. No runtime errors or horizontal overflow in the checked feed.
- Dark Hub main/portal and Overview main both compute to rgb(28,29,38), the approved #1c1d26 canvas. Screenshot: evidence/theme-overview-dark-20261016.png.
- Text correction build 78883 finished with actual exit 0 in 12m 5s, log storage/logs/company-hub-theme-text-build-20261016.log. Fresh browser served app-C-Fd_n3N.js. Dark guide active contents, recognition impact label and selected editor Settings now compute to oklch(0.7 0.262768 289.545), instead of raw rgb(109,36,236). Light selected editor text computes to oklch(0.46 0.262768 289.545). Guide, feed and editor screenshots captured in both appearances; no console errors or horizontal overflow in the checked screens.
- Opening a dropdown exposed a second real contrast issue: the shared primitive's focus:text-accent-foreground inherits the runtime raw brand. HubSelect now supplies focus:text-primary to use the established mode-aware text token. Existing sizing, focus, values and selection behavior are unchanged; scoped formatter and lint passed. Build 97014 ended with actual exit 1 after 7m20s: concurrently edited ChatCreateConversation imported readPendingChatCreation before its API export existed. Current source now contains the export (api.ts updated 09:48:08); no Chat files were changed by this task. Retry build 49134 is live, log storage/logs/company-hub-theme-dropdown-retry-20261016.log. Do not restart merely because quiet.
- Light restored through the real settings UI while the dropdown build runs. A final temporary Dark dropdown check and restoration to Light remain.
- Reference fidelity clarified: approved-mockup/company-hub-designer.html:832 and interaction-mockups/frame.html:9 explicitly specify #eeeff3 Light / #1c1d26 Dark. Current Hub computed colours match those exact approved references. The current live Overview Light main instead computes to oklch(0.905 0.003 277); the live dark canvas matches. No unrelated Overview change was made, and the user-approved mockup colour was retained rather than silently changed to follow another page's newer global colour.

## Full-goal evidence boundary

The exact 22 approved completion items remain mapped in completion-evidence-addendum-20261016.md; all 37 interaction families remain mapped in final-staff-evidence-20261015.md, final-community-evidence-20261015.md and final-authoring-evidence-20261015.md. This change does not reduce that scope.

Current-source follow-up inspected HubAccess, HubPageCopy, and the birthday/privacy/explicit-denial tests. Existing terminal logs were reread for recognition receipts (7/254), birthday/resource review (9/164), document repair (10/132), moderation (12/134), publishing/review (70/529), restore retention (13/126), and event documents (17/410). These remain distinct existing evidence, not newly executed or summable test totals. The approved social polish has separate 84-case focused evidence and built browser checks in social-polish-20261016.md.

## Dropdown renderer follow-up

- Retry 49134 completed actual exit 0 in 6m5s, app-Cx-f_dY6.js. The event Category menu now computes highlighted Company to oklch(0.7 0.262768 289.545); screenshot evidence/theme-event-menu-dark-20261016.png. No field values were changed and no event created.
- The browsing-filter recheck correctly failed the same contrast requirement because HubBrowseSelect is separate from HubSelect. An exhaustive current Hub TSX SelectItem inventory found four renderers: _select.tsx, _browse-select.tsx, editor.tsx's local selector, and _shortcuts.tsx. All four now apply focus:text-primary. Source sizes/values/handlers remain unchanged. Formatting and lint passed for all additional files.
- Build 85596 is now terminal and the completed inventory is verified as recorded above. The earlier theme-filter-dark-final screenshot is explicitly a failed recheck, not completion evidence. Existing font-reference and large-bundle build advisories remain; the final build succeeded. Formatter/lint and scoped whitespace checks passed. This appearance-only correction did not need new behavior-mirroring unit tests.
