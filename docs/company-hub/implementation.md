# Company Hub implementation

Approved 11 October 2026. One operating organisation across approved sites; no new tenant boundary or tenant columns.

Latest user direction overrides earlier scope: **desktop application only, no mobile layouts or mobile preview needed**. The visual direction is a high-end, modern company magazine with strong photography, featured news, warm people stories, and tidy events and links. The user rejected the initial generic gradient-and-box design; its production build is not visual acceptance.

**12 October design correction:** The user rejected the magazine result and explicitly selected a **modern SharePoint-style company intranet layout while retaining Oblivion colours and styling**. This supersedes the magazine direction above. The actual staff view, especially Saved, must be the primary verification surface; a populated showcase alone is insufficient evidence of a good live experience.

Current redesign: compact company header and search; horizontal site navigation before content; home-only three-tile feature area; theme-aware content surface within the unchanged app shell; compact news/quick-link/people/event web parts; resource list; distinct inner-page headings and useful Saved empty state; editor properties on the right. Existing publishing, permissions, personalisation, birthday policy and canonical HR/calendar integrations remain intact. Six focused frontend tests, TypeScript, scoped lint and the production build passed. The live staff Home, Saved, News search, Resources, Events and personal menu were verified in the current dark-mode session. See [the revision record](sharepoint-redesign.md) for evidence and the limits of this browser pass; visual approval remains with the user.

## Accepted experience

- Company Hub between Overview and My Day, plus a top navigation shortcut. Existing landing routes stay intact.
- Distinctive branded hero, visually related to the existing app; polished desktop and dark-mode presentation.
- Capability-based authoring, publishing, layout editing and moderation. Staff read and give shout-outs.
- Shared canonical HR recognition, announcements, calendar, staff and resource records.
- Birthdays hidden unless HR explicitly enables each individual; month/day only on every celebration surface.
- Company-managed layouts and custom pages, plus staff shortcuts and saved items.

## Delivery and verification ledger

Everything below remains required until current evidence proves it complete. This is an implementation ledger, not a reduced substitute for the approved scope.

1. Foundation: schema, capability catalogue, current-staff/site/ownership authorization, immutable published snapshots, drafts, optimistic concurrency, scheduled publishing, expiry, review/restore/archive, audit trail.
2. Hub experience: hero, home/news/shout-outs/events/resources navigation, search, readable details, empty/loading/error states, shell links and desktop window resizing.
3. Visual editor: block library, sections/columns, accessible drag/move, templates, branding/cover/intro, audience/category configuration, custom pages/navigation, autosave/recovery, preview, revisions, optional approval, page owners/review dates.
4. Canonical integrations: HR shout-outs/reactions/replies/team recognition; announcements/acknowledgements; events/RSVP/reminders/recurrence/cancellation; approved birthdays/anniversaries/new starters; resources/directory/surveys.
5. Participation: comments/reactions/moderation settings, polls, personal/saved links and articles/events, notification preferences and actionable notices.
6. Demo: labelled fictional showcase separated from live staff data, believable imagery/content and a complete staff/editor walkthrough.
7. Verification: meaningful lifecycle/privacy/direct-object/role/site tests, targeted frontend checks, full production build, correct-host desktop/dark-mode browser verification and requirement-by-requirement completion audit. Mobile layouts and preview were removed from acceptance at the user's request.

## Workspace baseline

Started on main with unrelated workforce/leave changes, including HandleInertiaRequests.php, and existing untracked audit/evidence files. Preserve these changes. No subagents requested.

## Progress

