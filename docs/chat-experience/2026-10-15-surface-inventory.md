# Chat surface inventory

Reviewed: 15 October 2026, Pacific/Auckland. Companion to the [product specification](2026-10-15-specification.md).

This is a source review of the current working tree, based on commit `d48ea17e5` plus existing uncommitted work. It is not a claim that the deployed application, live notifications, or calling have been tested. No product code was changed for this review.

Search covered application routes, controllers, models, services, React pages/components, navigation, notifications, relevant tests, and the Obli endpoint client. Searches followed chat/message/conversation/discussion references and direct links into the messaging routes. Older audit documents were used only to locate possible surfaces; current source is the evidence. A final route/link sweep and role-based browser walk-through remain required before implementation scope is signed off.

Treatment categories: **shared chat** uses the common conversation experience; **entry point** opens an authorised conversation; **adapted thread** reuses appropriate presentation while retaining its domain rules; **separate workflow** remains a formal record or specialised conversation. The latter categories are deliberately accounted for, not omitted.

## Existing chat and entry points

### S01 — Main staff navigation

- Source: [app-sidebar.tsx](../../resources/js/components/app-sidebar.tsx), particularly the main icon rail and Operations communications group.
- Current: Messages is nested under Operations; Company Hub is a separate main destination.
- Proposed: top-level **Chat**, after My Day, with an accessible unread badge. Move the staff Messages destination into this home; keep old URLs working. Company Hub keeps its purpose and identity.
- Treatment: entry point. Mockups M01, M02.

### S02 — Global header

- Source: [app-header.tsx](../../resources/js/components/app-header.tsx), [HandleInertiaRequests.php](../../app/Http/Middleware/HandleInertiaRequests.php).
- Current: Messages links to `/operations/messages`; unread count comes from shared page data.
- Proposed: same Chat destination and unread source as the sidebar; consistent accessible name, selected state, and notification behaviour.
- Treatment: entry point. Mockups M01, M09.

### S03 — Staff Messages workspace

- Source: [Index.tsx](../../resources/js/pages/operations/messages/Index.tsx), [MessageController.php](../../app/Http/Controllers/Operations/MessageController.php), [operations routes](../../routes/operations.php).
- Current: direct/group conversation data, people search, text, reactions, pins, quoted replies and receipt UI. Server supports multiple participants; the visible new-chat action starts a chat with one selected person.
- Proposed: full shared Chat workspace, direct chats, private groups, managed channels, family inbox and Calls.
- Treatment: shared chat. Mockups M01–M09, M18.

### S04 — Family portal Messages

- Source: [messages.tsx](../../resources/js/pages/portal/messages.tsx), [PortalMessageController.php](../../app/Http/Controllers/Portal/PortalMessageController.php), [portal routes](../../routes/portal.php).
- Current: separate full chat implementation, named-worker selection, attachments, reactions, pins and voice-note UI.
- Proposed: same shared conversation components with family capabilities; clear client context, care-team inbox and named-worker routes, disclosed cover access, voice/group calls and callbacks.
- Treatment: shared chat. Mockups M10–M13, M19.

### S05 — Client-profile family popup

- Source: [family-chat.tsx](../../resources/js/components/clients/profile/family-chat.tsx), [dialog-host.tsx](../../resources/js/components/clients/profile/dialog-host.tsx), [client profile](../../resources/js/pages/operations/clients/show.tsx), [ClientFamilyChatController.php](../../app/Http/Controllers/Operations/ClientFamilyChatController.php).
- Current: independent popup, latest participating family conversation, text-only projection, eight-second polling, latest 100 messages. It reports older history exists but offers no history-loading control.
- Proposed: shared drawer with an explicit conversation selector when more than one exists, media/voice support, readable audience and an **Open in Chat** action retaining the same conversation and draft.
- Treatment: shared chat. Mockups M06, M14.

### S06 — Family dashboard contacts and shortcuts

- Source: [family-dashboard.tsx](../../resources/js/pages/portal/family-dashboard.tsx).
- Current: multiple care-team/current/next-worker Chat links lead to the general client messages page rather than carrying the selected worker.
- Proposed: **Message care team**, **Message [name]**, **Call** and **Request callback** where allowed; preserve intended recipient and client. Distinguish on-shift from actually available to call.
- Treatment: entry point. Mockups M10, M12, M19.

### S07 — Family portal navigation and client switching

