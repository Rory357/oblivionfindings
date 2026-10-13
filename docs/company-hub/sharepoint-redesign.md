# Company Hub intranet design — 12 October 2026

The user chose a modern SharePoint company intranet layout with Oblivion colours and styling. Desktop only. This replaces the earlier magazine direction.

Reference: [Microsoft's communication site templates](https://support.microsoft.com/en-us/sharepoint/sites-in-sharepoint/use-the-sharepoint-standard-communication-showcase-and-blank-communication-site-templates) and [Hero web part](https://support.microsoft.com/en-us/sharepoint/pages-in-sharepoint/use-the-hero-web-part).

## Changed experience

- Compact company identity and scoped search, then persistent horizontal navigation and a quiet command bar.
- Home-only feature tiles: one lead story plus two supporting stories or managed useful links. The live empty homepage has a replaceable neutral workplace cover and useful destination tiles.
- Theme-aware content surface within the existing app shell. News, quick links, recognition, people and events remain customisable sections. The current staff session uses dark mode.
- Resources use a document-style list. Saved, search and other inner views have focused headings; empty Saved explains how to save items instead of claiming no published news exists.
- Personal shortcuts/notification preferences and HR birthday controls are grouped under My hub. Existing capability checks remain in force.
- The editor keeps its existing save/publish/version mechanisms and moves page properties to the right of the editing canvas.

## Cover asset

Saved path: `C:/Users/steph/Herd/oblivionfindings/public/images/company-hub/workplace-welcome.png`.

Generated with the **built-in image generation tool**, not the fallback CLI. This is a generic fictional interior used as a decorative cover, with no claim that it is the company's actual premises. No fictional staff, news or HR records were added to the live hub.

Final prompt:

> Photorealistic editorial interior photograph for the default cover of a modern company intranet. Landscape 16:9 composition. A calm, welcoming contemporary shared workplace in New Zealand: pale timber communal table, a few comfortable muted sage chairs, one ceramic coffee cup and closed notebook, natural plants, floor-to-ceiling glazing with soft green garden beyond. No people, no logos, no signage, no lettering, no screens or readable documents. Natural morning daylight, subtle realistic texture, premium architectural photography, restrained colours, elegant and warm rather than corporate stock-photo stiffness. Compose with some darker uncluttered space toward the lower left for website text to be overlaid later. This is a generic fictional workplace interior, not a depiction of any real company's premises. No text rendered into the image.

## Verification

- Focused frontend checks: six passed, including home tile links, Saved without the home hero, active navigation, scoped search and existing poll behavior.
- TypeScript and scoped lint passed. Production build passed in 8m 11s with the existing large-chunk advisory. `git diff --check` passed.
- Current output verified in the manifest and built files: `index-DOgORif2.js`, `editor-CoMWayfO.js`, `_hero-7GmG6ND6.js`, `hub-CBnUPgBl.css`. The output contains the new Saved heading, site header, cover path and editor layout. `public/hot` is absent.
- Browser-verified on `https://oblivionfindings.test` as the already signed-in Support Worker 1, with their dark theme and permissions preserved. Checked Home, Saved, News search and clearing it by navigating to Resources, Events, active navigation and the My hub menu. The staff menu contains shortcuts and notification preferences; no HR birthday management or editor actions are exposed.
- The default cover loaded successfully. At the current 1115px desktop viewport, document scroll width was 1100px: no horizontal overflow. No browser console errors were recorded. The page was left on Home.
- Evidence: `evidence/sharepoint-staff-home.jpg`, `evidence/sharepoint-staff-home-full.jpg`, `evidence/sharepoint-staff-saved.jpg`. The account currently has no published news/events to render, so this pass explicitly verifies the live empty experience. Populated resource rows and the revised editor were checked through source/type/build validation, not through an elevated browser session; the user's login and permissions were not changed.
- No permission/schema/publishing changes in this visual revision; previous backend boundary tests remain the relevant foundation evidence.
