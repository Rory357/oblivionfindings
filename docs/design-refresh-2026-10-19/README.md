# Colour and look-and-feel refresh — 19 October 2026

Status: **D · Slate chosen and written into Rory's rules (Stephan,
2026-10-20)**: Slate neutrals, a `#1E293B` nav and top bar in both modes,
and flatter buttons. The rules are in:
- DESIGN.md, "The Slate look";
- DESIGN_TOKENS.md, "Slate palette";
- the APP_SHELL, BUTTON and POPUP guide amendments.

Nothing in `app.css` or the shared components has changed yet. The token change is the next step.

**Second round, 20 Oct.** Stephan said the mockups were "mostly generic, nothing original for Oblivion", that items were still missing, and that logos and colours must never be hard-coded. In response:
- the Oblivion signature was proposed, layered on Slate: [SIGNATURE.md](SIGNATURE.md), canvas page 2;
- a Branding board shows one colour and the logo slot retinting the whole app;
- the real top bar and sidebar are now drawn;
- the real IT ticket drawer and ticket page are drawn;
- seven new canvas pages cover the 23 kinds of screen the first round missed (pages 9–15).

The signature is **not** in Rory's rules yet; it waits for Stephan.

**Start here (20 Oct, round 5):** the viewer now opens on **Start here**: what is decided, the open decisions (also in [DECISIONS.md](DECISIONS.md) — 27, each with a recommendation), and a **Coverage** board that maps each of Stephan's asks to what exists and what is still missing. The menu is grouped: Start · The look · The pop-up kit · Every pop-up, by module · Screens and parts · Archive (the A–D comparison, first templates and first catalogue, kept for reference).

This folder holds the decision material, the full UI inventory, the good catches, and the plan for writing the outcome into Rory's rules (`DESIGN.md` + `design_styles/`).

Latest boards (all 18 pages, light and dark, including Chat, Company hub, Referrals and the chart system): **<https://claude.ai/artifact/S2FWVebvqtxrVL5LHahzHy>** (a viewer page built from the canvas files).

