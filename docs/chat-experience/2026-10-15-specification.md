# Oblivion Findings Chat — product and experience specification

**Status:** Specification approved on 15 October 2026; complete mockups and web implementation approved on 16 October 2026. Implementation is in progress. **Date:** 16 October 2026, Pacific/Auckland.

**Approval sequence:** specification → complete UI/UX mockups → implementation. The user approved this specification and requested the complete web mockup set, including family portal, calls and Global Chat settings. Native iOS/Android app design/build remains later. The user approved the complete mockups and web implementation on 16 October 2026. Calling must be self-hosted only. Product acceptance is tracked in IMPLEMENTATION-STATUS.md.

Companion documents: [surface inventory and findings](2026-10-15-surface-inventory.md) and [mockup coverage and review checklist](2026-10-15-mockup-plan.md).

## 1. Intended outcome and confirmed decisions

**Decisions — 20 October 2026 (Stephan approved):**

- **Slate look:** Chat and the family portal take the app-wide Slate look (DESIGN.md "The Slate look"). Codex's approved structure — regions, order, controls, labels and flows — is kept exactly; only the skin changes. Chat uses semantic tokens only, so it follows the Slate token change automatically. The Oblivion signature (horizon line, ring glyphs, pathway) is applied later, only after it is approved.
- **Phone layouts:** not built. The app is desktop web only. Chat must still not break when narrow or zoomed (no horizontal scrolling; controls reachable). The approved 390/320 px layouts stay in the docs as input for the later native app.
- **Order of work:** Chat is tested and committed on its own branch and merged first; the Slate token change lands afterwards in one pass, because both touch the sidebar, header and settings layout.
- **Relative invitations:** a relative joins another relative's private family conversation only after the relative(s) already in it agree. Staff can propose the invitation, but it stays pending until a current family member of that conversation accepts it.

**Decisions — 22 October 2026 (Stephan approved), investigator access (M22):**

- **Own page:** investigators work on their own page (Settings › Chat investigations), not only in the M22 pop-up. The `chat.investigate` permission is still granted to nobody by default.
- **Time-limited, per conversation:** each conversation is opened by its own grant, with a case reference, a reason and an access period. Expiry, ending and the access log stay per conversation.
- **Several conversations under one case:** an investigator searches by a person, ticks several of that person's private staff conversations (searching again adds others) and grants them in one step under one case reference, reason and access period (at most 20). One grant row is still created per conversation, linked by a case batch, and all are created or none. Any family conversation in the selection refuses the whole request.
- **Controlled export:** while a grant is active, the investigator can export a PDF of that conversation, or of every conversation still active in the case. Nothing else is included. Every page is watermarked with the case reference, the investigator's name, the export date and time (New Zealand time; stored as UTC) and "Confidential — investigation record". The PDF includes removed messages and earlier versions of edited messages; attachments are listed by name only, never their contents. Each export is logged per conversation in the investigation audit with who, when, which conversations and the file's SHA-256 hash. Exports are refused after expiry or ending and are rate-limited (5 per 10 minutes per investigator). This replaces the earlier "Chat does not export conversations" position.

**Calling ownership decision — 16 October 2026:** After reviewing proprietary-engine and clean-room alternatives, the user selected option 1: Oblivion's custom application and workflows with self-hosted LiveKit audio infrastructure, subject to licence compliance. The underlying LiveKit code remains licensed third-party software; it is not represented as owned by Oblivion. This supersedes the interim request to own the audio engine. Native mobile remains deferred. The local Windows machine is authorised for development; `oblivionfindings.com` has not been connected to it.

Create one dependable Chat experience across Oblivion Findings: the organisation and conversation structure of a workplace messenger, with the approachable conversation flow of a personal messenger. Use the existing Oblivion visual language rather than reproducing either Teams or WhatsApp.

The user has confirmed:

