# Chat UI/UX mockup coverage and approval plan

**Status:** specification approved; mockups ready for UI/UX review on 15 October 2026. Open the [interactive preview](http://127.0.0.1:8879/), [portable review](mockups/review.html), [screenshot gallery](mockups/gallery.html) or [review guide](mockups/README.md). All 24 groups are represented by 155 selectable scenarios. UI/UX approval and implementation approval remain pending.

This list prevents approval of one attractive central screen from being mistaken for approval of the whole chat experience. Each M item is a review group and may need several screens/states. Source surfaces are recorded as S IDs in the [inventory](2026-10-15-surface-inventory.md); acceptance checks use the spec’s A IDs.

## Design direction

Keep Oblivion’s existing visual language, calm typography and familiar controls. Use a clear conversation rail, readable message rhythm, restrained surface colours and obvious participants. Family screens should feel approachable; worker screens should be quick to use while interrupted. Avoid dense dashboard cards around the conversation.

Reuse the same composer, message actions, attachment treatment, status wording, call controls and audience indicators wherever their capabilities match. Desktop has room for details; mobile gets a focused thread and clear back action. Staff/family audience differences must remain obvious in both themes.

Use synthetic names, messages and attachments. No prototype should send a real message, call a person, mutate app settings or use production care data.

## Required review groups

### M01 — Main navigation and desktop Chat home

Show expanded/collapsed sidebar, Chat placement, header shortcut, unread badge, empty first-use state and a populated list. Include role-restricted views and distinction from Company Hub. Cover S01–S03; A01, A17.

### M02 — Mobile browser Chat home and navigation

Show list, search, filtering, mobile header/More access and return to My Day. Preserve Meds, Clock and Report access. Include 320px narrow layout, common phone width and tablet transition. Cover S01, S09; A01, A17.

### M03 — Staff direct conversation

Show message grouping, date/unread separators, sender identity, sent/read policy, presence, typing, new-message jump and details panel. Demonstrate scroll preservation while older messages are read. Cover S03; A02–A05.

### M04 — New direct chat and private group

Show eligible-person search, selected recipients, duplicate-direct opening, group title, membership summary, validation, creation failure and group details. Include invite/remove/leave/owner transfer and history disclosure. Cover S03; A03, A07, A08.

### M05 — Managed site/team channels

Show channel list, permitted channel, owner/member controls, join/access rules, archived/read-only channel and an unavailable linked record. Management controls must reflect actual capabilities. Cover S03, S14; A07, A08, A14.

### M06 — Shared composer and message actions

Show multiline text, reply, mentions, reactions, personal saved versus shared pinned, edit/deleted states, attachment selection and keyboard/touch menu access. Show draft restoration and unknown send outcome. Cover S03–S05; A02, A03, A17.

### M07 — Attachments and voice notes

Show photo/document queue, preview, file limit, progress, cancellation, retry and removed/restricted file. Voice: recording, preview, discard, send and playback. Include the explicit **Add to client photos/documents** flow and audience preview. Cover S03, S04, S18; A06, A07.

### M08 — Search, saved messages and history

Show people/conversation/message results, filters, no matches, inaccessible result, selected message in context, older history and personal Saved items. Preserve the return location and distinguish search failure from no results. Cover S03, S04; A04, A07.

### M09 — Unread, notifications and delivery recovery

Show matching header/sidebar/list counters; active-thread suppression; safe push/email previews; notification permission denied; expired login deep link; offline, reconnecting, unknown send outcome and retry. Cover S02, S13; A03, A05, A15.

### M10 — Family dashboard and contact entry

Show selected client, Message care team, named eligible contacts, available/unavailable worker, call/callback actions and configured/unconfigured response expectation. Include more than one linked client. Cover S06, S07; A01, A07, A09.

### M11 — Family conversation, desktop and mobile browser

Show approachable thread layout, selected client, named recipient/care team, disclosed cover, visible audience, files/voice and safe client switching. Include no previous messages, read-only/revoked access and more than one family conversation. Cover S04, S07; A02, A07, A08.

### M12 — Family recipient and member selection

Show care team versus named worker, separate-by-default conversations, explicit approved-relative invitation, join-history disclosure, removed relationship and a worker no longer assigned. No unrestricted directory. Cover S04, S06; A07, A08.

### M13 — Shared family inbox and follow-up

Show Needs reply, Assigned to me, Waiting on family, Callback requested and Resolved; claim/conflict, response owner, handoff, unstaffed queue, due-time policy and reopen. Include separate staff-only coordination and reviewed Create follow-up. Cover S04, S15, S18; A09, A10, A16.

### M14 — Client profile drawer

Show shared family conversation within the real profile context, choice of multiple threads, attachments, older history, load failure and read-only mode. Demonstrate Open in Chat and closing back to an unfinished form without losing state. Cover S05; A02, A04, A16.

### M15 — Control Room conversations and discussions

Show direct/alert thread, originating alert context, internal discussion, permitted reply and unavailable alert. Demonstrate how shared primitives fit the existing workspace while broadcast/outbox/evidence actions retain their meaning. Cover S11, S12, S22; A07, A16.

### M16 — Worker contextual entry points

Show My Day family follow-up, named medication lead, shift handover and communication-note entry. Demonstrate recipient/context preview, return to work, Create follow-up/Record communication and no automatic clinical publication. Include the respite communication-record variant where the same control applies. Cover S09, S10, S15–S17, S23; A07, A16.

### M17 — Related conversation boundaries

Show IT public reply versus internal note versus separate chat; Company Hub page discussion versus person chat; timeline comments versus chat; announcement acknowledgement versus conversation. Include an Obli AI-labelled navigation example. Reuse existing designs where no change is needed and record that decision explicitly. Cover S18–S23; A07, A16, A20.

### M18 — Staff browser voice calls

Show Calls list, direct/group initiation, audience confirmation, microphone check, incoming/outgoing, connecting, connected, mute/devices, invite/remove, leave/end and minimised global control. Include navigation to another app page while connected. Cover S03; A11, A12.

### M19 — Family voice calls and callbacks

Show care-team/named-person call, unavailable recipient, no answer, callback request/ownership/status/cancellation, family group invitation, participant preview and ended-call follow-up. Show cover answering under their own identity. Cover S04, S06; A09–A12.

### M20 — Call failures and device states

Show permission ignored/denied, no microphone, unsupported browser, busy, answered elsewhere, expired invitation, failed connection, reconnect, device change, access revoked and missed call. Explain browser-open requirements without promising locked-phone ringing. Cover S13, S24 browser boundary; A11–A13.

### M21 — Global Chat settings: general, family, access and channels

Show settings navigation, organisation defaults, approved site/service overrides, effective values, channel owners, cover policy, response hours/holidays and callback routing. Include disabled/no-authority state, save validation, conflict and change-impact summary. Cover S14; A14.

### M22 — Global Chat settings: calls, files, privacy and service health

Show voice enablement/group limits, media types and limits, edit/receipt policy, retention pending configuration, investigator access and restricted health diagnostics. Unsupported recording/video features must not appear as usable controls. Cover S14; A06, A14, A19.

### M23 — Personal preferences and notification integration

Show availability/DND, sound, enter-to-send, text size/display density where appropriate, permitted previews and inherited policy. Link existing notification settings coherently; explain why a mandatory rule cannot be disabled. Cover S13, S14; A14, A15, A17.

### M24 — Responsive, accessible and legacy-link review

Apply cross-cutting checks to every changed surface: light/dark, phone/tablet/desktop, keyboard, focus return, zoom, long names/text, keyboard-open composer and reduced motion. Demonstrate old Messages and portal links arriving at the right authorised conversation, or a neutral unavailable state. Confirm whether the older portal component can be retired. Cover S01–S23; A01, A17, A18, A20.

## Future app boundary

S24 receives a written handoff covering shared APIs, device identity, future native incoming-call lifecycle and locked-phone acceptance. Native Oblivion Care app screens/build are deliberately outside the current mockup set, following the user’s direction to build that whole app later. Mobile **browser** mockups remain in scope.

## Review method and completion evidence

Prepare a navigable, read-only prototype plus a screen index after spec approval. Organise review into worker chat, family contact/inbox, calling, settings and integration states. Each review group records:

- Screen IDs and which S/A requirements they demonstrate.
- Desktop/mobile and light/dark variants where the surface exists.
- Happy path, empty/loading, denied/read-only and relevant failure/recovery states.
- Decisions still pending, requested changes and revision history.
- **Not started**, **Ready for review**, **Changes requested**, or **Approved** status.

Use representative seeded/synthetic conversations across at least two sites and two clients, a family member linked to multiple clients, two family members with separate conversations, an assigned worker, eligible cover, an unassigned worker, a site lead, a settings administrator and an authorised investigator. These are roles/relationships within one organisation, not tenants.

The mockup gate is complete only when M01–M24 have been approved or have an explicit approved no-change treatment; S01–S24 have no unmapped work; and unresolved policy decisions are visibly recorded. A design approval is not evidence that realtime, microphone permissions, native lifecycle or delivery already works. Implementation still requires a separate user approval.

## Revision 2 delivery — ready for review

The approved refinement work now contains 25 groups and 191 scenarios, including M25 incoming calls over unfinished work and the compact two-step Communication Record / optional Family Note dialog. The 51 approved refinements are mapped in [the revision ledger](mockups/approved-revisions.json). Two additional review-and-fix passes are documented in [the review report](mockups/review-passes.md).

Open [the complete interactive set](http://127.0.0.1:8879/review.html#M01/default) or [the screenshot gallery](mockups/gallery.html). Every group is ready for the user's revised design review; no group has been marked user-approved on the user's behalf. Application implementation remains gated on separate approval.
