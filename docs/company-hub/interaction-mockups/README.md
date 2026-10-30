# Company Hub interaction collection

Design review collection created 14 October 2026. This directory is independent of the application implementation and the approved original mockup.

Open `index.html` for the standalone collection. The conversation version is `C:/Users/steph/.codex/visualizations/2026/10/13/01a136b7-77ea-7c12-a824-ea96761a44e6/company-hub-interactions.html`. It starts with Give a shout-out. **All journeys** opens a task-based index: Connect, Find information, Make it yours and Manage content. Search covers all 37 journeys regardless of the selected category.

## Review the first journey

1. Choose a colleague, select several people, or switch to Whole team.
2. Search by name, role, site or team. Selected people appear as removable chips.
3. Continue and write a message. The card appears at this stage and updates as you type. Expand **Add a personal touch** to choose an optional value or change the card heading.
4. Review names, card details and message. Edit any part before sending. A message-only thank-you is valid in this revised prototype.
5. Send into the fictional preview, then read the completed card, appreciate it, reply, open a profile, or curate the shared spotlight as an editor.

Use the preview-state selector to explore loading, empty, validation, failed saving/sending, permission limits and stale changes. Recoverable error causes the next simulated save/send to fail once without clearing the work. Retry then succeeds. The role selector is a design-review aid, not a proposed application permission switch. Birthday sharing starts off and is enabled for one fictional person through the HR preview.

## Inventory and reference comparison

The collection covers the requested 35 families. Two existing discovery families were promoted to their own index entries after comparing the original source fragments and current entry points.

The original source grouping below describes file ownership. The visible index now uses the four task categories above; all family IDs remain unchanged.

| Source group | Families | Local source |
|---|---|---|
| People & appreciation | 1 Give a shout-out; 2 recognition reading, reactions, replies and spotlight; 3 celebrations and individual HR birthday sharing; 4 comments and moderation; 14 colleague discovery and work profile | `people.js` |
| Gather & contribute | 5 event details, RSVP and waitlist; 6 event drafts, recurrence, review, scheduling and attendance; 7 gallery and recap; 8 idea submission; 9 owner, routing and dated progress; 10 polls | `community.js` |
| Read & discover | 11 announcements and exact-version acknowledgement; 12 guides and personal checklists; 13 corrections and owner review; 16 team spaces; 17 campaigns; 18 service handoffs; 19 grouped search; 36 resource discovery/collections; 37 newsroom/story reading | `reading.js` |
| Make it yours | 15 shared profile editing; 20 saved content and activity; 21 interests; 22 notifications; 23 personal shortcuts | `people.js`, `personal.js` |
| Create & curate | 24 page starters; 25 hero controls and preview; 26 illustrated block library; 27 section/block designer; 28 media; 29 shared navigation | `designer.js`, `publishing.js` |
| Publish & improve | 30 review and publication lifecycle; 31 version restore; 32 draft safety/recovery; 33 editorial calendar/plans; 34 engagement/participation/search insights; 35 common interaction states | `publishing.js` |

Reference verification used:

- `docs/company-hub/approved-mockup/company-hub-designer.html`, including the final Overview backgrounds (`#eeeff3` / `#1c1d26`) and compact header treatment.
- The preserved management, community, depth, editor-suite, library, attention, refinement, polish and usability fragments.
- `docs/company-hub/mockup-parity-audit-20261014.md`, `RESUME-HERE.md`, and `approved-mockup-delivery.md`.
- The current Company Hub dialog, recognition, event, resource, profile, navigation, designer, publishing, planning and discovery entry points, plus the shared `recognition-wizard.tsx`.

Added family **36** corresponds to resource team/type/owner/topic discovery, saved/recent filtering and collection authoring. Added family **37** corresponds to newsroom cards/list, author search, article reading, save and discussion entry. Reusable section management stays inside 27; event draft management stays inside 6. Staff requests stop at the existing canonical service handoff; no replacement HR or IT request system is proposed.

## Design choices

