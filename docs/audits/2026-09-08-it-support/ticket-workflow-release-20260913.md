# Ticket workflow release — 13 September 2026

## Delivered behavior

- Public replies and internal notes open in an Add note modal. Each audience retains its own draft, attachments and work fields through closing and reopening. Submission recovery preserves newer work and dismisses only the matching saved note.
- Technicians record start/end times, breaks, travel/work type and explicit after-hours work with their note. A recoverable timer and reviewed business-hours split support actual time entry. Notes, time, status and follow-up changes save atomically.
- Ticket-scoped search exposes eligible users and technicians and appropriate work contact details. Additional technicians can be booked with availability checks, acceptance, cancellation, rescheduling and calendar visibility.
- Contact preferences, diagnostics, follow-ups, parts/expenses, independent reviews, settlement gates and reasoned corrections provide durable work history.
- Existing priority values display as P1 Critical, P2 High, P3 Medium and P4 Low. Existing assessment rules and SLA clocks remain authoritative.

## Boundaries and integration

The application remains single-tenant. Existing ticket permissions, approved sites, current staff eligibility and privacy rules govern access. Requester responses exclude internal work context. Existing canonical comment, draft, version, transition and notification services remain the writers.

The release contains only the ticket workflow and its direct integration changes. Concurrent Knowledge, Provisioning, Governance and other working changes were excluded through reviewed file/hunk selection. Temporary browser fixtures, local runtime identities, generated assets and integration harnesses are not included.

Database change: `2026_09_13_120000_create_it_ticket_work_records.php` adds work profiles, bookings, time entries, costs and correction history. Apply it through the normal deployment migration process. The migration was already applied to the local development application without changing existing ticket records; publishing source does not deploy or migrate a remote environment.

## Verification

- The completed implementation passed 18 work feature cases / 87 assertions, an earlier combined work/comment command group of 39 cases / 283 assertions, and four additional time unit cases / eight assertions.
- Real Laravel browser checks covered note/time persistence, separate audience drafts, mobile modal layout, technician bookings, searchable users, contacts and saved costs. Final read-only verification in the normal Herd application confirmed the compiled note modal, contact details and priority identifiers with no console errors.
- Browser-discovered fixes refresh terminal draft capabilities before starting another form and normalize frozen command payloads consistently with Laravel middleware.
- Release review aligned work draft recovery with the server's 60,000-character field limit while retaining the aggregate byte limit and ordinary note boundaries. Five regression cases cover these limits.
- The isolated release checkout passed 104 component, draft, ticket-page and conversation-list tests, TypeScript checking and scoped lint. Existing page privacy/session-recovery tests now enter and reopen notes through the modal; closing a concealed modal cannot take focus away from the session-access message.

This record describes source integration and local verification. It does not claim a remote deployment, production migration or billing integration.
