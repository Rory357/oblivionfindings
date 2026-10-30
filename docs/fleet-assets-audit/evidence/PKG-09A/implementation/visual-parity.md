# Overview visual alignment — 27 September 2026

Follow-up to the user's comparison with the approved v9 mockup. The prior functional checks did not establish visual parity. This follow-up changes only the Overview component and its scoped stylesheet.

## Aligned presentation

- Restored the car mark, Overview chip, concise subline, record search, refresh and View fleet action in the shared page header. Saved views now open from the filter row; Data status has one entry beside the observation time.
- Moved saved-view management into a dialog without removing account persistence, import, rename, replacement or Undo. Applying a saved view closes the dialog.
- Made the grayscale map flush with the card edges. Search, resource type, grouping, stale locations, list, reset and fullscreen now share the toolbar in the approved order. Cluster colour follows the primary theme token; location-summary icons match the legend.
- Matched card radii, subtle shadows, work/availability column proportions, donut spacing and legend density. The donut retains the approved 210px chart, 74/94px radii, rounded segments and actual data. Coming up retains two summary rows with compact text alignment.
- Constrained the attention table's container so it can scroll internally on a narrow screen, and retained the full-width map there.

## Verification

11 existing frontend tests passed. TypeScript, targeted ESLint, Prettier and whitespace checks passed. The final Vite production build passed in 5m 27s with the existing bundle-size warnings. The generated stylesheet was checked for the last narrow-screen corrections. No backend code, permissions, source projections or migrations change in this follow-up.

Browser checks covered the saved-view dialog, opening/cancelling its form, applying a stored view, map fullscreen and Escape, keyboard asset context actions and quick details, View fleet navigation with return to the Overview, and donut-to-availability filtering. No browser console errors were captured. Document widths equalled scroll widths at requested widths of 1425, 1024 and 390px (1410, 1009 and 375px content widths). The map has zero inset padding and the tile filter remains `grayscale(1) saturate(0) contrast(0.88) brightness(1.04)`.

Screenshots: [before in light mode](parity-before-light.png), [aligned light mode](parity-after-light.png), [aligned dark mode](parity-after-dark.png), [1024px](parity-1024.png), [390px](parity-mobile.png).

Reference: [approved v9](parity-reference-v9.png). Comparison captures use the same 1425px requested browser width (1410px content with the scrollbar). The application retains its real navigation and additional Availability view. Record totals, chart proportions, dates, map extent and receipt state follow the scoped source data; no mockup values are substituted.

The earlier repository-wide GitHub tests were already failing on the preceding main commits `f7d517359`, `4ea64c547` and `fa7b52919`. The prior publication's failures include other module and architecture checks; this visual follow-up does not establish a green repository-wide suite.