- Three recognition stages. People selection is a compact dialog without an empty card. Writing and review include the live card; value and card heading sit behind an optional disclosure with no extra stage.
- Card-heading labels are warmer presentation text: Thank you, You made a difference, You inspired us and Above and beyond. Existing internal impact values are preserved. This prototype now treats the value as optional; this is a design proposal, not an application rule change.
- Long authoring tasks use a page workspace. News, resources, people, work profiles and events use reading pages with shared Hub navigation; personal preferences and discussion tools use a side panel. Other focused journeys retain appropriately sized dialogs.
- Open list rows and unframed supporting columns reduce nested boxes. Status messages, selected controls and meaningful content cards retain their visual distinctions.
- Stable translucent navigation and a compact shell behind the focused interaction.
- Reading space and a dedicated RSVP column for events; narrower panels for preferences and discussions.
- Distinct saved/seen/acknowledged states, personal/shared settings, moderator-hidden/author-removed text, and draft/page lifecycle actions.
- Shared authoring uses editor permissions; individual birthday sharing uses HR permission. The product remains one organisation across approved sites.
- Illustrated library choices, live hero/media previews, independent sections, keyboard alternatives to dragging and resizing, visible focus, dialog focus containment and reduced-motion support.

## Remaining-journey refinement

The second refinement pass extends beyond the recognition flow:

- Events put the date, location, host, availability and registration action together. Ideas use a focused writing form; recaps retain their edited text; poll results reflect the current options and local vote.
- Reading uses quieter surfaces and clearer headings. Corrections retain the report and owner response; services use complete clickable rows; Hub search provides a preview for the selected result.
- Resources place compact filters beside a readable result. Recent and saved filters follow local activity. News has distinct stories, author search, comfortable article width and saved links that reopen the selected story.
- Page starters create their stated block arrangements. Blocks retain their content, reopen for editing and show representative content in the designer. Unfinished visible blocks must be completed or hidden before review or publication.
- Media titles, descriptions and captions belong to each image. Search, copying, removal and restoration use that item's details. Local image selection remains entirely in the preview.
- Editorial planning follows the saved date's month and content type. Campaign target dates persist in the local reading view. Navigation and shortcut boundary controls are disabled when movement is unavailable.
- Profiles, personal preferences, discussions, version history, recovery and insights share the clearer headings, quieter layouts and consistent control treatment. These receive shared visual refinements rather than a full rewrite of every simulated action.

The preview still uses representative records and some illustrative actions. For example, attendance/export, announcement scheduling, media albums and aggregate insights are not full implementations. These designs and local checks do not establish production readiness.

## Connected browsing and home

The six approved improvements are applied across the collection:

- News, Resources, People and Events have proper browse pages, shared Home/News/People/Events/Resources/Teams navigation and an explicit return action. Focused tasks retain their dialogs or panels. Reading pages allow keyboard focus to move into the application navigation.
- Returning from a story, resource, event or profile restores the originating search, filters, display mode, scroll position and focused control. Switching among browse sections remembers their local presentation state. These choices reset on reload.
- Home brings together required announcements, upcoming events, saved content, appreciation and recent stories. Acknowledging the current announcement clears its confirmation prompt; event choices and saved items update home. Event dates and availability follow local event edits and cancellations.
- Repeated eyebrow headings are removed, browse results use quieter rows, and secondary actions receive less emphasis. Long names and titles wrap without hiding the useful details.
- Custom feature icons remain where their meaning is useful. Writing assistance no longer carries the appreciation mark. Familiar utility actions keep their established symbols.
- Fictional content now includes 18 colleagues, eight resources, eight stories and four events, with varied title lengths, roles, locations and content. Resources have separate checklist progress; each event has its own registration or waitlist choice.

`connected-data.js`, `connected.js` and `connected.css` contain this pass. Events browse view 44 belongs to the existing event family 5; the All journeys inventory remains 37 families. Close the initial shout-out dialog to explore the revised home, or use the shared Home navigation from any reading page.

## What the preview does and does not do

All people, replies, events, counts and content are fictional. Interactions mutate local preview memory. Reloading resets the demo; only optional appearance/review context can be remembered by the conversation host. A local image picker reads a chosen image into the preview and does not upload it. The calendar control can download a clearly labelled fictional `.ics` file. The contact copy action copies an `example.org` address only after clicking it.

No recognition, email, account change, publication, notification, staff record, database write or backend call is made. The application source, migrations, tests and approved mockup were not edited. This collection demonstrates design and local transitions; it does not establish production persistence, permissions, privacy, delivery or security correctness. Some record counts and media previews are representative examples, not computed production analytics.

