# Generic → Oblivion: the audit (21 October 2026)

Status: **approved by Stephan on 21 Oct (“P4 yes”)** and applied to every page drawing (viewer Version 18) and the pop-up tops. Nothing in the app code has changed.

Stephan, 21 Oct, on the client profile's Onboarding tab: *"it is starting to look good now can you please audit everything again and let me know for example this looks generic and very ai built is there a better unique way to do this"*.

Viewer: <https://claude.ai/artifact/S2FWVebvqtxrVL5LHahzHy> › The look › **Audit: generic → Oblivion** (3 boards, light and dark). Each pair shows today's kit block and the proposed one with the same data.

## What was counted (all 295 page drawings)

| Generic tell | Count |
|---|---|
| Stat tiles with a coloured side border | 220 tiles in 60 drawings; 39 drawings repeat a number the header already shows; 14 tiles show a zero; 17 show progress as a percentage; some show a person |
| An icon at the start of a list row | 459 of 531 rows |
| Template card grids (icon or initials, name, grey line, ⋮, pills, "Updated") | 50 drawings |
| "↗" in a header box | 1,099 — every box |
| Tinted info boxes | 64 drawings, plus pop-ups |
| Pop-up tops with three purple-tinted things before the first field (lens, record box, info box) | most pop-ups with a record context |

The status badges, lenses and horizon line are already the Oblivion signature (L1) on every board, so the generic look comes from these blocks.

## The Oblivion replacements (now in the page kit as blocks)

- **`tally`** — numbers that belong together on one line, separated by hairlines; each label carries its state ring; a zero folds into a sentence ("Nobody needs attention").
- **`journey`** — steps as stations on the brand line with its two round end dots (the Journey mark laid flat). Done fills the line; the caption names the person who coordinated it.
- **`ring`** — progress closes the Oblivion ring; complete is the ring closed with a centre. Optional checklist read by its rings (no badge per row).
- **`log`** — time first, the state ring, then the words; no decorative icon.
- **`people`** — the presence ring on the avatar (on shift / away) and today's rounds as a short Journey line (done, now, missed, next).
- **`places`** — a house as its beds (rings, filled = occupied), who is on shift now, and the one thing that needs doing.
- **`quiet`** — a small empty is one line with the action in it; the islands stay for whole empty pages.
- **`point`** — a chart with a point: values written on, the latest in the brand, the rest quiet, one sentence on what changed.
- **calm header numbers** (`hero.calm`) — no arrow in every box; a percentage closes a small ring.
- **pop-up tops** — one brand mark (the lens), the record as a plain line between hairlines, who-can-see in the sub-line.

Styles: `page-kit/oc-sg.css`. Renderer: `page-kit/page-kit.mjs`. Board: `page-kit/build-audit.mjs`.

## The ten rules (proposed)

1. A number appears once on a page — in the header or in the body, never both.
2. No tile per number. Numbers that belong together share one line; a zero is a sentence.
3. People are never numbers. Name them where the work is, with their presence ring.
4. Progress closes the ring; steps are the Journey line; every chart makes one point.
5. No coloured side borders. A surface that matters carries the horizon line on its top edge.
6. Icons tell kinds apart; rings tell state. No icon on a row just because there is room.
7. A card tells its thing's own story — a person's day, a house's beds — not a template of icon, title, pills.
8. Small empties are one quiet line. The islands are for whole empty pages, first run and success.
9. A box only for a rule that changes what you can do now (a safety stop, a legal step). Who-can-see goes in the sub-line.
10. The brand colour means you, now, act. Structure stays slate; not every icon and chip is purple.

## If approved

- The 295 page drawings move across mechanically (tiles → tally / ring / journey by what the number is; event lists → log rows; people and house cards → person and place cards; small empties → quiet; header boxes calm), then the remaining six page modules are drawn with the new blocks from the start.
- The pop-up kit thins its info boxes and per-row icons by rules 6, 9 and 10.
- These rules go into DESIGN.md's anti-pattern list with lint checks where they can be checked (coloured side borders, `border-l-4` accents).
- In code: one `Tally`, `Journey`, `ProgressRing` and `LogRow` component each.