Original design canvas (now on Rory's account, frozen at Version 99 because this session can no longer publish to it): <https://claude.ai/artifact/CPsdrsDkwjpYwscYf8jHbY>
("Oblivion colours & look"). Version 96 has 150 boards on 18 pages, light and dark:

1. Start here: swatches and contents.
2. What makes it Oblivion: the six signature pieces, and Branding (one colour plus the logo slot, for four clients).
3. Colour directions: Today vs A, B, C and D. D · Slate was chosen.
4. Pop-up templates:
   - the wizard (step, review, error, success, discard, narrow);
   - form, confirm, delete and reason pop-ups;
   - file preview, detail, settings and side panels;
   - pickers, menus, Ctrl K, notifications and the user menu.
5. Date & time: calendar date, range with "Not known yet", clock and typed time, errors and review.
6. Controls & small parts:
   - every button and field state, choices, the tile picker, uploads, status and the loader;
   - tooltips, popovers, accordions, avatars, progress and the bulk-action bar.
7. Cards, lists & feedback: entity cards, tables, pagination, empty / loading / error, messages and toasts.
8. Page layouts: record profile, settings page, Site Calendar and sign-in.
9. IT & support: the real 576 px ticket drawer, the ticket page with its conversation, the drawer's states and the ticket pop-ups.
10. Dashboards & charts: the Overview block workspace, one chart style and the specialist widgets.
11. Maps, rosters & calendars: control-room map, geofences, roster week grid, the respite planner and every calendar view.
12. Medication & frontline: the MAR grid, rounds, the witness check, My Day and drag-and-drop boards.
13. Messages, activity & access: messages, notifications, audit, timelines, the permissions matrix and the settings menu (Branding).
14. Errors, states & outside: error pages, app-wide banners, the family portal, careers, incident status and sign-in screens.
15. Builders, hub, print & email: the editors, the company hub, PDFs that print in black and white, and one email template.
16 and 17. Every pop-up: all 1,539, one column per area, light and dark.

**Third round, 20 Oct: one pop-up kit.** Stephan asked for "re-usable things instead of having so many different" pop-ups. The viewer now has two new pages:
- **4 · The pop-up kit:** the 8 types, the 21 building blocks, and the rules. It also shows where all 1,539 pop-ups land.
- **17 · Pop-ups · Clients:** the 58 live Clients pop-ups, drawn from their real code as 51 drawings on the kit, with the merges listed.
- **18 · Pop-ups · Sites & operations:** the 221 live pop-ups as 192 drawings (Sites, rooms, calendar, meal planner, vendors, shifts, handovers, respite, catering, control room, location, checklists, work plans).
- **19 · Pop-ups · Medication & clinical:** 233 rows holding 292 separate screens, drawn as 248 (recording pop-ups at frontline 44 px; merges keep every safety step).
- **20 · Pop-ups · HR & rostering:** 229 rows holding 258 screens, drawn as 234 (clock and timesheet pop-ups at frontline 44 px; merges keep every privacy and employment-law step).
- **21 · Pop-ups · Finance & fleet:** 288 rows holding 327 screens, drawn as 276 (the vehicle workspace's copies merged with the Fleet pages'; drivers' pop-ups at frontline 44 px).
- **22 · Pop-ups · Governance, H&S & hub:** 219 rows holding 299 screens, drawn as 276 (H&S reporting at frontline 44 px; every WorkSafe, Privacy Act, restraint and board-governance step kept).
- **23 · Pop-ups · IT & support:** 96 pop-ups holding 117 screens, drawn as 103 (the ticket quick peek is the kit side panel; staff self-service at frontline 44 px).
- **24 · Pop-ups · Shell, settings & devices:** 194 rows holding 198 screens, drawn as 175 (one Ctrl K palette; My Day and end shift at frontline 44 px).

**All eight modules are drawn:** 1,772 separate screens → 1,555 drawings on 8 types and 21 blocks (totals on the kit's rules board).

The other modules follow the same method. See [POPUP-SYSTEM.md](POPUP-SYSTEM.md) for the plan and the progress table, and [popup-kit/](popup-kit/) for the drawing source.

**Pages (20 Oct):** the same method for pages — the **page kit** (13 templates, 6 layouts, 16 blocks, 8 rules; menu group "The page kit") and **Clients** drawn on it (17 pages and every profile tab → 52 drawings, 66 things found; menu group "Every page, by module"). 721 routed pages in all; 7 modules to go. See [PAGE-SYSTEM.md](PAGE-SYSTEM.md) and [page-kit/](page-kit/).

Companion files: [GOOD-CATCHES.md](GOOD-CATCHES.md) (everything that must
not be missed, including bugs found), [RULES-CHECKLIST.md](RULES-CHECKLIST.md)
(every Rory rule → what to draw), [POPUP-SYSTEM.md](POPUP-SYSTEM.md) (the
shared templates), and `inventory/` (every pop-up, file:line).

## Why the app feels grey today

Measured from `resources/css/app.css` (oklch converted to hex):

| Token | Light | Dark | Problem |
|---|---|---|---|
| `--background` (page ground) | `#DFDFE2` | `#030306` | Light: a flat, cold mid-grey with no brand warmth. Dark: almost pure black. |
| `--card` | `#FFFFFF` | `#070811` | Dark: card vs ground contrast is **1.03:1**, so cards don't separate. |
| `--border` | `#D3D4D8` | `#181A23` | Dark borders are nearly invisible. |
| `--primary` | `#4F39F6` (hue 277, indigo) | `#747CFF` | Not the brand. The Journey brand purple is `#7040D6` (hue 292); sign-in already uses it, the app does not. |
| `--sidebar` (ink chrome) | `#0A0C13` | `#0B0D15` | Blue-black; the brand near-black is plum `#141019`. |

So the app has three different "purples" and two different "blacks" between
the sign-in pages, the shell and the brand files. The grey ground then
drains the colour out of everything sitting on it.

The Event Horizon header ("hero banner") is liked and **keeps its anatomy in
every direction**. Its sky is already derived from `--primary`, so it simply
picks up the brand purple.

## The four boards on the canvas

| | Ground (light) | Chrome | Brand | Dark mode |
|---|---|---|---|---|
| **Today** | `#DFDFE2` grey | ink `#0A0C13` | `#4F39F6` indigo | `#030306` near-black |
| **A · Lavender mist** (recommended) | `#F3F1F8` soft lavender | plum ink `#141019` | `#7040D6` Journey | plum graphite `#16131C` / cards `#1F1B27` |
| **B · Bright white** | `#F7F7FA` near-white | **white** sidebar + top bar, lavender selected | `#7040D6` | neutral graphite `#121216` / `#1B1B21` |
| **C · Warm paper** | `#F4F2EE` warm off-white | warm ink `#1C1917` | `#7040D6` | warm charcoal `#171513` / `#211E1B` |

All four keep the app's verified status colour pairs (success / warning /
critical / info) — safety colours stay brand-independent (DESIGN.md
non-negotiable #6).

### Contrast check (WCAG 2.1 AA needs 4.5:1 for body text)

| Pair | Today L | Today D | A L | A D | B L | B D | C L | C D |
|---|---|---|---|---|---|---|---|---|
| Text on ground | 14.8 | 17.9 | 16.8 | 16.3 | 17.0 | 16.7 | 16.0 | 16.2 |
| Muted text on ground | 7.8 | 6.2 | 6.5 | 7.6 | 6.2 | 7.5 | 6.3 | 7.6 |
| Muted text on card | 10.4 | 6.0 | 7.2 | 7.0 | 6.7 | 6.9 | 7.0 | 6.9 |
| Brand text on card | 6.5 | 8.4 | 7.3 | 8.3 | 7.3 | 8.5 | 7.3 | 8.2 |
| Brand text on brand tint | 5.4 | 6.8 | 6.3 | 7.4 | 6.3 | 7.3 | 6.3 | 7.4 |
| Sidebar text on chrome | 8.1 | 7.2 | 9.0 | 7.4 | 8.8 | 7.2 | 9.0 | 7.5 |
| White on brand button | — | — | 7.3 | — | 7.3 | — | 7.3 | — |

Every new pair passes AA. Cards lift by border + soft shadow rather than by a
dark ground (the reason the ground was darkened in September).

### Why A is recommended

- It is the brand: the ground, chrome and purple are the Journey palette
  (`#7040D6`, `#141019`, `#D6B9FF`), so the app finally matches sign-in.
- It removes the grey without going pure white (white was rejected in
  September because cards stopped lifting — A keeps a visible ground).
- It keeps the approved dark "ink" chrome and the Event Horizon header, so the
  shell structure doesn't change — only colours.
- Its dark mode is the lifted graphite approach Stephan already approved as a
  candidate on the role Overview, tinted to the brand.

B is the biggest change (it replaces the approved ink chrome with a white
sidebar and top bar). C is the softest but makes the purple the only cool
colour, which can look muddy next to warm greys.

## Rules this decision would change in DESIGN.md

These are the existing rules that conflict with A/B/C. They get rewritten,
with the date and "corrected 2026-10-19 (Stephan)", once a direction is chosen:

1. Named anti-pattern **"Violet-tinted or pure-white page grounds"** — A
   deliberately tints the ground (lightly); B is near-white.
2. `APP_SHELL_STYLE_GUIDE.md` §4 "neutral mid-light grey ground".
3. `APP_SHELL_STYLE_GUIDE.md` §1–3 ink chrome colours (B replaces the ink
   chrome entirely; A and C re-tint it).
4. `DESIGN_TOKENS.md` — default `--primary` moves from `purple-600`
   (`#4F39F6`) to the Journey purple `#7040D6`; the `--color-purple-*` ramp is
   re-centred on hue 292.
5. DESIGN.md "Role overview pilot" — the graphite dark palette becomes the
   app-wide dark mode (Stephan's call, recorded 14 Oct).
6. Memory rule "page by page, no app-wide" (14 Oct) — this is the app-wide
   step that rule deferred to Stephan.

## Next steps (after the direction is chosen)

1. Pages and layouts: list and draw every page on a page kit (decisions N1 and N2 —
   about 880 page files, about 20 drawn so far).
2. Build the mockups item by item in the chosen palette (same canvas, one
   section per family: shell, headers, cards, lists, modals, sheets, forms,
   feedback, navigation, charts, calendars, maps).
3. Stephan approves the mockups.
4. Write the approved look into Rory's rules: `DESIGN.md`,
   `design_styles/DESIGN_TOKENS.md`, `APP_SHELL_STYLE_GUIDE.md`,
   `POPUP_STYLE_GUIDE.md`, `LIST_STYLE_GUIDE.md`, plus ESLint guardrails where
   mechanical.
5. Only then change `app.css` tokens (one PR, tokens only), and verify every
   page family at 1440 px in light and dark.