- A **Chat** destination in the main left navigation. Recommendation: keep this name; it is short and covers everyday messaging naturally. Calls live inside it.
- Staff can start direct chats and private groups. Team/site channels are managed.
- Families can contact both a shared care-team inbox and named eligible support workers.
- Named-worker family conversations can be seen by authorised care-team cover, with that access clearly disclosed.
- Voice calls include staff one-to-one, staff groups, family-to-staff and invited groups of approved family/care-team members.
- Families call available people or the care team; when nobody can answer, offer a callback request.
- Chat settings belong in **Global Settings**.
- Response expectations vary by site/service; show no response-time promise until configured.
- Family text conversations are separate by default; other approved relatives join only by explicit invitation.
- Chat photos/documents remain in the conversation until explicitly published to the client gallery/documents by someone with permission.
- Private staff chats may be inspected for investigation only by specifically authorised investigators, with a recorded reason and access log.
- A whole **Oblivion Care app for iOS and Android will be built later**. This project covers the browser experience and shared foundations now. Locked-phone and native background calling remain requirements for that later app.
- The specification must be approved before mockups, and all UI/UX mockups must be approved before implementation.

**Approved default:** voice only; no video, screen sharing or call recording. Video is not needed for the stated workflows and can be considered separately if a clear care use case emerges. Browser calling does not include ordinary telephone-network calling or exposure of personal phone numbers.

**Architecture boundary:** one operating organisation across multiple sites, as defined in [single-tenant-application.md](../architecture/single-tenant-application.md). Authorisation uses current roles, permissions, approved sites, client relationships, conversation membership, canonical record ownership and privacy rules. No tenant selectors, switching, tenant transports or hypothetical multi-tenant fixtures. Existing legacy organisation columns remain compatibility details.

## 2. What exists and what needs to change

The [inventory](2026-10-15-surface-inventory.md) records 24 surface/integration areas, their current source and the proposed treatment. Discovery found separate staff, portal, client-popup and Control Room interfaces; an additional older portal component appears unreferenced by the inspected controller paths.

The present data model already supports conversations, participants, messages, reactions and participant read positions. Keep useful records and security boundaries. However, a polished interface alone will not provide the requested experience:

- Voice notes currently have a staff placeholder-send path and a family upload-format mismatch.
- Refresh behaviour differs between surfaces; no working human calling stack was identified in the reviewed chat code.
- Family popup history/media differ from the full portal view.
- Personal saving and shared pinning are conflated in the current menus.
- Replies are stored as copied quotations; receipts mix message-level flags with participant read positions.
- Some contact-specific actions discard the intended recipient on navigation.
- Shared family-inbox ownership, disclosed cover access, group calling and dedicated Chat settings need explicit behaviour.

These are source observations. Live deployment, browser rendering, push delivery, device permissions and end-to-end calls have not been verified. An approval-stage baseline walkthrough must confirm the actual staff/family routes and visual context before mockups are finalised.

## 3. Navigation and workspace

### 3.1 Staff desktop

- Add a main left-nav **Chat** item after My Day, with a speech-bubble icon and a numeric unread badge. Use an accessible label such as “Chat, 3 unread conversations”.
- The header shortcut opens the same destination. The old Operations Messages entry redirects or points here; it does not create a second inbox.
- Proposed canonical URLs: `/chat`, `/chat/conversations/{id}`, `/chat/calls`, `/settings/chat`. These are design proposals; final route names must fit existing conventions. Existing `/operations/messages/...` and portal links remain compatible.
- Chat has a conversation rail, the active conversation and an optional details panel. Avoid a large dashboard hero above the messages.
- Primary views: **Chats**, **Teams**, **Family**, **Calls**. Show only authorised views. Within Chats: **All**, **Unread**, **Mentions**, plus personal favourites. Provide **Saved messages** without confusing it with shared pins.
- Separate staff and family conversations with explicit text labels. A colour accent can assist, but must never be the only audience cue.
- Search people, conversations and messages; display results in context and return to the exact message. Authorisation applies before search results, snippets, counts or previews are returned.

### 3.2 Mobile browser

- One pane at a time: conversation list → thread → details, with a clear back action that restores list position.
- Chat is reachable from the mobile header and More menu. Preserve the existing five-slot worker bottom navigation and My Day home for this scope; any proposed slot change requires explicit mockup approval.
- Use a full-height thread, a composer that stays above the keyboard, safe-area spacing and at least 44px controls. No action may depend solely on hover or right-click.
- At intermediate tablet widths collapse details before compressing the reading area. Test landscape and large text.