- Source: portal branch of [app-sidebar.tsx](../../resources/js/components/app-sidebar.tsx), [portal index](../../resources/js/pages/portal/index.tsx), [portal client](../../resources/js/pages/portal/client.tsx).
- Proposed: Chat label aligned with staff, scoped to the selected client; no mixing conversations, drafts, badges or contact directories between clients.
- Treatment: entry point. Mockups M10, M11.

### S08 — Older portal conversation component

- Source: [messages/show.tsx](../../resources/js/pages/portal/messages/show.tsx).
- Current: another standalone chat rendering exists. Both inspected PortalMessageController render paths use `portal/messages`; no active controller reference to `portal/messages/show` was found.
- Proposed: verify reachability and remove/deprecate only after the route/import audit. Do not treat it as a fifth live chat UI or maintain a competing component.
- Treatment: legacy candidate. Mockup M24 covers old-link behaviour; no separate design needed if confirmed unreachable.

### S09 — Mobile staff shell and My Day

- Source: [staff-bottom-nav.tsx](../../resources/js/components/staff-bottom-nav.tsx), [use-mobile-navigation.ts](../../resources/js/hooks/use-mobile-navigation.ts), [My Day](../../resources/js/pages/my-day/index.tsx).
- Current: bottom navigation has Home, Meds, Clock, Report, More; no dedicated Chat slot.
- Proposed: prominent Chat action in the mobile header and More menu, plus relevant My Day follow-ups. Keep My Day as the worker home and preserve existing medication/clock/report access unless a later mockup decision explicitly changes the navigation.
- Treatment: entry point. Mockups M02, M16.

### S10 — Medication workflow Message lead links

- Source: [record-dose/dialogs.tsx](../../resources/js/components/emar/record-dose/dialogs.tsx), [record-dose-dialog.tsx](../../resources/js/components/emar/record-dose/record-dose-dialog.tsx).
- Current: named-lead action links to the generic Messages index.
- Proposed: open the intended eligible staff conversation, preserving the medication workflow/draft. Sharing any clinical context must be deliberate and independently authorised. Chat does not satisfy medication authorisation, escalation or witness requirements.
- Treatment: entry point. Mockup M16.

### S11 — Control Room quick messaging

- Source: [messaging.tsx](../../resources/js/pages/control-room/messaging.tsx), [ControlRoomMessagingController.php](../../app/Http/Controllers/ControlRoom/ControlRoomMessagingController.php), [Communication.php](../../app/Models/ControlRoom/Communication.php).
- Current: separate direct/alert message UI, separate communication records, 15-second refresh. Notification and escalation delivery rows are explicitly excluded from conversational records.
- Proposed: shared thread/composer presentation through a Control Room adapter; preserve alert/site permissions and delivery semantics. Full Chat links into a contextual view when authorised; do not duplicate messages or import outbox rows as chat.
- Treatment: adapted thread. Mockup M15.

### S12 — Control Room alert discussions

- Source: [alert-workspace-dialog.tsx](../../resources/js/components/control-room/alert-workspace-dialog.tsx), [ControlRoomDiscussionController.php](../../app/Http/Controllers/ControlRoom/ControlRoomDiscussionController.php), [control-room routes](../../routes/control-room.php).
- Proposed: matching interaction and typography primitives where useful, with alert-specific editing/audit permissions intact. Link conversations to the alert without changing its evidence history.
- Treatment: adapted thread. Mockup M15.

### S13 — Notifications, unread counters and push

- Source: [notifications page](../../resources/js/pages/notifications/index.tsx), [operations notifications](../../resources/js/pages/operations/notifications/Index.tsx), [portal notifications](../../resources/js/pages/portal/notifications.tsx), [OpsMessageVisibilityService.php](../../app/Services/Operations/OpsMessageVisibilityService.php), [notification preferences](../../resources/js/pages/settings/notifications.tsx), [service worker](../../public/sw.js), [push services](../../app/Services/Notifications).
- Proposed: consistent chat/missed-call notifications and authorised deep links; same per-person unread truth everywhere; privacy-safe previews, deduplication, quiet hours and device capability states. Existing generic push/Expo support is not proof of native VoIP support.
- Treatment: entry point/shared infrastructure. Mockups M09, M20, M23.

### S14 — Global Settings and family communication administration

- Source: [settings layout](../../resources/js/layouts/settings/layout.tsx), [settings routes](../../routes/settings.php), [family portal Edit](../../resources/js/pages/operations/family-portal/Edit.tsx), [portal-user administration](../../resources/js/pages/clients/portal-users.tsx).
- Current: notification/role/escalation settings and client portal visibility controls exist; no dedicated Chat settings section found in those settings routes/layout.
- Proposed: **Global Settings → Chat**, effective-policy summaries in relevant client/site settings, and personal preferences using existing notification controls.
- Treatment: new settings surface within the existing shell. Mockups M21–M23.

