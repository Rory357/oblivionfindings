# The Oblivion signature: proposed 20 October 2026

Status: **proposal, awaiting Stephan.** This layer goes on top of the approved Slate look. Stephan said the mockups felt "mostly generic, nothing original for Oblivion". Every piece below comes from something only we have:

- the Journey brand (the islands mark, its pathway and the lowercase wordmark);
- the Event Horizon header (its ring and sky).

Once approved, this text moves into DESIGN.md ("The Slate look") and `design_styles/` as rules.

Canvas: <https://claude.ai/artifact/CPsdrsDkwjpYwscYf8jHbY>. See page 2 ("What makes it Oblivion" and "Branding") and every page after it.

## Branding comes first: nothing is hard-coded

Each client sets two logos (one for light backgrounds, one for dark) and one brand colour in Settings › Branding.

- **The logo slot.** The top bar, sign-in, family portal, print and email headers all read one brand slot (`BrandLockup`). No component points at an image path.
  - Default: the Journey mark plus "oblivion care".
  - Client logo: it replaces ours, with a small "Powered by oblivion care".
  - Name only: the name, without our mark.
- **One colour drives everything.**
  - Every brand-coloured surface derives from `--primary`: the fills, link text, tints, the rim colour, the sky, lenses, the focus halo and the horizon line.
  - A very light colour automatically gets a darker shade for text and fills. Settings warns when white text cannot reach 4.5:1.
- **Never follows the brand:**
  - status pairs (done, due soon, overdue, live);
  - safety banners and dose states;
  - calendar sources and map zones;
  - delete buttons;
  - the Slate chrome and the page ground.
  This way a red brand can never hide an overdue dose.
- The islands artwork stays for clients, in their colour, as Stephan decided on 13 October for sign-in.

## The six pieces

1. **The Journey logo in the chrome.**
   - The top bar uses the same lockup as sign-in, so the app and its front door match. The full lockup is at least 180 px wide.
   - The collapsed sidebar uses the small mark, which leaves out the pathway under 32 px, as the brand README says.
   - The islands sit very faintly (5%) in the empty foot of the sidebar.
   - This replaces the ring-O "Oblivion Care" wordmark in the top bar.
2. **The horizon line.**
   - A 1–2 px line of light that is brighter in the middle (rim → brand → rim).
   - Where it goes:
     - the lower edge of the top bar;
     - the top edge of every surface that floats: pop-ups, menus, popovers, toasts and the save bar;
     - the edge where a side panel meets the page;
     - the top of the active tab.
   - It is the "event horizon" the header is named after.
3. **Rings tell the state.** The status dot becomes a ring whose shape carries the tone, so it reads without colour (WCAG 1.4.1):
   - hollow = not started;
   - half = under way;
   - three-quarters = needs you soon;
   - closed with a centre = done;
   - solid with a halo = act now;
   - glowing = live.
   The same ring language covers other states:
   - People: a ring when on shift, a dashed ring when on leave.
   - Percentages: a closing ring.
   - Today: a ring.
   - Focus: the ring's glow.
   - The clock chip: a shift-progress ring once you are on shift.
   - The nav: a "you are here" ring, which sits on the tree line for sub-items.
4. **The pathway.** This is the Journey mark's line with its two round end dots. It is used for:
   - history, timelines, rounds and service levels;
   - steps outside wizards;
   - the wizard's step list;
   - routes on maps.
   Done = filled, now = the ring, next = hollow. It replaces about 51 one-off timeline designs.
5. **Sky surfaces, one per layer.**
   - the page header (unchanged);
   - the wizard's left rail (the steps are the journey; the current step is the ring);
   - the head of a side panel, with connected tabs as on the page header;
   - the sign-in brand half.
   Text on the sky is white; secondary text is at least 80% white.
6. **The islands for big, quiet moments only:** empty pages, arrival/success, not found / errors and first run. They are never used in table rows, cards or small pop-ups.

Also part of the signature:

- **Lenses.** Icons sit in a ring with a little brand light (32, 40, 44, 56 px), not in rounded squares.
- **Toasts and the bulk-action bar** use the slate chrome surface in both modes.
- **Success is the ring closing** in the brand, not a green tick.
- **Motion: "rise over the horizon".**
  - A surface rises 8 px and fades in, then its line draws out from the middle.
  - It takes 200 ms and animates transform and opacity only.
  - Reduced motion turns it off.

## What changes for developers

These pieces are tokens or utilities, not per-page work:

- `--rim`, `--horizon`, `--lens`, `--halo` and the sky ramp in `app.css`, all derived from `--primary`;
- `StatusBadge` draws the ring glyph by tone;
- one `<Pathway>` / `<Trail>` component;
- `Sheet` gets the sky head variant;
- `WizardShell`'s rail becomes the sky;
- `.oc-logo` becomes `BrandLockup` in `app-header.tsx`.

## Conflicts with approved rules (Stephan to confirm)

- APP_SHELL_STYLE_GUIDE §2: the ring-O wordmark moves out of the top bar in favour of the Journey lockup. It stays in the loader and as the header ring.
- BUTTON_STYLE_GUIDE (Slate revision): the primary button keeps its flat fill, but its inner top highlight takes the rim colour.
- Success screens move from the green ok pair to the brand ring. Status chips stay fixed.