### 3.3 Embedded chat

- Client profile and approved contextual entry points use the same conversation view inside a responsive drawer.
- **Open in Chat** keeps the same conversation ID, read position, unsent draft, attachments and reply selection. Closing the drawer restores focus and the underlying form.
- When a client has several family conversations, show the intended conversation or a selector. Never silently select a different private thread merely because it was most recently updated.
- A single active call control remains reachable across ordinary app navigation without covering medication, clock or submit actions. Full reload/session expiry must show honest recovery/end behaviour rather than claiming uninterrupted audio.

## 4. Shared chat system

Create a shared browser feature with small, reusable components and common behaviour. Proposed location: `resources/js/features/chat/`, with domain adapters alongside existing modules where appropriate.

Shared responsibilities:

- Workspace, conversation list/item, avatar/group identity, header and audience summary.
- Message list, date/unread dividers, message bubble, structured reply, reactions and action menu.
- Composer, attachment queue/previews, upload progress, audio recorder/player and sending/error state.
- Search, saved items, pinned items, member details and capability-aware controls.
- Draft ownership, realtime synchronisation, read positions, notifications and connection health.
- Call invitation, device check, active-call controls, participants and call history.
- Family inbox assignment, response status and callback cards.

Use existing buttons, dialogs/sheets, toasts, status helpers, date formatting, typography, colour tokens and focus utilities. Dates use the shared `en-NZ`/`Pacific/Auckland` helpers, with explicit date and time available on demand.

The same components accept server-issued capabilities such as `canSend`, `canUpload`, `canCall`, `canInvite`, `canManageMembers` and `canSeeReceipts`. Hiding a control never substitutes for server enforcement.

**Reuse has boundaries:** staff and portal chat use the same core. Control Room, IT and Company Hub can use smaller primitives/adapters while retaining their own record types and permissions. Do not rewrite working ticket/email/announcement workflows merely to make their storage identical. Future native apps share API contracts, policy and design tokens; React web components are not assumed to be portable native screens.

## 5. Conversation types, identity and membership

- **Staff direct:** one stable conversation per eligible pair; duplicate opens do not create duplicate threads.
- **Staff private group:** creator selects eligible current staff, names the group, and sees its members before creating it. Owner/moderator rules cover invites, removal, rename, leaving, ownership transfer and archiving.
- **Managed team/site channel:** named owner, purpose, approved-site scope and governed membership. Managers create/manage these; ordinary staff use only permitted channels. No organisation-wide directory implied by a site-level permission.
- **Client care-team inbox:** a stable client-bound conversation/inbox relationship with an explicit family audience and authorised covering team. New incoming work enters the team queue even if no individual has opened the thread.
- **Named-worker family conversation:** client-bound, addressed to a named current worker, with disclosed authorised cover. It is not represented as secret or accessible only to that worker.
- **Contextual thread:** links to an existing alert/ticket/discussion; it preserves that domain’s audience and source record.

Families never join internal staff groups/channels. Staff cannot add a portal identity through a staff-only participant picker. Family recipients are limited to approved linked people for that client; never show other clients’ families or an unrestricted staff directory.

Confirmed family audience: the sender plus explicitly invited approved family members and eligible staff/cover. Do not automatically include every relative.

Joining/removal must state what history becomes available. Proposed default: newly added private-group members see messages from joining onward; any sharing of earlier history is deliberate. Existing historical family threads must not gain broader cover/family access silently. Preserve their original audience until a disclosed, approved transition is completed.

Revocation takes effect on reads, writes, searches, attachments, subscriptions, notification previews and calls. Site transfer, reassignment, offboarding or a withdrawn portal link must remove access without leaving a still-authorised recipient’s conversation unusable. Reconcile this deliberately with the current fail-closed participant tests; do not simply relax those tests.

## 6. Messaging behaviour

### 6.1 Compose, send and recover

