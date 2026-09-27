# Boundary visibility correction — 27 September 2026

The user's map contained 127 saved synthetic QA boundaries, including BG-130, a 220 m circle created and then renamed through the actual four-step builder. The earlier successful save/history checks did not catch a poor default map presentation.

## Causes and corrections

1. Unselected areas used 1px grey outlines at 40% opacity and only 1.5% fill. These nearly disappeared over the greyscale tiles. All available areas now use solid 2px brand-coloured outlines, with a darker brand-derived stroke appropriate to the light basemap in both app themes. Selected/linked areas have stronger outlines and fills; retired areas retain dashed styling.
2. Every automatic, focus-triggered or manual refresh emptied the map arrays and totals before requests returned. The same-scope refresh now retains the last permitted snapshot until its replacement arrives, labelled as refreshing. Changing scope clears immediately. Failed refreshes clear geometry, resources, selection and observation time rather than displaying data after a denial.
3. The map ignored the boundary selection already present in its URL. The canonical permitted selection now opens highlighted and centred, with its inspector. A conflicting Site filter or excluded retired area prevents that initial pin. An explicit resource link takes precedence.

Stale resource relationships now say “Last recorded inside/outside” rather than showing an unqualified current relationship.

## Tests

Ten focused frontend checks passed in `boundary-visibility-tests.log`, including three new behavioural regression cases. They verify visible geometry during a pending refresh, replacement data after success, immediate clearing on a Site change, clearing after denial and permitted deep-link selection. Geometry, clustering and policy checks also remain green. The Leaflet rendering itself is verified separately in the browser, rather than inferred from the component mock used for request-lifecycle tests.

The historical limited TypeScript helper reported no implementation diagnostics (`boundary-visibility-types.log`), but omitted tests and did not use the full repository compilation context. Its graph diagnostics do not establish inherited repository failures. See `VERIFICATION.md` for Main's T07-01 finding and the authoritative repository check. No backend schema, fixture or production data change was required for this fix.

## Browser result

The Vite production build passed in 5m 57s (`boundary-visibility-build.log`). Reloaded the actual application on port 4426 with `tab=map&selected=130`: BG-130 automatically opened highlighted with its inspector. Inspected 127 rendered boundary paths; unselected outlines resolve to `oklch(0.48 0.2 277)`, 2px, full opacity, with 7% fill. Captured the selected area and the fit-all overview in `boundary-visibility-selected.png` and `boundary-visibility-all.png`.

During a real manual refresh, all 127 paths remained rendered while the UI said “Refreshing permitted map data…”. The selected path's geometry in screen coordinates was identical before and after refresh, confirming that the map no longer jumps back to fit-all. Final browser error logs were empty. The browser remains on the selected saved area.
