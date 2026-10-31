# Notes: notification row, side sheet and pop-up kit boards

Three new Chat v3 boards, drawn 31 October 2026 for Stephan to approve before anything is built.

- `ChatV3-Notifications.html` (C7.7, with the C1.4 grouping rules)
- `ChatV3-SideSheet.html` (C7.3)
- `ChatV3-Popups.html` (C1.0e)

They use the same 1600 px board frame, Instrument Sans, the three approved stylesheets (`oc.css`, `oc-chat.css`, `oc-chat3.css`), the `brand/` logos and the same `#d` dark-mode script as the six approved boards. Double-click to open; add `#d` to the address for dark mode.

The top bar and side nav are copied from the approved Workspace board. The signed-in person is Rohan Patel (Site lead, Kōwhai House), on Monday 19 October 2026, the same day as the approved boards. People: Rohan Patel, Ana Rēweti, Tane Wineera, Kiri Wineera, Aroha Ngata, Demo Admin. Places: Kōwhai House, Tōtara service.

Each board adds a small `<style>` block for parts the stylesheets don't have (the notification row, the push card, the sheet head, the picker parts, the stacking diagram). It uses only the stylesheets' variables. A build check refuses any hex value in it. Avatars keep the approved boards' four inline avatar colours, as the approved boards do.

The scripts that drew them are in `_src-row-sheet-popups/`. Run `node b-notifications.mjs`, `node b-sidesheet.mjs` or `node b-popups.mjs` from that folder to rebuild one board.

---

## 1. ChatV3-Notifications.html

### Scenes
1. **In the bell.** The whole page at 1440 × 1000 (My day), with the bell open. Rows: urgent "read and confirm", a mention, a family conversation (callback request + 2 messages, reply due), a missed call, "3 new messages" in a channel, an inspection from another module on the same row, the off-shift summary, and a read row.
2. **Why it works this way.** Three short cards: the app's row with Chat's words, one row per conversation, and what stays visible until it's dealt with.
3. **On the Notifications page.** The whole page at 1440 × 1000: Event Horizon header, Notifications and Announcements tabs, filter chips with **Chat** selected, wide rows with their main action ("Read and confirm", "Call Kiri", "Reply", "Call back"), and the right-click menu with the Mute choices open.
4. **Row parts.** Every kind of Chat row; unread vs read; previews off; a muted conversation (only the mention comes through); a conversation you can no longer open; the hover bar and the right-click menu.
5. **How rows group.** Today (one "New Chat message" per message) next to the proposal (one row that keeps count), and the off-shift flow (one row at clock-in).
6. **Browser notifications.** Staff events say who wrote, never what. Family events have no names and no words. Also: the locked-screen view with details hidden, sign-out removing notifications, and "notifications blocked".

### Decisions to check
- Chat uses the app's notification row: photo or icon, a small mark for the kind (@, missed call, urgent), name, what happened, the newest line, time, unread dot. There is no separate Chat card any more (today's `ChatNoticeCard`).
- One row per conversation. A mention stays named in its row ("Ana Rēweti mentioned you · and 2 more messages").
- Urgent rows have no "Mark as read". They leave the bell only when you confirm in the conversation.
- A family row keeps its "Reply due by 3:00 pm" tag until someone in the care team replies. When the time passes it turns red ("Reply overdue").
- The callback request and new family messages from the same conversation share one row.
- Hover shows Open, Mark as read and Mute. Right-click, ⋯ and Shift + F10 open the same list, which adds "Open beside this page" (the side sheet). No pop-ups.
- Message previews show in the app unless the person turns them off in My Chat preferences (the server default is on). With previews off, the row shows who wrote and how many messages, but not the words.
- Bell footer wording is "See all notifications" (today: "View All Notifications", title case).
- Push uses the brand mark on a primary-colour circle (today: `favicon.ico`).