- Support multiline text, emoji, structured reply, permitted mentions, photos, approved documents and actual voice notes. No GIF/sticker catalogue or automatic link scraping in the initial proposal.
- Desktop Enter sends and Shift+Enter inserts a line break, with an optional preference; mobile uses a visible Send button. Respect input-method composition.
- Drafts belong to the signed-in person, conversation, client context and audience. Restore them when returning; never carry one into another client or account. Explain recovery after expiry/logout and purge device data on sign-out or lost access.
- Proposed draft strategy: protected server drafts for recovery, with temporary in-memory browser buffering while offline. Persistent browser storage of care content needs a separate approved device policy; do not broaden the service-worker cache.
- Show **Sending**, **Sent**, **Couldn’t send — Retry** and **Checking whether it sent** distinctly. A timeout can mean an unknown outcome; check by a stable request ID before retrying. Repeated clicks/reconnects must not duplicate a message.
- The outgoing bubble remains until the outcome is known. A failed send does not clear the text or attached files. A draft changed during send must not be overwritten by the earlier response.
- Offline composing is allowed with an explicit unsent state. Proposed default: user reviews and taps Send after reconnect; no silent delayed sending into a changed audience.

### 6.2 Reading and history

- Load recent history quickly and fetch older messages without moving the reader’s position. Show a first-unread divider and a **New messages** jump action when reading older content.
- Mark only actually displayed messages read while the conversation is visible; opening a background tab or receiving a notification is not reading.
- Keep unread badges, list previews, drawer and full-page history synchronised across tabs/devices. Define badges as unread conversations; show message counts within conversations where useful.
- Receipt meanings are factual: **Sent** means accepted by the service; **Delivered** only where a receiving-client acknowledgement exists; **Read** follows the approved receipt policy. Shared-inbox **Seen by care team** does not mean **Someone is responding** or **Resolved**.
- Personal mark-unread is a reminder; it does not erase previously emitted receipts. Do not expose an employee’s precise last-seen time to family by default.

### 6.3 Message actions

- Reply references the original message and resolves the current permitted preview. Deleted/restricted content displays an appropriate placeholder instead of a cached quote.
- Reactions are accessible and reversible. **Save for me** is private; **Pin for everyone** requires the appropriate conversation capability.
- Proposed edit/delete policy: edit own text within a configurable window, show an edited indicator and retain authorised audit history. Removal leaves a neutral placeholder; it does not rewrite clinical records or promise destruction from every recipient’s memory/download.
- No bulk forwarding of family/clinical messages. Any approved sharing action previews recipients and revalidates the record/attachment; prohibit cross-audience sharing when authority is missing.
- Report a concern/misuse to an authorised manager through an appropriate existing workflow. Muting does not disable required operational escalation. A blocked or abusive family contact must still have a managed care contact path.

### 6.4 Files and voice notes

- Choose/capture, preview, optionally caption, remove, upload and retry with clear size/type limits. Private storage, safe serving headers, file validation and malware handling are part of the delivery contract.
- Voice notes have Record, duration, Pause/Resume where supported, preview, discard and Send. Stopping a recording does not automatically send it. Release the microphone on stop/cancel/navigation.
- Playback offers play/pause, duration and speed where supported. Unsupported audio has a clear fallback. Do not display an upload control for a format the server rejects.
- Confirmed policy: an attachment is conversation-visible by default. **Add to client photos/documents** is a separate authorised action with an audience preview. This deliberately changes the current automatic gallery/document side effect; preserve existing records and their current visibility during migration.
- Revoked access, removed attachments and expired links show recoverable states; no raw storage path or public URL is exposed. Photo metadata/location handling must be deliberate.
- Suggested design limits pending approval: 20 MB per attachment, 5 attachments per message, 2-minute voice notes. These are proposed limits, not statements about current support.

## 7. Family-to-support-worker redesign

### 7.1 Family experience

The family dashboard presents the selected client, **Message care team** as the default action, and eligible named contacts with understandable roles. Explain who can see the conversation before first send and in the thread details: for example, “Messages to Alex can also be answered by authorised members of Sam’s care team.”

Display **Available to call**, **On shift — availability unknown**, **Unavailable**, or **Callback requested** only when supported by current evidence. Being rostered or recently online does not prove someone can answer.