- Goal armed; canonical HR services, navigation, branding and design contract inspected.
- Expanded backend suite: **23 tests, 75 assertions pass**, including participation, notifications, ownership, immutable snapshots and isolated showcase. Targeted frontend interaction suite: **3 tests pass**.
- Desktop redesign inspected on the correct Herd host at 1440 and 1280 widths and in dark mode. No horizontal overflow at 1280. Demo Admin's original Light appearance restored after verification.
- Two generated fictional photographs are saved under public/images/company-hub; showcase is a separate permission-gated route and does not write live records. Its editor playground stores changes only in memory.
- Final production build passed in 7m 9s. Verified current compiled bundles include the editor playground and updated hero. Scoped lint, TypeScript and whitespace checks passed; the build retains the application's existing large-chunk advisory.
- Final desktop browser walkthrough passed on `https://oblivionfindings.test/company-hub/showcase/edit`: title change, draft save, desktop preview, adding/moving sections, undo, automatic save and safe demo publication preview. The preview uses the same photographic lead story as the staff home.
- Live `/company-hub` verified: canonical recognition wizard opens, personal shortcut editor opens, all 17 local birthday controls are off, and no birthday records appear. No live shout-out, birthday activation or story was published for verification.
- Final showcase rechecked with no horizontal overflow at 1280 and no browser console errors. Original viewport restored; browser left on `/company-hub/showcase`. Evidence: `evidence/desktop-showcase.jpg` and `evidence/desktop-showcase-full.jpg`.
- Accepted implementation complete for the desktop-only scope. This is local implementation and verification, not a claim that it has been deployed to a production host or approved aesthetically by the user.

## Scope audit

| Requirement | Delivered behavior |
| --- | --- |
| Shared staff home | Sidebar order and top-bar shortcut; hero inherits application branding, with editable label/title/intro/cover. |
| News and pages | Categories, featured lead story, protected photographs/PDFs, approved video links, audience restrictions, custom navigation and published-content search. |
| Recognition | Existing HR recognition wizard supports colleagues/teams; the hub reads the same kudos, reactions and permitted replies. |
| Celebrations | Birthdays default off and require individual HR permission-controlled activation; no birth year/age exposed. Anniversary and welcome records remain canonical. |
| Events | Existing calendar feed, recurrence/cancellation visibility and calendar link. Named invitees can RSVP to the canonical series; organiser reminders remain managed in the calendar. Saved events refer to the canonical event. |
| Notices | Existing audience-filtered announcements link to their canonical read/acknowledgement flow. Notice blocks show acknowledgement state. |
| Resources | Managed links, custom resource pages, private file downloads, directory/training/policy/survey entry points; destination permissions still apply. |
| Personalisation | Private shortcuts, saved pages/events, news and owner-review notification preferences. |
| Participation | Published-page comments/reactions, scoped moderation and aggregate polls. Changed poll questions/options cannot reinterpret earlier votes. |
| Editor | Section and block library, 1/2/3 columns, keyboard/pointer section dragging and move controls, starter templates, desktop preview, undo, autosave, explicit failed-save/concurrency handling and unsaved-navigation protection. |
| Publishing | Private drafts; immutable submitted snapshots; separate-approver option; scheduled release/expiry; restore to draft; owner/review date; archive; audit events. |
| Delivery | Existing scheduler runs Company Hub in-app notices every five minutes. It rechecks audience/current staff/preferences and deduplicates notices. Production requires its normal scheduler to be running. |
| Client demo | Fictional showcase, read-only story/event walkthrough, isolated editor playground. No fictional HR/calendar/publication records inserted. |

## Asset provenance

Created using the built-in image generation tool for this hub showcase. These depict fictional people and are labelled as such in the showcase.

- `public/images/company-hub/community-garden.png`: editorial photograph of five fictional adult colleagues planting seedlings in a New Zealand community garden; warm natural afternoon light, timber raised beds, candid interaction, no text or logos.
- `public/images/company-hub/colleagues-together.png`: editorial photograph of four fictional adult colleagues sharing ideas around a timber table in a bright New Zealand office; plants, daylight, relaxed neutral clothing, no text or logos.

## Integration notes

- Schema: apply the two Company Hub migrations through the normal deployment process. Capability defaults are seeded for existing internal roles; every request checks current staff and effective permissions, including explicit denials.
- Local verification required two existing medication/staff-access prerequisite migrations because this checkout's User model expected newer local schema. Unrelated pending migrations were not applied.
- Existing shared dependencies are a junction. Vite needs access to that existing dependency directory to write its temporary configuration. No dependency versions or lockfiles changed for the hub.
- No development-server override remains in `public/hot`; final preview uses same-origin compiled assets and the existing CSP.