## Original feature icons

The user's request for unique icons replaces generic sparkle and feature symbols with 12 original vector drawings: appreciation, people, ideas, gatherings, stories, resources, care, progress, conversation, celebrations, saved and our Hub. Rounded strokes and offset joins form the common visual language. The appreciation mark uses two clapping hands with short motion marks. It replaces the earlier linked-loop design after user feedback that it resembled a paperclip.

`hub-icons.js` is the source of the glyphs and their mappings. `hub-icons.css` handles sizing and the family preview. `icons/` contains individually reusable SVG assets; `node export-icons.cjs` regenerates them from the source. Each has a 24-unit viewbox, a 1.7-unit stroke, rounded terminals, a descriptive title and `currentColor`. Inline icons are decorative beside accessible text labels. Standard arrows, close, search and status controls keep their familiar utility glyphs. The Oblivion logo itself is unchanged.

Open **All journeys → Our icon family** to compare the drawings and open their related journeys. The original **Give a shout-out** entry remains the starting screen. `icon-checks.json` records the recognition card/header and hero-picker checks, plus both themes across all 37 journeys at 1024px. The checks found no remaining generic sparkle or star glyphs in those rendered screens. The SVG drawings were authored directly for this requested icon system; they are not generated raster images or a claim of trademark exclusivity.

## Validation and maintenance

- `connected-checks.json` records the latest connected-flow checks: return search/filter/layout/scroll/focus, nested checklist/profile navigation, independent event choices, home acknowledgement/saved/event state, purposeful icons and staff page keyboard navigation. Edited event dates, full availability and cancellation were checked on home and event discovery. It measures the 37 original journeys plus the event browser at 1024px in both appearances. `connected-*.png` captures the reviewed home and browse pages at 1360px and 1024px. The editor, core journey, control and clarity checks were also rerun during this pass.
- Older `rest-checks.json` and `icon-checks.json` record their respective earlier refinement passes; they should not be treated as new validation of every expanded fixture or connected flow.
- `editor-finish.js`, `journey-finish.js` and `journey-finish.css` contain the remaining-journey refinements. `rest-checks.json` records starter/block content, publication readiness, per-image details, recaps, polls, corrections, exact saved stories, resource history, editorial rescheduling and campaign date checks. It includes 74 layout measurements: all 37 journeys at 1024px in both themes. `rest-*.png` captures the reviewed screens.
- `clarity.js` and `clarity.css` apply the simpler recognition flow, task categories/global search, quieter surfaces and page/side-panel layouts. `clarity-checks.json` records message-only recognition, optional details with keyboard access, draft preservation, global search recovery, editor exit guards, saved side-panel preferences and all 37 family layouts at 1024px. `clarity-*.png` captures the reviewed layouts in both themes.
- `control-checks.json`: redesigned menus and inline choices, keyboard selection, focus restoration, searchable people, no-match recovery, preserved drafts, menu placement at 1024px and a scan of all 37 families for native selects.
- `refinements.js` and `refinements.css` replace native dropdowns with consistent product menus, expose short choices directly, explain recognition values and put writing first in the shout-out message step. The role/state controls use the same menu treatment. People menus include role/site context and search; menus adapt their placement to available space. These remain local prototype interactions.
- `browser-checks.json`: 37 family openings and core recognition, retry, reply, event registration, acknowledgement and draft recovery checks.
- `editor-checks.json`: media return, block/section operations, birthday preview gating, moderation distinctions, idea history, shortcut validation, preferences, schedule guards and history restore.
- PNGs capture recognition in both themes, the index, event details, illustrated library and editor at desktop widths including 1024px.
- `frame.html` is the literal fragment shell/styles. `core.js` provides shared state and interaction helpers. `build.ps1` composes the family sources and embeds optimised copies of the existing Company Hub demo images. It writes only this directory and the task visualization directory.
- `prepare-preview.cjs` creates a standalone icon adapter from the installed Lucide package. The native conversation uses its supplied Lucide runtime. `check-preview.cjs` and `check-editor.cjs` operate solely on the local fictional collection, not the application.

The prototype is a reviewable design handoff. Production integration remains with the parent implementation session.