A care-team conversation shows who is responding and any configured response expectation. If none is configured, say “Your care team will reply here” without a made-up deadline. Clarify the organisation’s configured urgent-contact route without asking families to rely on chat for emergency response.

Family can send text/media/voice, see conversation members, request a callback and join an approved call. Switching clients clears incompatible picker/search results and visibly changes the audience. Family content is never mixed with staff-only notes.

### 7.2 Worker and manager experience

The Family view gives eligible workers a shared inbox with **Needs reply**, **Assigned to me**, **Waiting on family**, **Callback requested**, and **Resolved** filters. Prioritise oldest unanswered requests using the configured business hours, not merely the most recently active chat.

- A worker can claim a conversation; another sees **Alex is responding**. Two simultaneous claims resolve to one owner with a useful conflict message.
- A response remains attributed to the actual staff member. Cover does not impersonate the named worker.
- Assignment can be transferred to eligible cover, with an internal handoff separate from the family-visible message stream.
- A new family message reopens resolved work. Reading, reacting or starting a call alone does not resolve it.
- Shift ending/off-duty status prompts handoff of owned unresolved work; it must not prevent legitimate clock-out or create unpaid obligations. Unclaimed messages remain in the team queue.
- An unstaffed team routes to the configured lead/cover or shows **Needs routing** to an authorised manager. Never fabricate an available recipient.
- Response due/escalation thresholds and holiday/time-zone rules are configured by site/service. Reuse existing notification/escalation services where their contracts fit.
- Family requests requiring action can become existing family notes/tasks using **Create follow-up**. Preview assignee, due time, permitted content and resulting visibility. Chat reply status and task completion remain separate.

Internal coordination uses an unmistakably separate staff-only panel/thread. Avoid an easy-to-miss “internal/public” toggle in the family composer.

### 7.3 Callback workflow

Callback request → awaiting assignment → assigned → attempted → completed or unable to reach. A missed incoming family call offers this same workflow rather than producing duplicate requests. Family sees the request status and any agreed time; staff see ownership, attempts and the next action. Cancelling, reassigning and duplicate submissions are handled explicitly.

## 8. Voice calling

### 8.1 Current browser scope

- One-to-one staff calls, staff group calls, family-to-staff calls, care-team calls and invited approved family/care-team group calls.
- Suggested initial group design cap: 8 connected people, subject to volume/cost review. Membership limits and microphone activity are visible. Joining checks current access each time.
- Start from a conversation or the Calls view. Confirm the audience before a group/family call; inviting someone to a live call shows the invitee and existing group who is joining. No anonymous room links.
- Care-team incoming calls route to currently eligible available responders according to configured coverage. First successful acceptance owns the call; all other ringing devices stop promptly. Unanswered/busy attempts offer a callback.
- Named-worker calls do not ring off-duty people by default. Offer care-team contact or callback instead. Staff can change availability within policy; roster status alone does not set availability.
- Respect Do Not Disturb and permission settings. An urgent chat label does not bypass phone or operating-system settings.

### 8.2 Required states and controls

Ready → microphone/device check → inviting/ringing → connecting → connected → reconnecting → ended. Also design declined, busy, no answer, cancelled, permission denied, missing microphone, unsupported device, network failure, access revoked and answered elsewhere.

Show caller/group identity, audience, mute state, call duration, participants, microphone/audio-device controls where supported, add eligible participant, leave/end and message-within-conversation. Muting a participant must never allow someone else to unmute their microphone without consent.

For a group, distinguish **Leave call** from an authorised **End for everyone**. Define host succession if the host leaves. Invited participants see who is already in the call before joining. A removed/revoked member loses media access, not just the visible participant row.

Call history records initiator, permitted participants, start/end and outcome. Duration starts when media connects. No audio recording, transcription, AI summary or automatic care note in the initial proposal. Support an explicit **Record communication** draft after a care-related call if the worker has permission.

