# Chat design review — revision 2

**The complete revised mockup set is ready for UI/UX review. All 51 approved refinements are represented. Application implementation has not started.**

Open the [interactive review](http://127.0.0.1:8879/review.html#M01/default) or [screenshot gallery](http://127.0.0.1:8879/gallery.html). The portable [review.html](review.html) also opens directly in a browser without a network connection.

The set contains **25 screen groups, 191 scenarios and 50 desktop/phone overview screenshots**. It covers staff Chat, groups, channels, family portal and worker inbox, embedded conversations, voice calls, global Chat settings, personal preferences and recovery/accessibility states. Phone layouts are responsive web; native apps come later.

Use **Guided review journey** for Staff, Family, Calls, Settings or Context. The screen/scenario selectors open individual states; width/theme controls show desktop, tablet, 390px and 320px layouts. **Review notes** stores feedback per scenario in this browser and can export it. Design approval does not authorise implementation.

## Key refinements

- Shared conversations, composers, message actions and media across full Chat, drawers and calls, preserving the selected person and draft.
- Main navigation named **Chat**, with family inbox and Calls shortcuts. My Day, Meds, Clock and Report remain available.
- Family conversations separated by client and relative, disclosed named-worker cover and explicit approved invitations. Staff coordination has its own composer/history.
- Family ownership, due/overdue states, shift handoff, follow-ups and callbacks with preferred/agreed times, attempts and duplicate handling.
- Global incoming-call card at the top right on desktop and below the header on smaller screens. Space is reserved for it; compact controls persist while working elsewhere.
- **Small, two-step call-note dialog:** reviewed staff summary to **Communication Record**, then an optional separately worded **Family Note** with audience acknowledgement. Eligible relatives outside the call may see that shared note. No automatic transcript, recording or care note.
- Global Settings → Chat with defaults, site/service overrides, effective values, change review, validation, failed saves and concurrent changes. Investigation access is separate from settings administration.
- Ticket public replies/internal notes retain separate drafts and audiences. Source permissions and required acknowledgements remain separate from Chat.

## Verification

The refinements were followed by **two additional review-and-fix passes**. Evidence includes **191 final scenario renders**, **100 responsive checks** covering all 25 groups at four widths, and **29 passing interaction checks**. No blank screens, outer horizontal overflow or captured browser errors were found in these checks.

Focused checks covered draft isolation, correct call recipients, shared call messages, returning to unfinished work, family queue navigation, callback reuse, policy scope isolation/conflicts, keyboard focus trapping, Escape/focus restoration, large-text/dark reflow, compact phone dialogs, separate Family Note wording and ticket public/internal draft isolation.

See [revision-2-checks.json](revision-2-checks.json), [the 51-item ledger](approved-revisions.json), [coverage.json](coverage.json) and [review-passes.md](review-passes.md). Older browser/call check files are historical baseline evidence only.

## Boundaries and policy decisions

These are mockups, not production acceptance. All content is sample data. No real messages, uploads, invitations, emails, calls, records, settings or investigation access are created. Audio and microphone levels are visual simulations. Only review feedback persists across reloads.

One organisation operates across approved sites. Access follows roles, sites, client relationships, source records and conversation membership; no tenant workflow is introduced.

Proposed values remain labelled: 20 MB files, five attachments, two-minute voice notes, eight call participants and example response hours. Retention, edit/removal windows, operating ownership, scale/cost and calling infrastructure need decisions before implementation. Unconfigured services promise no response time.

Real delivery, realtime behaviour, backend permissions, storage/migration, full browser zoom and assistive-technology acceptance, device audio, and locked-phone/background calling are future implementation work. The native app is planned later.

## Maintaining this isolated review

From the repository root, `node docs/chat-experience/mockups/build.mjs` rebuilds the bundle and portable HTML; `node docs/chat-experience/mockups/serve.mjs` serves this folder on port 8879. `finalise-review-pack.mjs` regenerates the gallery and ledger from the registry and saved evidence.

Revision-2 views use `src/v2-*.jsx`, `src/v2-model.js`, `src/incoming-calls.jsx` and `v2.css`. Nothing imports them into the product. The content policy disables network connections, submissions and media capture; the local server accepts only reads.

References: [approved specification](../2026-10-15-specification.md), [surface inventory](../2026-10-15-surface-inventory.md), [mockup plan](../2026-10-15-mockup-plan.md).
