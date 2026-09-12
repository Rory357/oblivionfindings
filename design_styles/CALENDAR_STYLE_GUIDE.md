# Calendar style — shared Site Calendar experience

Approved 2026-09-13. This is Rory's calendar design rule for Oblivion Findings.

All new or updated calendars use the look and feel of `/calendar`.
The canonical implementation is `resources/js/pages/sites/calendar/SiteCalendar.tsx`,
with its views and supporting pieces in `_parts.tsx`. My Calendar uses the same
component with `resources/js/lib/my-calendar-adapter.ts` as its data adapter.

## Fixed visual language

- Use the Event Horizon PageHeader: branding-derived sky, ring mark, title,
  scoped search, action cluster, linked meter blocks, date/display controls
  and the connected Month / Week / Day / Agenda / Timeline rail.
- Keep the shared source pills, white/card calendar surface, event styling,
  grid spacing, detail treatment and desktop Today rail. Use semantic tokens
  and the existing source palette; never copy a custom FullCalendar skin.
- Use Home-rooted breadcrumbs and the shell's standard gutter.
- Different modules supply their own feed, source labels, permissions and
  appropriate links. Presentation reuse never grants access to other
  modules or exposes their records. My Calendar remains personal to the
  signed-in worker and routes changes through the owning workflow.
- Provider connections and organisation-wide staff sync are administered
  in Settings → Calendar Sync. Workers should not need individual provider
  sign-in for organisation-managed work calendar sync.

## Prominent date anchor

The first header meter is the calendar date anchor. Display the viewed day
as a large number, the month written in full, and the four-digit year as a
clear adjacent stack. Its caption gives the weekday and complete date (or
the full viewed week range). Keep the entry count as the meter's secondary
value. Reuse the meter typography and glass surface so this is part of the
header, not another banner or toolbar.

The anchor must update with Previous, Next, Today and Jump to date. It
describes the browsed date, not always today's date. Week ranges crossing
December/January name both years. Today's date remains in the Today rail.

## Interaction standard

Reuse the shared right-click creation menu, blank-date/time creation controls,
keyboard navigation and entry styling. A visible New entry action must provide
the same workflow on touch devices. Right-clicking an existing entry opens its
actions; it must not accidentally create something underneath that entry.

Only entries the user can edit offer drag or resize controls. My Calendar's
personal planning editor supports tasks, meetings, appointments and reminders;
assigned shifts and care records retain their original workflows. Describe
meeting invitation and external-sync behaviour accurately in the editor.

Show the selected day, month, year and timezone when editing. Retain drafts on
failed saves, prevent duplicate submissions, reject stale edits, and offer Undo
for reversible deletion/rescheduling. Multi-day entries must appear on each day
they overlap, with an exclusive midnight end boundary.

## Interaction verification

Check the actual calendar in desktop and mobile viewports. Confirm the
date anchor fits, the complete month/year is visible, navigation updates
it, all available views work, source filters preserve the same data scope,
and no worker controls rely on hover or colour alone. Use at least 44 px
tap targets on frontline surfaces. Existing alternative calendar designs
are migration targets when those surfaces are next changed; this rule
does not authorise an unrelated application-wide rewrite.