## Related workflows to preserve and connect deliberately

### S15 — Family notes and shift follow-up

- Source: [portal family notes](../../resources/js/pages/portal/family-notes.tsx), family-notes tab in [client profile](../../resources/js/pages/operations/clients/show.tsx), [FamilyNoteController.php](../../app/Http/Controllers/FamilyNoteController.php).
- Treatment: separate structured workflow. Offer a reviewed **Create follow-up** action from chat using existing assignment/status rules. A reply or read receipt never means a family request was completed. Mockups M13, M16.

### S16 — Handovers, shift notes and tasks

- Source: [handover workspace](../../resources/js/pages/operations/handovers/Index.tsx), [communication-workspace-rail.tsx](../../resources/js/components/workforce/communication-workspace-rail.tsx), My Day task/follow-up components.
- Treatment: formal records remain canonical. Link to chat or create an authorised follow-up with explicit confirmation; do not silently publish chat into handover or turn a task into a conversation. Mockup M16.

### S17 — Client communication notes and respite communication logs

- Source: [communication-notes.tsx](../../resources/js/pages/operations/clients/tabs/communication-notes.tsx), [RespiteCommunicationLogController.php](../../app/Http/Controllers/Respite/RespiteCommunicationLogController.php), [respite communication pages](../../resources/js/pages/respite/communication-logs).
- Treatment: separate formal records. An optional **Record communication** action can prepare a human-reviewed summary and source link. No automatic call transcript or automatic clinical record. Mockup M16.

### S18 — Client timeline comments and media/documents

- Source: [timeline-interactions.tsx](../../resources/js/components/timeline-interactions.tsx), [portal timeline](../../resources/js/pages/portal/timeline.tsx), [portal photos](../../resources/js/pages/portal/photos.tsx), [portal documents](../../resources/js/pages/portal/documents.tsx).
- Treatment: keep comments attached to their original items. Reuse appropriate message/reaction primitives; a chat context card rechecks the target record and attachment access. Sharing in chat must not silently widen gallery/document visibility. Mockups M07, M13, M17.

### S19 — IT tickets and request conversations

- Source: [ticket-thread.tsx](../../resources/js/components/it/ticket-thread.tsx), [ticket-reply-composer.tsx](../../resources/js/components/it/ticket-reply-composer.tsx), [ticket-drawer.tsx](../../resources/js/components/it/ticket-drawer.tsx), [ticket page](../../resources/js/pages/it/tickets/show.tsx).
- Treatment: adapted presentation only where the shared primitives fit. Preserve public replies, internal notes, email delivery, command recovery and ticket audit semantics. **Chat with requester** is a separate authorised conversation, never an email-send alias. Mockup M17.

### S20 — Company Hub discussions, recognition and staff services

- Source: [_discussion.tsx](../../resources/js/pages/company-hub/_discussion.tsx), [_staff-services.tsx](../../resources/js/pages/company-hub/_staff-services.tsx), [recognition-reply.tsx](../../resources/js/components/recognition/recognition-reply.tsx), [HubConversations.php](../../app/Domain/CompanyHub/Services/HubConversations.php).
- Treatment: page discussions, recognition replies and service requests keep their own audience and moderation. Reuse small primitives and provide an explicit **Chat with [person]** link where permitted; do not move the entire Hub into Chat. Mockup M17.

### S21 — Obli AI and technician assistance

- Source: [ObliChatController.php](../../app/Http/Controllers/Obli/ObliChatController.php), [technician-obli.tsx](../../resources/js/pages/it/remote-support/technician-obli.tsx), [desktop ChatSupport.cs](../../endpoint-agent/tray/Obli/ChatSupport.cs).
- Treatment: separate AI conversation with its existing consent/knowledge/security boundary. Identify AI clearly. No automatic access to staff/family chats. Web primitives can be reused where compatible; a native desktop UI cannot import React components. Not a new human calling surface. Mockup M17 includes the boundary/navigation treatment only.

### S22 — Broadcasts, rostering communications and announcements

- Source: [Control Room broadcast](../../resources/js/pages/control-room/broadcast.tsx), [roster broadcast dialog](../../resources/js/components/rostering/broadcast-dialog.tsx), [AnnouncementController.php](../../app/Http/Controllers/Hr/AnnouncementController.php).
- Treatment: retain delivery/acknowledgement/recipient selection rules. An authorised discussion link can be offered, but a chat reaction or receipt cannot replace required acknowledgement. Mockups M15, M17.