### Open questions for Stephan
1. **Names in browser notifications.** C7.7 says push shows who wrote. Today's build shows no names at all ("generic wording … without names", `docs/chat-experience/browser-push.md`). Which is right for staff chats? The boards follow C7.7. Family names and the person supported are never shown either way.
2. **"Hide details in browser notifications".** This is a new switch in My Chat preferences for locked or shared computers. Should it exist, and should it be on or off by default?
3. **What "Mark as read" does.** The drawing says it clears the row and the Chat count. Should it also send "Read" rings to the sender, or only when the conversation is actually opened? The proposal is only when it's opened.
4. **Off shift.** Should urgent messages for your Site still reach you while you're off shift? The drawing says yes; C1.4 doesn't say.
5. **Mute.** Should mentions and urgent messages still come through when a conversation is muted? The drawing says yes.
6. **Brand icon.** The stylesheets only have `favicon-white.svg`, so the push icon is that mark on a primary circle. A real browser notification needs a full-colour PNG icon and a small badge image. Those assets don't exist yet.
7. **Bell filter.** The bell has "All · Chat" chips as well as the page. Keep both, or only the page (C7.7 only asks for the page)?

---

## 2. ChatV3-SideSheet.html

### Scenes
1. **A conversation beside your work.** The whole page at 1440 × 1000: Tane Wineera's client profile with a chat with Ana Rēweti open in a 640 px sheet. The sheet has a sky header (photo with ring, name, status, call, video, "Open in Chat", ⋯, ✕), Chat · Files · Pinned tabs and a "From Tane Wineera's profile" chip. Below that: grouped v3 bubbles, an incident card ("Opens under this chat"), a care-plan card ("Show on this page"), a reaction, an unread line and the one-line composer.
2. **Why it works this way.** Measured in the browser, the messages get 762 of the sheet's 944 px (81%). One width; the page stays usable.
3. **Where it opens from.** A person's profile (family chat with its count, message the key worker), a shift, an incident, and the header Chat button (a "Recent chats" list). A note covers eMAR and Control Room.
4. **States in the sheet.** All drawn at the real 640 px:
   - a family conversation, with the slim family bar and one notice slot (callback request);
   - a first message from a profile;
   - a group closed by its owner (read only);
   - no access (no name shown, plus the "removed" wording);
   - loading;
   - couldn't load.
5. **Width and stacking.** A half-size diagram of the 1440 screen (nav 236, page 564, sheet 640, call dock, incoming call), what sits on top of what, and the rules.

### Decisions to check
- One conversation only, never the two-pane workspace. Width is the kit's 640 side panel, not today's 720. The sheet starts under the top bar so the Chat count and bell stay visible.
- The sheet head is the kit's sky side-panel head. It holds the conversation header parts the boards require (ring photo, name, one status line, tabs, call, video).
- No dark overlay. The page underneath still scrolls and works.
- Nothing from the page is shared on its own. Cards are only sent if you send them. Today's copy says the same: "No clinical details are shared automatically".
- A care card for the record you're on says "Show on this page". Other cards open their page under the sheet, and the sheet stays open.
- Closing never asks "Discard this draft?", because the unsent message stays with the conversation.
- The family sheet keeps the Workspace board's slim bar. Its action buttons move into ⋯ to fit 640 px. Callbacks use the one notice slot.
- The no-access state never shows the conversation's name.

### Open questions for Stephan
1. **Header Chat button.** C7.1 says it opens the Chat page. The brief and this board have it open a "Recent chats" list, where choosing one opens the sheet. Which do you want?
2. **Moving to another page.** Should the sheet stay open when the page changes? The drawing keeps it open when you follow a care card and closes it when you use the main nav.
3. **A record's side panel.** When a record's own side panel (for example, editing a care plan) is open and you open Chat, the drawing puts Chat over it, and closing Chat shows the panel again. Is that right, or should one close the other?
4. **640 or 480.** Is 640 the right width at 1440, or should Chat use 480 so more of the page shows?
5. **Back arrow.** Opening a second conversation replaces the first, and a back arrow returns to it. Keep the back arrow, or only ever one conversation?
6. **Today's sheet wording.** The current sheet says "Family Chat · Choose a conversation to see its audience" ("audience" is banned jargon). Replace it with the bar's "Family + care team can read this".

---

## 3. ChatV3-Popups.html

### Scenes
1. **The kit Chat uses.** Form 480, Confirm 480, Side panel 480, and menus and toasts, with the rules for each.
2. **Starting a conversation.**
   - New conversation: one form with One person · Group · Site channel, the people picker with chips, and a group name.
   - People picker states: loading, no match, can't add (the other members can't see that Site), couldn't load.
   - Add people, with "What can they see?" (new messages only, the last 7 days, everything).
3. **Leaving, removing and deleting.**
   - Leave group (plain), and the only-owner version ("Make Ana owner and leave").
   - Remove a person (plain), and "You can't remove people any more" (role changed).
   - Delete for everyone (red), with Undo toasts for both deletes.