Browser microphone capture needs a secure context and user permission; permission denial and ignored prompts are normal UX states. Request audio only. See [MDN getUserMedia](https://developer.mozilla.org/en-US/docs/Web/API/MediaDevices/getUserMedia).

### 8.3 Reliability and the later app

Desktop and mobile browsers support the tested active-app calling experience. Closed/suspended browser and locked-phone ringing are not acceptance promises for this release. Web push can notify users, but it is not unrestricted background execution; see [WebKit’s Web Push explanation](https://webkit.org/blog/12945/meet-web-push/).

The future Oblivion Care app must implement and prove native incoming-call, lock-screen, interruption and background lifecycle behaviour. Apple documents PushKit/CallKit for incoming VoIP; Android documents Core-Telecom integration. These are future integration requirements, not authorisation to build an app now. See [Apple incoming VoIP guidance](https://developer.apple.com/documentation/pushkit/responding-to-voip-notifications-from-pushkit) and [Android Core-Telecom](https://developer.android.com/develop/connectivity/telecom/voip-app/telecom).

Preserve stable user/conversation/call IDs and versioned APIs for that app. Test switching Wi-Fi/mobile networks, Bluetooth changes, another incoming phone call, multiple signed-in devices, logout and expired sessions. Provide text/callback fallback when voice is unavailable; do not say “ringing” without evidence that an eligible endpoint has been invited successfully.

## 9. Global Settings → Chat

Use the existing Global Settings shell, permissions, save feedback and audit patterns. Provide these sections within Chat:

1. **General:** enablement/rollout by approved site/role, allowed conversation types, directory scope, group limits and default channel ownership.
2. **Family contact:** eligible named contacts, care-team membership/cover, disclosed audience policy, family invites, callback routing, service hours/holidays and response expectations.
3. **Calls:** staff/family/group enablement, group size, available responders, ringing timeout, missed-call/callback handling, supported browser/device guidance and service health. No video/recording controls for unsupported features.
4. **Messages and files:** approved types/sizes, voice-note limits, edit/removal policy, receipts, permitted reactions, pin permissions and media-publication policy.
5. **Notifications and availability:** event defaults, quiet hours, mentions, family-reply reminders, missed calls, preview privacy and delegation to existing notification/escalation settings.
6. **Privacy and records:** approved retention rules by staff/family/call metadata/media category; audit access, export permissions, preservation/hold handling and offboarding behaviour. No automatic deletion schedule until policy is approved.
7. **Channels and access:** channel owners, allowed membership rules, approved-site scope, role capabilities and explicit effective-access summaries.
8. **Service health:** message/realtime/push/media/call status, failed-delivery trends and restricted diagnostics. Secrets are never displayed; provider credentials use the existing secure integration pattern.

Settings display whether a value is an organisation default, approved site/service override or personal preference. Site overrides cannot widen record access. Personal preferences can change sound, display, enter-to-send, availability and permitted notifications; they cannot override mandatory privacy, membership or retention policy.

For changes, show the effective value, affected audience and any change to active conversations/calls. Validate before saving, detect concurrent edits, show **Saved** only after acknowledgement, and retain an audit of who changed what. Restore previous configuration where safe; retrospective retention deletion is not a routine toggle.

Client portal administration links to the effective Chat policy and manages the client’s approved family/contact relationships. It must not become a competing independent settings system.

## 10. Notifications, privacy and accessibility

- Notifications link to the exact permitted conversation/message/call; after reauthentication return there only if access still exists. Denied/deleted content has a neutral recovery screen.
- Dedupe across email/push/in-app channels. An active conversation should not generate repeated sounds; quiet hours and muted chats behave consistently. Shared-team reminder ownership prevents everyone receiving repeated action alerts.
- Default external/lock-screen previews omit client names and message bodies. Staff/family can choose less restrictive previews only within approved policy. Personal phone numbers are never needed for in-app calling.
- Current membership and site/client authority are rechecked server-side for events, media and call tokens. Realtime topics are private and scoped to permitted users/conversations, never tenants.
- No blanket administrator or manager access to private staff conversations. Investigative content access is limited to specifically authorised investigators, with a recorded reason and access log, as confirmed by the user. Define any additional approval mechanics during policy configuration. Since 22 October 2026 one case may cover several conversations, and an active case can be exported as a watermarked, logged PDF (see the 22 October decisions in §1).
- Keyboard navigation, visible focus, readable contrast in light/dark themes, large text, reduced motion, screen-reader message/status announcements and non-colour status labels apply everywhere.
- Audio is optional: all essential contact/callback functions remain possible through text. Do not claim live captions/transcription in the initial voice-only release; hearing/accessibility needs are an open decision.
- The interface shows actionable loading, empty, offline, stale, read-only, access-revoked and error states. It never replaces a load failure with “No messages”.

## 11. Service design and migration constraints

This describes implementation requirements for later, not a final vendor or schema selection.

- Extend the existing `OpsConversation`, participant and message foundations after auditing compatibility. Add explicit conversation category/audience/ownership, structured replies, personal saved items, attachment records, read cursors and family-inbox/callback state as needed.
- Centralise authorised projections and commands so staff, portal and drawers receive the same permitted message contract. Keep module-specific storage behind adapters where merging would change semantics.
- Use authenticated realtime events with ordered/cursor-based catch-up, bounded reconnect/backoff, pagination and a visible degraded mode. Server-accepted writes and notification dispatch must be durable and deduplicated; realtime failure must not lose the committed message.
- Use stable mutation IDs and safe recovery for create/send/edit/upload/claim/callback/call operations. Reject stale responses after conversation, client, user or authority changes.
- Voice requires signalling, authenticated short-lived media access, relay/network traversal and group-call media infrastructure. Existing CCTV or remote-control WebRTC is not a reusable human-call product by assumption. Evaluate provider/hosting, data handling, operating cost and mobile compatibility before committing to an implementation choice.
- Centralise call lifecycle outside page components; avoid duplicate ringing across tabs/devices and orphaned calls after crashes. Access revocation and hangup release media and credentials promptly.
- Keep message content out of routine telemetry. Measure timings/outcomes using identifiers and safe aggregates; provide operator diagnostics without exposing care content.
- Inventory existing conversations and preserve IDs, sender attribution, attachments, deleted markers, timestamps, reactions, pins and read positions. Do not infer lost data, fabricate receipts or merge different family audiences.
- Stage migrations behind feature controls with reversible routing, reconciliation counts and a rollback plan. Avoid uncontrolled dual writes. Keep old URLs, notifications and bookmarks resolvable.
- Existing uncommitted repository work is outside this specification’s implementation ownership. Coordinate changed files at implementation time.

## 12. Proposed quality and acceptance gates

These are targets to validate on an agreed workload, not achieved results. Initial targets: visible accepted messages within 2 seconds at p95 for connected clients; open recent history within 2 seconds at p95 on the agreed test network; incoming invitation displayed within 3 seconds at p95 for eligible active browser endpoints; media connected within 5 seconds at p95 after acceptance where permissions are already granted. Agree staff/family counts, concurrent calls, network conditions and test devices before adopting these as release thresholds.

- **A01 Navigation:** Chat appears consistently for eligible staff/family; no unauthorised directory or second inbox; old links resolve correctly.
- **A02 Shared surfaces:** the same message, media, reply, deletion and receipt renders consistently in staff workspace, portal and drawer, subject to explicit capabilities.
- **A03 Delivery:** rapid repeated send, timeout after commit, reconnect and multi-tab send do not duplicate messages; unsent work is recoverable.
- **A04 History:** pagination/search/jump-to-message preserve scroll and never omit older authorised history silently.
- **A05 Read state:** one person reading does not mark it read for everyone; hidden tabs and notification delivery do not mark messages read.
- **A06 Media:** photo/document/voice can be previewed, uploaded, played/opened and retried across supported browsers; denied formats and revoked access behave safely.
- **A07 Audience:** staff-only content never appears in family history, search, notifications, attachments or call metadata. Linked-client and approved-site boundaries withstand forged IDs.
- **A08 Membership:** offboarding/reassignment/revocation removes access promptly; group history rules and cover transitions are explicit and tested.
- **A09 Family work:** claim conflicts, cover response attribution, shift handoff, new-message reopen, unstaffed inbox and configured response clocks work end to end.
- **A10 Callback:** missed calls and explicit requests dedupe; assignment, cancellation, repeated attempt and completion are visible to the right audience.
- **A11 Direct calls:** answer/decline/busy/no-answer/cancel/reconnect work; microphone failure does not trap the user or claim a connected call.
- **A12 Group/family calls:** invitees are eligible; participant identity is clear; removal, leave/end, host succession and multi-device answer races work.
- **A13 Calling scope:** browser foreground support is accurately described. Native locked-phone requirements are tracked for the future app, never marked passed by a browser mockup.
- **A14 Settings:** authorised changes persist with audit/conflict feedback; defaults/overrides/preferences are understandable; settings cannot bypass access checks.
- **A15 Notifications:** exact deep links, safe previews, dedupe, quiet hours, read synchronisation and expired-session handling work across available transports.
- **A16 Context:** medication/forms/handover drafts survive opening/closing chat; formal record and broadcast acknowledgement rules remain intact.
- **A17 Accessibility:** mobile keyboard, 320–430px layouts, tablet/desktop, keyboard-only, screen reader, 200% zoom, dark/light and reduced motion pass review.
- **A18 Migration:** preserved-history counts and audience checks reconcile; old URLs and rollback are demonstrated; no unapproved historical access expansion.
- **A19 Operations:** outage/degraded status, retries, retention rules, permissioned audit and content-safe diagnostics work at agreed scale.
- **A20 Coverage:** every S01–S24 inventory item has an approved treatment and evidence, or an explicit accepted no-change decision. New discoveries update the inventory before sign-off.

Retain the security tests listed in the inventory. Add meaningful browser tests for cross-surface flows and concurrent sessions, plus real-device audio checks; component screenshots alone do not prove calling or delivery.

## 13. Approval and delivery sequence

**Gate 1 — This spec:** review confirmed decisions, proposed defaults and open questions. Amend the document and record approval. Resolve decisions that affect audience or screen structure before their mockups are finalised.

**Gate 2 — Mockups only:** prepare the complete [mockup plan](2026-10-15-mockup-plan.md), covering desktop/mobile browser, family/worker/manager, settings and error/permission states. Use synthetic people and content. Include interaction demonstrations and a review ledger. No real messages/calls or app changes are made by the mockups. The future native app receives a requirements handoff, not a new app design/build in this scope.

**Gate 3 — Implementation approval:** only after the whole mockup set is approved, turn the agreed design into small dependency-ordered work packages: access/contracts and migration → shared UI/realtime → staff navigation/conversations → family inbox/media/cover → calling → settings/notifications → remaining adapters → migration/device/role verification. Prepare settings policy before enabling the features it governs. Calling infrastructure feasibility/cost must be resolved before calling implementation is committed.

**Release gate:** the acceptance checks pass on agreed environments and each inventory item is signed off. A phased delivery can be proposed, but the agreed full scope remains tracked; deferred group/family calling must not disappear behind a “chat complete” label.

## 14. Remaining decisions

All discovery answers received so far are recorded in section 1. These additional decisions are intentionally visible rather than silently assumed:

1. **Retention/edit policy:** approved retention periods for staff chats, family chats, media and call metadata; editing/removal windows; preservation exceptions. Recommendation: use the organisation’s approved records policy; do not invent periods or enable destructive cleanup.
2. **Volume/cost:** approximate staff/family user counts, simultaneous calls, largest expected group, hosting/data-region constraints and operating budget. Recommendation: start design at eight call participants and validate before selecting infrastructure.
3. **Media limits:** confirm proposed 20 MB files, five attachments and two-minute voice notes, or give alternatives.
4. **Receipt preferences:** should family see individual read receipts, or a simpler care-team received/seen/responding status? Recommendation: team status for shared inboxes; direct receipts configurable within policy.
5. **Accessibility/languages:** known hearing, vision, literacy or language needs to include in the mockups; whether live captioning is essential before voice calling can launch.
6. **Operational owner:** which role owns channel management, unclaimed family messages, callbacks and service-health alerts? Recommendation: named site/service lead plus authorised cover, configured before rollout.

These can remain visibly pending during early mockups but must be settled before the affected implementation/release commitments. Receipt and accessibility choices should be resolved before final mockup approval. No response is treated as approval.