### S23 — Other sensitive module records

- Areas: HR/candidate correspondence, wellbeing, governance, incidents, medication records, fleet/security records, and tasks.
- Treatment: no blanket chat embedding or copying of their records. Use a shared **Discuss** entry point only for a concrete approved workflow; resolve eligible people and validate the linked record at display time. Existing correspondence stays in its domain. The final route sweep must explicitly classify any additional human conversation found here. Mockups M16, M17.

### S24 — Future Oblivion Care mobile app

- User-confirmed future iOS/Android project; native app development is outside this request.
- Treatment: retain common identity, conversation IDs, policies, receipts and calling contracts. Locked-phone/background incoming calls are future native acceptance requirements, not a promise of the browser release. Mockup plan reserves a handoff note only; no separate mobile app mockups now.

## Concrete gaps to address

These are source observations, not results of end-to-end execution.

- **F01, voice-note mismatch:** staff recorder builds a blob but posts a text placeholder (`Index.tsx`, recording handler around line 349). Portal recorder uploads `.webm`, while `PortalMessageController::storeMessage` allows images/documents, not that audio type. Require real upload/playback or hide the feature until it works.
- **F02, fragmented freshness:** family popup polls every eight seconds, Control Room every fifteen; no live chat subscription/poll loop was found in the two main staff/portal components. `routes/channels.php` currently registers fleet channels. Establish authenticated chat events and recovery; existing fleet/CCTV/remote-support realtime facilities do not establish working human calling.
- **F03, divergent receipts:** participant `last_read_at` drives unread counts while message payloads/UI also expose `is_read`/`read_at`. Define per-recipient read semantics and stop displaying unsupported delivery claims.
- **F04, inconsistent history:** popup only provides the latest 100 text messages. Staff endpoint paginates 50 messages, while the reviewed UI types only consume `data`. Require older-history navigation and stable scroll across all chat renderings.
- **F05, pin/star confusion:** both Star and Pin menu actions call `togglePin` in staff and portal pages. Personal Saved messages must be separate from shared pins.
- **F06, replies are copied text:** both main pages prepend a quote to message content. Store a real reply reference with access/deletion handling instead of relying on a stale copied quotation.
- **F07, recipient context lost:** family dashboard contact cards and eMAR named-lead links land on generic message indexes. Preserve intended client/recipient and return context.
- **F08, no visible ownership workflow:** current family message model/controllers do not establish the proposed shared-inbox claim/cover/callback workflow. This needs server behaviour and explicit audience rules, not just a redesigned list.
- **F09, inconsistent interaction:** separate date formatting, right-click menus, unconditional scroll-to-bottom and a hand-built popup increase mobile/accessibility/recovery risk. Use shared project date, dialog, focus and touch patterns.
- **F10, attachment side effects:** portal message uploads create gallery/document records. Existing disclosure/media protections must be preserved; the new design needs an explicit decision about conversation-only media versus publishing to those collections.
- **F11, membership migration:** current tests intentionally deny private family conversations to non-participants. Shared cover is a newly requested, disclosed capability. Do not broaden old conversations invisibly or remove existing site/client/current-staff checks.

## Existing checks to preserve and extend

- [OpsConversationParticipantAccessTest.php](../../tests/Feature/Operations/OpsConversationParticipantAccessTest.php): participant boundaries, current staff, exact client provenance, independent unread state and direct-pair identity.
- [ClientFamilyCommunicationSecurityTest.php](../../tests/Feature/Operations/ClientFamilyCommunicationSecurityTest.php): family permissions, private conversation participation, stale identities, omitted history, worker assignment and family-note lifecycle.
- [PortalMessageMediaSecurityTest.php](../../tests/Feature/Portal/PortalMessageMediaSecurityTest.php): private media, nested client binding, safe upload formats and disclosure-aware delivery.
- [portal-messages-upload-security.test.tsx](../../resources/js/test/portal-messages-upload-security.test.tsx): frontend media behaviour.
- [ControlRoomMessagingAuthorizationTest.php](../../tests/Feature/ControlRoom/ControlRoomMessagingAuthorizationTest.php): accessible sites/threads/people, deterministic summaries and exclusion of notification outbox rows.
- Portal direct-route/disclosure tests, sidebar role/navigation tests, notification tests and service-worker privacy tests remain regression requirements.

## Completeness gate

Every S item must have an approved treatment, an owner during implementation, a mockup or an explicit reason no UI changes are needed, and verification evidence. Newly discovered surfaces receive another S ID. No surface is considered complete solely because the central Chat page looks finished.