4. **Deleting · every state.** Deleting, failed (nothing changed), result not confirmed (check before trying again), too late for everyone, and on hold for an investigation.
5. **Sharing and turning into work.** Share (family chats can't receive staff messages) and a part-failed share. Add a task with validation errors, and with access lost mid-way (typing kept). Report a concern, as Ana Rēweti, about a family photo that shows Aroha.
6. **Looking things up and recording.** Message info while loading, and in a family conversation (side panel). Add a call record, and a failed save with the text kept. Mute as a menu plus an Undo toast.
7. **Safety and privacy.** Pause messages from a family contact (site leads, warning tone, reason required). Stop asking for confirmations (destructive, reason required, error shown). Send past the privacy check (destructive, reason required).
8. **States every pop-up has**, shown on Add people: loading, couldn't load, nothing to add, no access, check your entry, saving (✕ and Cancel wait), failed with choices kept, result not confirmed, and "Discard this draft?".
9. **What each pop-up replaces.** A table of today's chat dialogs, whether `chat-thread.tsx` opens them, what replaces them, and which board draws it.

### Decisions to check
- Every pop-up follows `components/popup/popup.tsx`.
  - Forms have a lens, title, one line, ✕, a plain context line and a grey footer, with Cancel first and the main button named for what it does.
  - Confirms have the lens on top and a question, with no ✕.
  - Widths are 480 only.
- **Red only when something is deleted or a safety check is loosened.** That covers Delete for everyone, "Discard this draft?", Stop asking for confirmations and Send anyway. Leave group and Remove a person stay plain, because history is kept. Pause messages is the warning tone.
- **No pop-up for message actions** (`chat-thread.tsx` "Message actions" goes).
- **Delete for me and Mute have no pop-up**: a menu plus an Undo toast.
- **Read-only things stay side panels.** Message info is one.
- **Lost responses say "Result not confirmed"**, with a way to check and never an invitation to send twice. Failures keep what was typed or chosen.
- **People pickers only list people you can see.** Someone who'd break the "everyone can see everyone" rule shows the reason, not a silent gap. The empty state says to ask a site lead.
- **The call record replaces the two-step "Communication Record and Family Note"** with one form and an optional, separate note for Kiri.
- **Wording.** "eligible", "audience" and title case are gone (today: "Invite eligible colleagues", "Review the updated audience").

### Open questions for Stephan
1. **"Delete" or "Remove".** The kit says "Delete" means gone for good, but Chat keeps a copy in the encrypted history (D9). The board keeps the approved Messages board's "Delete for everyone". Is that right, or should it say "Remove for everyone"?
2. **Undo after deleting.** Undo for "Delete for everyone" means holding the delete for a few seconds. Is that OK, and for how long (10 seconds)?
3. **Pausing a family contact.** Who can do it (site leads only?), what Kiri sees, and does it need a second person? This is new policy, not in the plan.
4. **Report a concern.** Who receives it: the site lead and "the safeguarding lead"? Is there such a role? What are the four reasons?
5. **History for new members.** Are the three choices (new messages only, the last 7 days, everything) right? Today's code talks about "reviewed history access".
6. **Not drawn yet.** These are opened from `chat-thread.tsx` but not drawn here:
   - Take ownership, Hand over family work, Staff-only coordination (`chat-family-work.tsx`);
   - the "Saved elsewhere" draft comparison (`chat-composer-recovery.tsx`).

   Should they get their own board, or follow these patterns (Form 480)?
7. **Blocking between staff.** The board says staff can't block each other; they mute and report. Agree?

---

## How the boards were checked
- I opened each board in the built-in browser through a small local server that served only this folder, at 1640 px wide and at 1:1. I checked every section in light, and the main scenes in dark (`#d`).
- A script checked every column, footer, button, badge, menu, sheet row and frame for overflow or clipping, and found none on any board.
- Fixes made during the check:
  - the bell menu was clipped (the scene is now 1000 px tall);
  - the Notifications page list was clipped (one row removed);
  - three icons were wrong;
  - spacing in the "How rows group" cards;
  - the "Recent chats" footer overlapped;
  - the half-size stacking diagram was too narrow;
  - a day pill was clipped at the top of the sheet.
- The server is stopped. The repo was not touched.
